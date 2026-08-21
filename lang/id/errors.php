<?php

declare(strict_types=1);

/**
 * Framework-rendered failures, in Bahasa and on the same branded page as everything
 * else a member of the public can hit (constraint 10). These are reachable from the
 * public /laporkan form, which is the only unauthenticated form in the app.
 */
return [
    '429' => [
        'title' => 'Terlalu banyak permintaan',
        'body' => 'Anda mengirim terlalu banyak laporan dalam waktu singkat. Tunggu satu menit, lalu coba lagi.',
    ],
    '419' => [
        'title' => 'Halaman sudah kedaluwarsa',
        'body' => 'Halaman ini terlalu lama dibuka. Muat ulang halaman, lalu kirim laporan Anda sekali lagi.',
    ],
];
