<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#fafafa">
    <title>{{ __('abuse.title') }} · kodeqr</title>
    <style>
        {{-- Same tokens as redirect.layout, kept separate on purpose: that layout is
             on the scan path and every rule added to it is downloaded by every free
             scan. Form styling has no business there. --}}
        :root {
            --bg: #fafafa; --surface: #ffffff; --fg: #09090b; --muted: #52525b;
            --line: #e4e4e7; --brand: #18181b; --danger: #b91c1c; --danger-bg: #fee2e2;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; min-height: 100dvh;
            display: flex; flex-direction: column; align-items: center;
            gap: 1.5rem; padding: 2rem 1.5rem calc(2rem + env(safe-area-inset-bottom));
            background: var(--bg); color: var(--fg);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
            font-size: 1rem; line-height: 1.5; -webkit-font-smoothing: antialiased;
        }
        .brand {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.9375rem; font-weight: 600; letter-spacing: -0.01em; color: var(--brand);
        }
        .brand svg { width: 1.125rem; height: 1.125rem; }
        .brand .tld { color: var(--muted); font-weight: 300; }

        .card {
            width: 100%; max-width: 28rem;
            background: var(--surface); border: 1px solid var(--line);
            border-radius: 1rem; padding: 1.75rem 1.5rem;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        }
        h1 { margin: 0 0 0.5rem; font-size: 1.375rem; font-weight: 600; letter-spacing: -0.02em; }
        .intro { margin: 0 0 1.5rem; color: var(--muted); font-size: 0.9375rem; text-wrap: pretty; }

        .field { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.375rem; }
        label .optional { font-weight: 400; color: var(--muted); }
        .help { margin: 0.375rem 0 0; font-size: 0.8125rem; color: var(--muted); }
        input[type=file] { width: 100%; font-size: 0.875rem; color: var(--muted); }
        input[type=text], input[type=email], select {
            width: 100%; padding: 0.625rem 0.75rem;
            border: 1px solid var(--line); border-radius: 0.5rem;
            background: var(--surface); color: var(--fg);
            font: inherit; font-size: 1rem;
        }
        input:focus-visible, select:focus-visible, button:focus-visible {
            outline: 2px solid var(--brand); outline-offset: 2px;
        }
        .error { margin: 0.375rem 0 0; font-size: 0.8125rem; color: var(--danger); }
        [aria-invalid=true] { border-color: var(--danger); }

        button {
            width: 100%; min-height: 2.75rem; padding: 0.625rem 1.25rem;
            border: 0; border-radius: 999px;
            background: var(--brand); color: var(--surface);
            font: inherit; font-size: 0.9375rem; font-weight: 600; cursor: pointer;
        }
        button:active { opacity: 0.85; }

        {{-- The honeypot. Off-screen rather than display:none, which the crawlers
             that matter have checked for since about 2010, and removed from the tab
             order and the accessibility tree so no human ever meets it. --}}
        .hp {
            position: absolute; left: -9999px; width: 1px; height: 1px;
            overflow: hidden;
        }

        .sent { text-align: center; }
        .sent .badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 3rem; height: 3rem; margin-bottom: 1.25rem;
            border-radius: 0.75rem; background: #dcfce7; color: #15803d;
        }
        .sent .badge svg { width: 1.5rem; height: 1.5rem; }
        .sent a { display: inline-block; margin-top: 1.25rem; color: var(--brand); font-weight: 600; font-size: 0.9375rem; }

        footer { font-size: 0.8125rem; color: var(--muted); }
        footer a { color: var(--brand); font-weight: 500; text-decoration: none; }
        footer a:hover { text-decoration: underline; }

        @media (min-width: 640px) { .card { padding: 2.25rem 2rem; } }
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

    <main class="card">
        @if (session('status') === 'reported')
            {{-- Identical for a live slug, a deleted one and one that never existed.
                 Anything that varied here would answer the question the endpoint
                 exists to refuse: does this code exist? --}}
            <div class="sent">
                <div class="badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5" />
                    </svg>
                </div>
                <h1>{{ __('abuse.sent.title') }}</h1>
                <p class="intro">{{ __('abuse.sent.body') }}</p>
                <a href="{{ route('abuse.report.show') }}">{{ __('abuse.sent.again') }}</a>
            </div>
        @else
            <h1>{{ __('abuse.title') }}</h1>
            <p class="intro">{{ __('abuse.intro') }}</p>

            <form method="POST" action="{{ route('abuse.report.store') }}">
                @csrf

                {{-- Hidden until the JS below confirms the browser can actually decode
                     a QR. Revealing it first and failing on tap is worse than never
                     offering it: the reporter has already gone to fetch the sticker. --}}
                <div class="field" id="qr-scan" hidden>
                    <label for="qr-image">{{ __('abuse.scan.label') }}</label>
                    <input type="file" id="qr-image" accept="image/*" capture="environment">
                    <p class="help" id="qr-note">{{ __('abuse.scan.help') }}</p>
                </div>

                <div class="field">
                    <label for="report">{{ __('abuse.report.label') }}</label>
                    <input type="text" id="report" name="report" required
                           inputmode="url" autocapitalize="none" autocorrect="off" spellcheck="false"
                           value="{{ old('report', $prefill ?? '') }}"
                           @error('report') aria-invalid="true" aria-describedby="report-error" @enderror>
                    @error('report')
                        <p class="error" id="report-error">{{ __('abuse.error.report') }}</p>
                    @else
                        <p class="help">{{ __('abuse.report.help') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="reason">{{ __('abuse.reason.label') }}</label>
                    <select id="reason" name="reason" required
                            @error('reason') aria-invalid="true" aria-describedby="reason-error" @enderror>
                        {{-- Without this, the first option is pre-selected and anybody
                             who never opens the dropdown files a PHISHING report — the
                             field the operator triages on and the one in the subject
                             line. `required` cannot fire while a value is always
                             present, so the placeholder is what gives it meaning. --}}
                        <option value="" disabled @selected(old('reason') === null)>{{ __('abuse.reason.placeholder') }}</option>
                        @foreach ($reasons as $reason)
                            <option value="{{ $reason->value }}" @selected(old('reason') === $reason->value)>
                                {{ __('abuse.reason.'.$reason->value) }}
                            </option>
                        @endforeach
                    </select>
                    @error('reason')
                        <p class="error" id="reason-error">{{ __('abuse.error.reason') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="reporter_email">
                        {{ __('abuse.email.label') }}
                        <span class="optional">({{ __('abuse.email.optional') }})</span>
                    </label>
                    <input type="email" id="reporter_email" name="reporter_email"
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           value="{{ old('reporter_email') }}"
                           @error('reporter_email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('reporter_email')
                        <p class="error" id="email-error">{{ __('abuse.error.email') }}</p>
                    @else
                        <p class="help">{{ __('abuse.email.help') }}</p>
                    @enderror
                </div>

                {{-- NOT named `website`, `url`, `email` or anything else a password
                     manager fills. autocomplete="off" does not stop 1Password or
                     Bitwarden, and a manager filling this drops a real report on the
                     floor while showing the reporter the success page. `subjek` is
                     nothing any manager has a value for, and a bot filling inputs by
                     name still fills it. --}}
                <div class="hp" aria-hidden="true">
                    <label for="subjek">Subjek</label>
                    <input type="text" id="subjek" name="subjek" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit">{{ __('abuse.submit') }}</button>
            </form>
        @endif
    </main>

    {{--
        The QR picker, and the only JavaScript on any public page in this app.

        It decodes on the DEVICE with the browser's own BarcodeDetector and writes the
        result into the ordinary text field, so the form still posts plain text to the
        endpoint that already existed. No upload route, no image parsing on our server,
        no stored photographs of the places people found these stickers — and no new
        dependency, which constraint 11 would otherwise have made a conversation.

        Everything is feature-detected and the picker stays hidden unless the decode
        is genuinely available, so a browser without it (Safari, at time of writing)
        sees exactly the form that shipped before this.

        The raw decoded value goes in unparsed: the server already knows how to pull a
        slug out of a /x/ URL and to refuse a foreign host, and a second copy of that
        logic here is a second place for it to be wrong.
    --}}
    <script>
        (function () {
            var field = document.getElementById('report');
            var picker = document.getElementById('qr-image');
            var wrap = document.getElementById('qr-scan');
            var note = document.getElementById('qr-note');
            var copy = @json(__('abuse.scan'));

            if (!field || !picker || !wrap || !('BarcodeDetector' in window) || !window.createImageBitmap) {
                return;
            }

            window.BarcodeDetector.getSupportedFormats().then(function (formats) {
                if (formats.indexOf('qr_code') === -1) {
                    return;
                }

                var detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                wrap.hidden = false;

                picker.addEventListener('change', function () {
                    var file = picker.files && picker.files[0];

                    if (!file) {
                        return;
                    }

                    note.textContent = copy.reading;

                    window.createImageBitmap(file).then(function (bitmap) {
                        return detector.detect(bitmap).then(function (codes) {
                            bitmap.close();

                            if (!codes.length) {
                                note.textContent = copy.failed;

                                return;
                            }

                            field.value = codes[0].rawValue;
                            note.textContent = copy.found;
                        });
                    }).catch(function () {
                        note.textContent = copy.failed;
                    });
                });
            }).catch(function () {});
        }());
    </script>

    <footer>{!! __('redirect.footer', ['brand' => '<a href="https://kodeqr.com">kodeqr.com</a>']) !!}</footer>
</body>
</html>
