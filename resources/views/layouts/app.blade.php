<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? 'Ask the Laravel docs' }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen">
        <header class="border-b border-rule">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <a href="{{ url('/') }}" class="text-sm font-semibold tracking-tight">Ask the Laravel docs</a>

                <nav class="flex items-center gap-5 text-sm text-muted">
                    <a href="https://github.com/laravel/docs" class="hidden hover:text-ink sm:inline" rel="noopener">Source: laravel/docs</a>

                    @if (\Laravel\Horizon\Horizon::check(request()))
                        <a href="{{ url(config('horizon.path', 'horizon')) }}" class="hover:text-ink">Horizon</a>
                    @endif
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12">
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
