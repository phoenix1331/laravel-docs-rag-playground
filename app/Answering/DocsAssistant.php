<?php

declare(strict_types=1);

namespace App\Answering;

use App\Ai\Agents\DocsAgent;
use App\Retrieval\RetrievalResult;
use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;
use Closure;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

/**
 * Answers a question from the docs: retrieve sections, refuse if none are relevant, otherwise ask the model to answer only from them.
 */
class DocsAssistant
{
    public const string NOT_COVERED_MESSAGE = "This doesn't appear to be covered in the Laravel %s documentation.";

    /**
     * Seconds to wait before retrying a busy provider.
     *
     * @var list<int>
     */
    protected array $retryDelays = [2, 5];

    /**
     * @param  array{provider: string, model: ?string, max_tokens: int, fallback_provider?: ?string, fallback_model?: ?string}  $chat
     */
    public function __construct(
        protected Retriever $retriever,
        protected array $chat,
        protected float $minSimilarity,
        protected string $defaultVersion,
        protected ?QuestionLog $log = null,
    ) {}

    /**
     * @param  (Closure(string): void)|null  $onDelta  Receives answer text as it streams; when null the answer is generated in one call.
     */
    public function answer(string $question, ?string $version = null, ?Closure $onDelta = null): Answer
    {
        $version ??= $this->defaultVersion;
        $started = hrtime(true);
        $retrieval = null;

        try {
            $retrieval = $this->retriever->retrieve($question, $version);
            $answer = $this->respond($question, $retrieval, $onDelta);
        } catch (Throwable $exception) {
            $this->log?->failed($question, $version, $retrieval, $exception, $this->elapsedMs($started));

            throw $exception;
        }

        $this->log?->answered($answer, $this->elapsedMs($started));

        return $answer;
    }

    protected function respond(string $question, RetrievalResult $retrieval, ?Closure $onDelta): Answer
    {
        if (! $this->isCovered($retrieval)) {
            return $this->notCovered($question, $retrieval, $onDelta);
        }

        if ($this->chat['provider'] === 'fake') {
            return $this->offline($question, $retrieval, $onDelta);
        }

        return $this->generate($question, $retrieval, $onDelta);
    }

    protected function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /**
     * Skip the model entirely when no retrieved section is similar enough to plausibly answer the question.
     */
    public function isCovered(RetrievalResult $retrieval): bool
    {
        $best = $retrieval->bestSimilarity();

        return $best !== null && $best >= $this->minSimilarity;
    }

    protected function generate(string $question, RetrievalResult $retrieval, ?Closure $onDelta): Answer
    {
        $agent = new DocsAgent($retrieval->version, $this->chat['max_tokens']);
        $prompt = DocsPrompt::userMessage($question, $retrieval->chunks);

        [$text, $usage, $provider, $model] = $this->withRetries(fn () => $onDelta === null
            ? $this->prompt($agent, $prompt)
            : $this->stream($agent, $prompt, $onDelta));

        if (DocsPrompt::isNotCovered($text)) {
            return $this->notCovered($question, $retrieval, $onDelta, $provider, $model, $usage);
        }

        return new Answer(
            question: $question,
            status: Answer::ANSWERED,
            text: $text,
            retrieval: $retrieval,
            citedNumbers: DocsPrompt::citedNumbers($text, count($retrieval->chunks)),
            provider: $provider,
            model: $model,
            inputTokens: $usage?->inputTokens ?? 0,
            outputTokens: $usage?->outputTokens ?? 0,
        );
    }

    /**
     * @return array{string, ?Usage, ?string, ?string}
     */
    protected function prompt(DocsAgent $agent, string $prompt): array
    {
        $response = $agent->prompt($prompt, provider: $this->providers());

        return [trim($response->text), $response->usage, $response->meta->provider, $response->meta->model];
    }

