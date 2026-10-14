<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
