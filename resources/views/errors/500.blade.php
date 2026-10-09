<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Something went wrong</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f9fafb; color: #111827; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { color: #4b5563; line-height: 1.5; margin: .5rem 0; }
        code { font-family: ui-monospace, monospace; font-size: .875rem; background: #e5e7eb; padding: .15rem .4rem; border-radius: .25rem; }
    </style>
</head>
<body>
    <main>
        <h1>Something went wrong on our side.</h1>
        <p>Please try again in a moment. If it keeps happening, contact support and quote this reference:</p>
        @if (filled($reference ?? null))
            <p><code>{{ $reference }}</code></p>
        @endif
    </main>
</body>
</html>