    /**
     * Stream deltas to the caller, holding back output that might be the start of the NOT_COVERED reply.
     *
     * @param  Closure(string): void  $onDelta
     * @return array{string, ?Usage, ?string, ?string}
     */
    protected function stream(DocsAgent $agent, string $prompt, Closure $onDelta): array
    {
        $response = $agent->stream($prompt, provider: $this->providers());
        $text = '';
        $flushed = 0;
        $meta = null;

        $response->then(function ($streamed) use (&$meta) {
            $meta = $streamed->meta;
        });

        foreach ($response as $event) {
            if (! $event instanceof TextDelta) {
                continue;
            }

            $text .= $event->delta;

            if ($this->mightBeNotCovered($text)) {
                continue;
            }

            $onDelta(substr($text, $flushed));
            $flushed = strlen($text);
        }

        if (! DocsPrompt::isNotCovered($text) && $flushed < strlen($text)) {
            $onDelta(substr($text, $flushed));
        }

        return [trim($text), $response->usage, $meta?->provider, $meta?->model];
    }

    protected function mightBeNotCovered(string $text): bool
    {
        $trimmed = ltrim($text, " \n\r\t`*");

        return $trimmed === '' || str_starts_with(DocsPrompt::NOT_COVERED, rtrim($trimmed, " \n\r\t.`*"));
    }

    /**
     * The configured provider, with an optional fallback the SDK fails over to when the first is unavailable.
     *
     * @return array<string, ?string>
     */
    protected function providers(): array
    {
        $providers = [$this->chat['provider'] => $this->chat['model']];

        if (filled($this->chat['fallback_provider'] ?? null)) {
            $providers[$this->chat['fallback_provider']] = $this->chat['fallback_model'] ?? null;
        }

        return $providers;
    }

    /**
     * Retry briefly when every provider is rate limited, overloaded or unreachable; free tiers often are.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withRetries(Closure $callback): mixed
    {
        foreach ($this->retryDelays as $delay) {
            try {
                return $callback();
            } catch (RateLimitedException|ProviderOverloadedException|ProviderConnectionException) {
                Sleep::for($delay)->seconds();
            }
        }

        return $callback();
    }

    protected function notCovered(
        string $question,
        RetrievalResult $retrieval,
        ?Closure $onDelta = null,
        ?string $provider = null,
        ?string $model = null,
        ?Usage $usage = null,
    ): Answer {
        $text = sprintf(self::NOT_COVERED_MESSAGE, $retrieval->version);

        if ($onDelta !== null) {
            $onDelta($text);
        }

        return new Answer(
            question: $question,
            status: Answer::NOT_COVERED,
            text: $text,
            retrieval: $retrieval,
            provider: $provider,
            model: $model,
            inputTokens: $usage?->inputTokens ?? 0,
            outputTokens: $usage?->outputTokens ?? 0,
        );
    }

    /**
     * Offline mode: list the most relevant sections instead of calling a model, so the app works without a key.
     */
    protected function offline(string $question, RetrievalResult $retrieval, ?Closure $onDelta): Answer
    {
        $top = array_slice($retrieval->chunks, 0, 3);

        $lines = array_map(fn (RetrievedChunk $hit) => sprintf(
            '- **%s** [%d]: %s',
            $hit->chunk->heading_path,
            $hit->rank,
            Str::limit(trim(preg_replace('/\s+/', ' ', preg_replace('/^#+ .*$/m', '', $hit->chunk->content))), 200),
        ), $top);

        $text = "Offline mode (RAG_CHAT_PROVIDER=fake): no model was called. These are the most relevant sections:\n\n".implode("\n", $lines);

        if ($onDelta !== null) {
            $onDelta($text);
        }

        return new Answer(
            question: $question,
            status: Answer::OFFLINE,
            text: $text,
            retrieval: $retrieval,
            citedNumbers: array_map(fn (RetrievedChunk $hit) => $hit->rank, $top),
            provider: 'fake',
        );
    }

    /**
     * Exposed for tests that should not wait between retries.
     *
     * @param  list<int>  $delays
     */
    public function retryDelays(array $delays): static
    {
        $this->retryDelays = $delays;

        return $this;
    }
}
