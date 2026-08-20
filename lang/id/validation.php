<?php

declare(strict_types=1);

/**
 * Project-specific validation messages (constraint 10).
 *
 * Laravel's own validation messages ship in English only, and this app has no
 * `lang/en` — the fallback locale is `id` too. Translating the framework's full set
 * belongs to M2-T1, which is where a form first shows one to anybody; until then
 * this file holds only the rules this project wrote.
 */
return [
    /*
     * We are relaying a verdict, not making one. The check is a lookup against
     * :layanan's threat intelligence, so the message says whose finding it is —
     * claiming it as ours asserts an authority we do not have and leaves an owner
     * with a legitimate site nobody to appeal to. It says "domain" because that is
     * what is actually checked; the path is not.
     */
    'safe_destination' => 'Domain ini ditandai berbahaya oleh layanan keamanan :layanan, jadi belum bisa dipakai sebagai tujuan. Jika menurut Anda ini keliru, hubungi kami.',
];
