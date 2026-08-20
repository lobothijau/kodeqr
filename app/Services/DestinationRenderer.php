<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\QrCodeType;
use InvalidArgumentException;

/**
 * Turns an owner's destination input into the single string the scanner is sent to,
 * once, at save time (invariant I1).
 *
 * The redirect path reads `destination['dest_url']` and nothing else — it must never
 * string-build a URL per request, because that would put phone normalisation and
 * percent-encoding on the warm path and turn an owner's typo into a scanner-facing
 * failure. Everything here happens on the owner's write instead, where throwing is a
 * 500 they can see rather than a dead code on printed paper.
 *
 * Rendering REBUILDS the array from a per-type whitelist. `destination` is fillable as
 * a whole array, so anything merged instead of rebuilt would let a crafted payload
 * carry its own `dest_url` past validation and point the code anywhere.
 */
final class DestinationRenderer
{
    /**
     * Not a product rule, a transport one: E.164 tops out at 15 digits, and the
     * shortest plausible mobile number with a country code is 9.
     */
    private const PHONE_MIN_DIGITS = 9;

    private const PHONE_MAX_DIGITS = 15;

    /**
     * An Indonesian mobile number without its trunk prefix: 8 followed by 8 to 11
     * digits. Anything longer that starts with 8 carries its own country code.
     */
    private const LOCAL_MOBILE_MAX_DIGITS = 12;

    /**
     * @param  array<string, mixed>  $destination
     * @return array<string, mixed>
     */
    public function render(QrCodeType $type, array $destination): array
    {
        return match ($type) {
            QrCodeType::Url => $this->url($destination),
            QrCodeType::Whatsapp => $this->whatsapp($destination),
            // M3 (file, linkpage) and M4 (vcard) extend this same shape. Until they
            // do, a row of these types cannot be persisted at all — better than one
            // that persists with no dest_url and dead-ends a scan.
            QrCodeType::File, QrCodeType::Vcard, QrCodeType::Linkpage => throw new InvalidArgumentException(
                "Destination type [{$type->value}] is not renderable yet."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $destination
     * @return array<string, mixed>
     */
    private function url(array $destination): array
    {
        $url = $this->normalizeUrl($this->string($destination, 'url'));

        return ['url' => $url, 'dest_url' => $url];
    }

    /**
     * @param  array<string, mixed>  $destination
     * @return array<string, mixed>
     */
    private function whatsapp(array $destination): array
    {
        $phone = $this->normalizePhone($this->string($destination, 'phone'));
        $text = trim($this->string($destination, 'text'));

        // rawurlencode, NOT urlencode. Checked against a live wa.me link rather than
        // from memory: wa.me accepts %20 and normalises it to its own `+` form, so
        // %20 round-trips under both readings of the query string, while `+` is
        // rendered literally by anything parsing per RFC 3986. Both encode a typed
        // `+` as %2B, so a phone number in the message survives either way.
        $query = $text === '' ? '' : '?text='.rawurlencode($text);

        return [
            'phone' => $phone,
            'text' => $text,
            'dest_url' => "https://wa.me/{$phone}{$query}",
        ];
    }

    /**
     * Digits only, Indonesian trunk prefix resolved. `00` (the international dial
     * prefix) is stripped BEFORE the leading-zero rule, or `0062…` would be read as a
     * domestic number and become `62062…`.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        // Typed with the international dial prefix, the number already carries its
        // own country code — so the domestic rules below must not touch it.
        if (str_starts_with($digits, '00')) {
            return $this->checkedPhone(substr($digits, 2));
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8') && strlen($digits) <= self::LOCAL_MOBILE_MAX_DIGITS) {
            // The form people type under a "+62" field label. Left alone it reads as
            // country code 81 — Japan — and every scan lands on a stranger or nowhere.
            // Bounded by length so a genuine foreign number entered in full (8613…,
            // thirteen digits) is not dragged into Indonesia.
            $digits = '62'.$digits;
        }

        return $this->checkedPhone($digits);
    }

    private function checkedPhone(string $digits): string
    {
        $length = strlen($digits);

        if ($length < self::PHONE_MIN_DIGITS || $length > self::PHONE_MAX_DIGITS) {
            throw new InvalidArgumentException('WhatsApp destination has no usable phone number.');
        }

        return $digits;
    }

    /**
     * Scheme-less input gains https. A scheme that is not http(s) is refused here
     * rather than at the redirect: `javascript:` and `data:` in a Location header are
     * an attack, and a code that carries one must never reach the database.
     */
    public function normalizeUrl(string $url): string
    {
        $url = trim($url);

        // Control characters and raw spaces: a CR/LF would split the Location header,
        // and a space makes the header invalid on the wire.
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new InvalidArgumentException('URL destination is empty or contains illegal characters.');
        }

        // A raw backslash is invalid in a URL and the parsers disagree about it: PHP
        // reads `https://evil.test\\@safe.test/` as host safe.test, while a browser
        // follows WHATWG and treats the backslash as a slash, making the host
        // evil.test. Anything checking one and navigating to the other is a hole, so
        // neither gets the chance.
        if (str_contains($url, '\\')) {
            throw new InvalidArgumentException('URL destination contains a backslash.');
        }

        $parts = $this->parts($url);

        // Userinfo in the authority is the oldest phishing disguise there is —
        // https://bank.co.id@evil.test reads as the bank to a human — and no QR
        // destination needs it. An `@` later in the path (/@handle) is untouched.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL destination must not carry userinfo.');
        }

        if (! isset($parts['scheme'])) {
            // parse_url already reads `example.com:8080/menu` as host+port, so only a
            // genuinely scheme-less URL lands here.
            $url = str_starts_with($url, '//') ? 'https:'.$url : 'https://'.$url;
            $parts = $this->parts($url);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL destination must use http or https.');
        }

        if (($parts['host'] ?? '') === '') {
            throw new InvalidArgumentException('URL destination has no host.');
        }

        return $url;
    }

    /**
     * parse_url returns false for input it cannot make sense of at all (`https://`).
     * Reading that as "no scheme" and prepending one would turn a malformed URL into
     * a valid-looking one pointing at a host the owner never typed.
     *
     * @return array<string, mixed>
     */
    private function parts(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new InvalidArgumentException('URL destination is malformed.');
        }

        return $parts;
    }

    /**
     * Strings only, no coercion: `['url' => true]` casting to "1" and persisting as
     * https://1 turns a malformed payload into a destination that looks deliberate.
     * A wrong shape has to fail on the owner's save, where someone can see it.
     *
     * @param  array<string, mixed>  $destination
     */
    private function string(array $destination, string $key): string
    {
        $value = $destination[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
