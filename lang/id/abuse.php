<?php

declare(strict_types=1);

/**
 * The public abuse-report form. Read by somebody who has just been sent somewhere
 * they did not expect, so it asks for the minimum and promises nothing it cannot do.
 */
return [
    'title' => 'Laporkan QR ini',
    'intro' => 'Menemukan QR kodeqr yang mengarah ke penipuan atau situs berbahaya? Beri tahu kami dan kami akan memeriksanya.',

    /*
     * The QR picker. Progressive enhancement over the text field, never a replacement
     * for it: the decode happens in the browser via BarcodeDetector, so a device
     * without it must still be able to report. Nothing here is required.
     */
    'scan' => [
        'label' => 'Pindai gambar QR',
        'help' => 'Ambil foto atau pilih gambar QR — kode akan terisi otomatis. Gambar tidak dikirim ke kami.',
        'reading' => 'Membaca gambar…',
        'found' => 'Kode ditemukan.',
        'failed' => 'QR tidak terbaca. Coba foto yang lebih jelas, atau ketik kodenya di bawah.',
    ],

    'report' => [
        'label' => 'Kode atau tautan QR',
        'help' => 'Contoh: kodeqr.com/x/Ab3xK9 atau cukup Ab3xK9',
    ],
    'reason' => [
        'label' => 'Alasan',
        'placeholder' => 'Pilih alasan…',
        'phishing' => 'Meniru situs resmi (phishing)',
        'malware' => 'Menyebarkan virus atau aplikasi berbahaya',
        'penipuan' => 'Penipuan atau transfer palsu',
        'lainnya' => 'Lainnya',
    ],
    'email' => [
        'label' => 'Email Anda',
        'optional' => 'opsional',
        'help' => 'Hanya dipakai jika kami perlu menanyakan detail. Laporan tanpa email tetap kami proses.',
    ],
    'submit' => 'Kirim laporan',

    /*
     * Shown for every submission — a live slug, a deleted one, one that never
     * existed. The wording promises review, never an outcome, because saying
     * anything about what we found would answer the question the form refuses to
     * answer: does this code exist?
     */
    'sent' => [
        'title' => 'Laporan terkirim',
        'body' => 'Terima kasih. Tim kami akan memeriksa laporan Anda. Jika Anda merasa dirugikan secara finansial, segera hubungi bank Anda dan laporkan ke pihak berwenang.',
        'again' => 'Kirim laporan lain',
    ],

    'error' => [
        'report' => 'Masukkan kode atau tautan QR yang ingin dilaporkan.',
        'reason' => 'Pilih salah satu alasan.',
        'email' => 'Format email tidak valid.',
    ],
];
