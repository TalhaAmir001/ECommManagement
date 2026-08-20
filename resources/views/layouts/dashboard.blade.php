<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'Dashboard') · Storefront</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-canvas text-ink antialiased">
        <div class="flex min-h-screen">
            <x-dashboard.sidebar />

            <div class="flex min-w-0 flex-1 flex-col">
                <x-dashboard.topbar />

                <main class="flex-1">
                    @yield('content')
                </main>
            </div>
        </div>
    </body>
</html>
