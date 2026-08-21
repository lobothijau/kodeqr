<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Canary
    |--------------------------------------------------------------------------
    |
    | A real QR code, scanned by us once a minute over the public URL. The point
    | is the WHOLE path — Cloudflare, TLS, the origin, Valkey — so `url` must be
    | the public hostname and never a loopback address. A canary that proves PHP
    | is up while Cloudflare serves errors to every scanner is worse than none,
    | because it reports green through the outage.
    |
    */

    'canary' => [
        'slug' => env('HEALTH_CANARY_SLUG', 'Canary'),
        'destination' => env('HEALTH_CANARY_DESTINATION', 'https://kodeqr.com/canary'),
    ],

    'url' => env('HEALTH_URL', env('APP_URL')),

    /*
    | Proof the request really traversed the edge.
    |
    | A public hostname is not enough. On Laravel Cloud, APP_URL is routinely the
    | *.laravel.cloud ORIGIN name — publicly routable, passes every other check, and
    | goes nowhere near Cloudflare. A canary pointed there reports green through an
    | edge rule, a DNS change or an expired edge certificate, which is the exact
    | failure the task file calls "worse than none, because it is believed".
    |
    | Cloudflare stamps cf-ray on every response it serves. Set to an empty string to
    | disable — for a deployment that genuinely has no proxy in front of it.
    */
    'edge_header' => env('HEALTH_EDGE_HEADER', 'cf-ray'),

    // Hard failure. Generous, because this measures a scanner's worst case on
    // Indonesian mobile, not a local round-trip.
    'timeout' => (float) env('HEALTH_TIMEOUT', 5.0),

    // Soft: logged, sampled into p95, never alerted on. A slow redirect is worth
    // knowing about; waking somebody for one is how alerts get muted.
    'slow_ms' => (int) env('HEALTH_SLOW_MS', 1500),

    /*
    | Two consecutive failures before anybody is told — a single miss is a dropped
    | packet. After that, one reminder every half hour rather than one a minute,
    | which is the difference between an alert and a mail filter.
    */
    'failures_before_alert' => 2,
    'remind_every' => 30,

    /*
    | Chained down to a literal on purpose. Every link that resolves to null is an
    | outage where the only record is a log line nobody is reading at 3am — the
    | failure mode an alerting path is least allowed to have.
    */
    'alert_address' => env(
        'HEALTH_ALERT_ADDRESS',
        env('MAIL_ABUSE_ADDRESS', env('MAIL_FROM_ADDRESS', 'alerts@kodeqr.com')),
    ),
];
