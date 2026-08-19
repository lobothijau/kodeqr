<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#fafafa">
    <title>{{ $title }} · kodeqr</title>
    <style>
        :root {
            --bg: #fafafa; --surface: #ffffff; --fg: #09090b; --muted: #52525b;
            --line: #e4e4e7; --brand: #18181b;
            --tone-bg: #f4f4f5; --tone-fg: #52525b;
        }
        .tone-warning { --tone-bg: #fef3c7; --tone-fg: #b45309; }
        .tone-danger  { --tone-bg: #fee2e2; --tone-fg: #b91c1c; }

        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            padding: 2rem 1.5rem calc(2rem + env(safe-area-inset-bottom));
            background: var(--bg);
            color: var(--fg);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
            font-size: 1rem;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .brand {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.9375rem; font-weight: 600; letter-spacing: -0.01em; color: var(--brand);
        }
        .brand svg { width: 1.125rem; height: 1.125rem; }

        .card {
            width: 100%; max-width: 24rem;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 2rem 1.75rem;
            text-align: center;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        }

        .badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 3rem; height: 3rem; margin-bottom: 1.25rem;
            border-radius: 0.75rem;
            background: var(--tone-bg); color: var(--tone-fg);
        }
        .badge svg { width: 1.5rem; height: 1.5rem; }

        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.375rem; font-weight: 600; line-height: 1.3; letter-spacing: -0.02em;
            text-wrap: balance;
        }
        p { margin: 0; color: var(--muted); font-size: 0.9375rem; text-wrap: pretty; }

        footer { font-size: 0.8125rem; color: var(--muted); }
        footer a { color: var(--brand); font-weight: 500; text-decoration: none; }
        footer a:hover { text-decoration: underline; }

        @media (min-width: 640px) {
            .card { padding: 2.5rem 2.25rem; }
            h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
    <div class="brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect width="7" height="7" x="3" y="3" rx="1" />
            <rect width="7" height="7" x="14" y="3" rx="1" />
            <rect width="7" height="7" x="3" y="14" rx="1" />
            <path d="M14 14h.01M21 14h.01M14 21h.01M21 21h.01M17.5 17.5h.01" />
        </svg>
        kodeqr
    </div>

    <main class="card tone-{{ $tone ?? 'neutral' }}">
        <div class="badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                @switch($icon)
                    @case('power')
                        <path d="M12 2v10" />
                        <path d="M18.4 6.6a9 9 0 1 1-12.77.04" />
                        @break
                    @case('shield')
                        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" />
                        <path d="M12 8v4" />
                        <path d="M12 16h.01" />
                        @break
                    @case('search')
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                        @break
                    @default
                        <circle cx="12" cy="12" r="10" />
                        <polyline points="12 6 12 12 16 14" />
                @endswitch
            </svg>
        </div>
        <h1>{{ $title }}</h1>
        <p>{{ $body }}</p>
    </main>

    <footer>{!! __('redirect.footer', ['brand' => '<a href="https://kodeqr.com">kodeqr.com</a>']) !!}</footer>
</body>
</html>
