<?php

declare(strict_types=1);

/**
 * Owner-facing copy for the QR builder. Read by somebody logged in, on a laptop or a
 * phone, who is trying to get a code onto a menu before service starts.
 */
return [
    'field' => [
        'type' => 'jenis QR',
        'url' => 'tautan tujuan',
        'phone' => 'nomor WhatsApp',
        'text' => 'pesan otomatis',
    ],

    'type' => [
        'url' => 'Tautan (URL)',
        'whatsapp' => 'WhatsApp',
        'file' => 'Berkas',
        'vcard' => 'Kartu nama',
        'linkpage' => 'Halaman tautan',
    ],

    // Flash messages after a toggle.
    'status' => [
        'active' => 'QR diaktifkan kembali.',
        'paused' => 'QR dijeda. Pemindai akan melihat halaman "sedang tidak aktif".',
    ],

    /*
     * The pill on the index. Separate from the flash messages above because these are
     * labels, not sentences — and because rendering the raw enum value put English
     * technical strings ("over_quota") in front of an Indonesian owner.
     */
    'status_label' => [
        'active' => 'Aktif',
        'paused' => 'Dijeda',
        'blocked' => 'Diblokir',
        'over_quota' => 'Kuota habis',
    ],

    /*
     * Shown when DestinationRenderer refuses input the cheap rules let through. It
     * names what to do rather than what went wrong, because the underlying reasons
     * (userinfo in the authority, a percent-escape in the host) mean nothing to the
     * person typing.
     */
    'unrenderable' => [
        'url' => 'Tautan ini tidak bisa dipakai sebagai tujuan. Pastikan formatnya seperti https://namasitus.com/halaman.',
        'phone' => 'Nomor WhatsApp tidak valid. Gunakan minimal 9 angka, contoh 081234567890.',
    ],

    'created' => 'QR :slug berhasil dibuat.',
    'updated' => 'Tujuan QR diperbarui. Pemindaian berikutnya langsung mengarah ke tujuan baru.',
    'deleted' => 'QR dihapus. Kode yang sudah tercetak akan menampilkan halaman "tidak ditemukan".',
    'not_pausable' => 'QR ini sedang diblokir atau melewati kuota, jadi statusnya tidak bisa diubah dari sini.',

    /*
     * Plan-gated refusals carry an `upgrade_to` payload alongside the message, per
     * the constitution's error convention, so the UI can name the next tier instead
     * of showing a generic upgrade prompt.
     */
    'quota_reached' => 'Anda sudah memakai semua :limit kode di paket ini. Naikkan paket untuk membuat kode baru.',

    /*
     * Lapsed is not "out of quota". Their codes keep redirecting for ever
     * (constraint 8) — what stopped is editing and creating. The quota wording would
     * have told them they had used all 0 of their codes, which is nonsense addressed
     * to somebody we want back.
     */
    'lapsed' => 'Paket Anda sudah berakhir. QR yang sudah ada tetap berfungsi, tetapi untuk membuat atau mengubah kode, perpanjang paket Anda.',
];
