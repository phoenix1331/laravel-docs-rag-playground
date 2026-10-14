<?php

declare(strict_types=1);

namespace App\Providers;

use App\Answering\DocsAssistant;
use App\Answering\QuestionLog;
use App\Chunking\MarkdownChunker;
use App\Contracts\Embedder;
use App\Docs\DocsRepository;
use App\Retrieval\Retriever;
use App\Services\Embeddings\AiSdkEmbedder;
use App\Services\Embeddings\EmbeddingThrottle;
use App\Services\Embeddings\FakeEmbedder;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Embedder::class, function (): Embedder {
            $config = config('rag.embedding');

            if ($config['provider'] === 'fake') {
                return new FakeEmbedder($config['dimensions']);
            }

            $model = $config['model'] ?? Ai::embeddingProvider($config['provider'])->defaultEmbeddingsModel();

            $throttle = new EmbeddingThrottle(
                cache()->store(),
                $config['requests_per_minute'],
                $config['tokens_per_minute'],
            );

            return new AiSdkEmbedder(
                $config['provider'],
                $model,
                $config['dimensions'],
                $config['batch_size'],
                $config['batch_tokens'],
                $throttle->enabled() ? $throttle : null,
            );
        });

        $this->app->bind(MarkdownChunker::class, fn () => new MarkdownChunker(
            config('rag.chunking.max_tokens'),
            config('rag.chunking.min_tokens'),
        ));

        $this->app->bind(Retriever::class, fn ($app) => new Retriever(
            $app->make(Embedder::class),
            config('rag.retrieval.mode'),
            config('rag.retrieval.top_k'),
        ));

        $this->app->bind(DocsAssistant::class, fn ($app) => new DocsAssistant(
            $app->make(Retriever::class),
            config('rag.chat'),
            config('rag.retrieval.min_similarity'),
            config('rag.docs.default_version'),
            new QuestionLog(config('rag.log_questions')),
        ));

        $this->app->bind(DocsRepository::class, fn () => new DocsRepository(
            config('rag.docs.repository'),
            config('rag.docs.path'),
            config('rag.docs.base_url'),
            config('rag.docs.versions'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerDevCommands();
    }

    /**
     * Serve the app through FrankenPHP rather than "artisan serve", and let Horizon (which registers
     * itself) process the queue instead of "queue:listen", when running "artisan dev".
     */
    protected function registerDevCommands(): void
    {
        DevCommands::except('server', 'queue');
        DevCommands::register('frankenphp php-server --listen :8000 --root public', 'frankenphp');
    }
}
