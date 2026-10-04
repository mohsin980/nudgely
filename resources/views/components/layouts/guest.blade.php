<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'QuoteFlow') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans text-gray-900 antialiased">
        <main id="main" class="mx-auto flex min-h-full max-w-md flex-col justify-center px-4 py-10">
            <p class="mb-6 text-center text-base font-semibold tracking-tight text-gray-900">{{ config('app.name', 'QuoteFlow') }}</p>
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                {{ $slot }}
            </div>
        </main>
    </body>
</html>
