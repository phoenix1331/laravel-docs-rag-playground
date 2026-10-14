<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Answering\Answer;
use App\Answering\AnswerHtml;
use App\Answering\DocsAssistant;
use App\Models\DocChunk;
use App\Retrieval\RetrievedChunk;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Ask the Laravel docs')]
class AskDocs extends Component
{
    #[Url(as: 'v')]
    public string $version = '';

    public string $question = '';

    /**
     * The question the current answer belongs to.
     */
    public string $asked = '';

    public ?string $status = null;

    public string $answerHtml = '';

    /**
     * @var list<array{number: int, heading_path: string, url: string}>
     */
    public array $sources = [];

    /**
     * @var array<string, mixed>
     */
    public array $meta = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->version = in_array($this->version, config('rag.docs.versions'), true)
            ? $this->version
            : config('rag.docs.default_version');
    }

    /**
     * Each configured docs version and how many chunks are stored for it.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function versions(): array
    {
        $counts = DocChunk::query()->selectRaw('version, count(*) as chunks')->groupBy('version')->pluck('chunks', 'version');

        return collect(config('rag.docs.versions'))->mapWithKeys(fn (string $version) => [$version => (int) ($counts[$version] ?? 0)])->all();
    }

    public function ask(DocsAssistant $assistant): void
    {
        $this->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'version' => ['required', Rule::in(config('rag.docs.versions'))],
        ], [
            'question.required' => 'Type a question about the Laravel docs.',
            'question.min' => 'Ask a slightly longer question.',
        ]);

        // Free-tier models can take longer than PHP's default 30 seconds.
        set_time_limit(120);

        $this->resetAnswer();
        $this->asked = trim($this->question);

        try {
            $this->show($assistant->answer($this->asked, $this->version));
        } catch (Throwable $exception) {
            Log::warning('Answering failed', ['question' => $this->asked, 'exception' => $exception]);
            $this->error = $this->describe($exception);
        }
    }

    protected function show(Answer $answer): void
    {
        $this->status = $answer->status;
        $this->answerHtml = AnswerHtml::render($answer->text, count($answer->retrieval->chunks));

        $this->sources = array_map(fn (RetrievedChunk $hit) => [
            'number' => $hit->rank,
            'heading_path' => $hit->chunk->heading_path,
            'url' => $hit->chunk->url,
        ], $answer->citations());

        $this->meta = [
            'provider' => $answer->provider,
            'model' => $answer->model,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
        ];
    }

    protected function resetAnswer(): void
    {
        $this->reset('asked', 'status', 'answerHtml', 'sources', 'meta', 'error');
    }

    /**
     * Turn a failure into a message that says what happened and what to do about it.
     */
    protected function describe(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof RateLimitedException => 'The AI provider is rate limiting requests. Wait a minute and ask again.',
            $exception instanceof ProviderOverloadedException => 'The AI provider is overloaded right now. Ask again in a moment, or set RAG_CHAT_FALLBACK_PROVIDER.',
            $exception instanceof ProviderConnectionException => 'Could not reach the AI provider. Check your connection and API key, then ask again.',
            default => 'Something went wrong while answering. Details are in the application log.',
        };
    }

    public function render(): View
    {
        return view('livewire.ask-docs');
    }
}
