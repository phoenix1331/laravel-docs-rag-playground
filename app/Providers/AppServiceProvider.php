<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Embedder;
use App\Services\Embeddings\AiSdkEmbedder;
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

            return new AiSdkEmbedder($config['provider'], $model, $config['dimensions'], $config['batch_size']);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerDevCommands();
    }

    /**
     * Serve the app through FrankenPHP rather than "artisan serve" when running "artisan dev".
     */
    protected function registerDevCommands(): void
    {
        DevCommands::except('server');
        DevCommands::register('frankenphp php-server --listen :8000 --root public', 'frankenphp');
    }
}
