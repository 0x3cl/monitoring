<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'SENTINEL') }}</title>

        {{--
            Resolve the theme before first paint, otherwise a dark-mode user
            sees a white flash while the bundle loads.
        --}}
        <script>
            (function () {
                try {
                    var mode = localStorage.getItem('sentinel-theme') || 'system';
                    var dark = mode === 'dark'
                        || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {
                    // Blocked site data: fall back to the light theme.
                }
            })();
        </script>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-svh antialiased">
        <div id="app"></div>
    </body>
</html>
