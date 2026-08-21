<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    @isset($refreshTo)
        {{-- Straight to the destination, never back through /x/: a refresh that
             re-entered the route would record every free-tier scan twice. --}}
        <meta http-equiv="refresh" content="{{ $refreshAfter }};url={{ $refreshTo }}">
    @endisset
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#f4f2ed">
    <title>{{ $title }} · kodeqr</title>
    <style>
        :root {
            --bg: #f4f2ed; --surface: #fbfaf7; --fg: #1c1a17; --muted: #6f6a61;
            --line: #e4e0d8; --brand: #1c1a17;
            --tone-bg: #edeae3; --tone-fg: #6f6a61;
        }
        .tone-warning { --tone-bg: #f7ebd3; --tone-fg: #8a5a11; }
        .tone-danger  { --tone-bg: #f7e1dc; --tone-fg: #98362a; }

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
        {{-- The TLD is muted, not dropped. A scanner has a few seconds and will not read the
           footer, so this is the only address they can carry away and type later —
           but it sits above a card whose whole job is to make ONE domain the loudest
           thing on the page. Muted keeps it legible and typeable without letting it
           compete with the destination for the eye. --}}
        .brand .tld { color: var(--muted); font-weight: 300; }

        .card {
            width: 100%; max-width: 24rem;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 1rem;
            overflow: hidden;
            padding: 2rem 1.75rem;
            text-align: center;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        }

        {{-- The page moves on its own. Nothing else on it says so, and a filled button
           underneath is read by a non-technical scanner as "nothing happens until you
           press this" — so they either wait for a page that has already gone, or reach
           for it exactly as it jumps, which feels like a hijack rather than a redirect.
           A bar says it without a sentence, which matters when the whole page is on
           screen for seconds and reading is the thing there is no time for.

           It fills rather than drains: filling is the page-load idiom every phone user
           already knows, and it frames the wait as arriving somewhere. A bar emptying
           out is a countdown, which puts a clock on a scanner who has done nothing
           wrong and turns the one calm moment in the flow into pressure. --}}
        .progress {
            height: 3px; margin: -2rem -1.75rem 1.5rem;
            background: var(--line);
        }
        .progress i {
            display: block; height: 100%;
            background: var(--brand);
            animation: fill var(--drain, 5s) linear forwards;
        }
        @keyframes fill { from { width: 0; } to { width: 100%; } }

        h1.label {
            margin: 0 0 0.75rem;
            color: var(--muted); font-size: 0.9375rem; font-weight: 400; letter-spacing: 0;
        }
        {{-- Three lines, then an ellipsis. The host is first in the source, so what a
           long path pushes out of view is always the end of the path — never the one
           part of the string the scanner is meant to read. --}}
        {{-- No button. The page forwards itself, so a filled pill bought the scanner
           nothing — while being the highest-contrast object on a page they see for
           a few seconds, it won the only glance available and spent it on a control
           that did not need pressing. What that glance is worth spending on is the
           destination, and who is showing it to them.

           The path's styling lives on the paragraph and the host overrides it, not the
           other way round, because the ellipsis `-webkit-line-clamp` draws is rendered
           in the styles of the element carrying the clamp — not of the text it cuts.
           With the weights the other way it came out bold and near-black on the end of
           a muted grey path, reading as emphasis rather than as "there is more". --}}
        .host {
            display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3;
            overflow: hidden;
            margin: 0;
            font-size: 1rem; font-weight: 400; line-height: 1.35;
            letter-spacing: -0.01em; color: var(--muted);
            overflow-wrap: anywhere;
        }
        .host .domain {
            font-size: 1.25rem; font-weight: 600; letter-spacing: -0.02em;
            color: var(--fg);
        }
        {{-- A fallback, and priced like one. Some in-app browsers drop meta refresh,
           and a scanner stranded in front of printed paper cannot go back — so there
           has to be a way through. But a button present during the wait is
           the highest-contrast thing on the page and wins the only glance available,
           spending it on a control nobody needs to press.

           So it is not present during the wait. It reveals one second after the
           redirect should already have fired, which on a working browser is a moment
           that never arrives — the page is gone. What is left is a button that only
           the people who need it ever see. No JS: the delay is the animation's. --}}
        .fallback {
            position: relative;
            display: inline-flex; align-items: center; gap: 0.375rem;
            margin-top: 1.5rem; padding: 0.4375rem 1.125rem;
            background: var(--brand); color: var(--surface);
            border-radius: 999px;
            font-size: 0.875rem; font-weight: 600; text-decoration: none;
            animation:
                hold var(--reveal, 6s) step-end both,
                reveal 0.25s ease-out var(--reveal, 6s) both;
        }
        {{-- The base styles are the VISIBLE ones and the animation does the hiding,
             which is the wrong way round until you ask what happens when it fails.
             The browsers that drop meta refresh are the same old in-app shells most
             likely to ignore an animated `display` — and for them the button must be
             present, not permanently invisible. Written this way an engine that does
             not understand these keyframes simply renders the button from the start:
             a slightly noisier page, rather than a scan with no way out.

             `display` collapses the box so nothing is reserved while it waits;
             `opacity` is a separate animation so that engines which ignore discrete
             `display` still reveal it on time, merely having held its space. --}}
        @keyframes hold { from { display: none; } to { display: inline-flex; } }
        @keyframes reveal { from { opacity: 0; } to { opacity: 1; } }
        {{-- Shallow pill, so ::after carries the tap target back up to 44px. --}}
        .fallback::after { content: ''; position: absolute; inset: -0.3125rem -0.5rem; }
        .fallback:active { opacity: 0.85; }
        .fallback svg { width: 0.875rem; height: 0.875rem; }

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
            .progress { margin: -2.5rem -2.25rem 1.5rem; }
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
        <span>kodeqr<span class="tld">.com</span></span>
    </div>

    <main class="card tone-{{ $tone ?? 'neutral' }}">
        @isset($host)
            {{-- Host first, and large. This is the one moment in the whole flow where
                 somebody standing in front of a printed code can still decline. --}}
            <div class="progress" aria-hidden="true"><i style="--drain: {{ $refreshAfter }}s"></i></div>
            <h1 class="label">{{ $title }}</h1>
            {{-- Deliberately not a link. The fallback below is the way through, and
                 an href here would put a third untruncated copy of the destination in
                 the response — the page is small on purpose and long URLs are real. --}}
            <p class="host"><span class="domain">{{ $host }}</span>{{ $path ?? '' }}</p>
            <a class="fallback" href="{{ $destination }}" rel="noreferrer" style="--reveal: {{ $revealAfter }}s">
                {{ __('redirect.splash.action') }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 12h14" /><path d="m12 5 7 7-7 7" />
                </svg>
            </a>
        @else
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
        @endisset
    </main>

    <footer>{!! $footer ?? __('redirect.footer', ['brand' => '<a href="https://kodeqr.com">kodeqr.com</a>']) !!}</footer>
</body>
</html>
