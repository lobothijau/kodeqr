<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
    | The destination threat check (constraint 5). Cloudflare's security resolver
    | answers 0.0.0.0 with EDE 16 ("Censored") for domains in its malware and
    | phishing intel, and needs no account or key. Safe Browsing was ruled out: it
    | needs a Google Cloud project (.ai/rules/general.md), and every free no-account
    | feed (OpenPhish, URLhaus) has moved behind registration. Domain-level only:
    | see docs/BACKLOG.md for what that misses.
    */
    'threat_check' => [
        // Named in the message the owner sees: the verdict is this service's, not
        // ours, and an owner who thinks it is wrong needs to know whose list to
        // appeal to. Change the provider and this changes with it.
        'name' => env('THREAT_CHECK_NAME', 'Cloudflare'),
        'resolver' => env('THREAT_CHECK_RESOLVER', 'https://security.cloudflare-dns.com/dns-query'),
        'timeout' => env('THREAT_CHECK_TIMEOUT', 2),
        'cache_ttl' => env('THREAT_CHECK_CACHE_TTL', 86400),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
