<?php

declare(strict_types=1);

/**
 * Scanner-facing copy. Every one of these is read by someone standing in front of a
 * printed code, on a phone, on cellular — so each page says what happened and what
 * to do next, and never blames them.
 *
 * One string per page, used as both the <title> and the <h1>: a camera app opens
 * these full-screen, so the tab title has no separate reader to write for.
 */
return [
    'inactive' => [
        'title' => 'QR ini sedang tidak aktif',
        'body' => 'Pemiliknya menonaktifkan QR ini untuk sementara. Coba pindai lagi nanti.',
    ],
    'blocked' => [
        'title' => 'QR ini diblokir',
        'body' => 'QR ini diblokir demi keamanan Anda. Jangan lanjutkan, dan laporkan ke pemilik tempat Anda menemukannya.',
    ],
    'not_found' => [
        'title' => 'QR ini tidak ditemukan',
        'body' => 'QR mungkin salah pindai atau sudah dihapus. Periksa kembali QR yang Anda pindai.',
    ],
    'unavailable' => [
        'title' => 'Sedang ada gangguan',
        'body' => 'Kami tidak bisa membuka QR ini sekarang. Coba pindai lagi beberapa saat lagi.',
    ],
    /*
     * The interstitial. It names the destination host because a scanner deserves to
     * know where a piece of printed paper is about to send them.
     *
     * `managed` is what a lapsed owner's code shows instead of the free-tier CTA. It
     * says nothing about expiry or money: the reader is almost always the owner's
     * customer, not the owner, and "your package has expired" told to a restaurant's
     * diners is both nonsense to them and embarrassing for the restaurant. The owner
     * recognises it as a way back in; nobody else reads anything into it.
     */
    'splash' => [
        'title' => 'Anda akan diarahkan ke',
        'action' => 'Lanjutkan',
        'cta' => 'Buat QR gratis di :brand',
        'managed' => 'Dikelola dengan :brand — masuk untuk mengelola QR ini',
    ],

    'footer' => 'Powered by :brand',
];
