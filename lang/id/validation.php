<?php

declare(strict_types=1);

/**
 * Validation messages, in Bahasa.
 *
 * Laravel's own messages ship in English only, and this app has NO `lang/en` — the
 * fallback locale is `id` too (constraint 10), so any rule without a translation here
 * shows the owner a raw key like `validation.url`. M1-T5 wrote this file for its own
 * rule and left the framework's set to M2-T1, which is where a form first shows one
 * to anybody. Only the rules this application can actually trigger are translated;
 * the rest of Laravel's file is noise nobody will ever read.
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

    'accepted' => ':attribute harus disetujui.',
    'active_url' => ':attribute bukan URL yang valid.',
    'after' => ':attribute harus berisi tanggal setelah :date.',
    'array' => ':attribute harus berupa daftar.',
    'before' => ':attribute harus berisi tanggal sebelum :date.',
    'between' => [
        'array' => ':attribute harus memiliki antara :min dan :max item.',
        'file' => ':attribute harus berukuran antara :min dan :max kilobyte.',
        'numeric' => ':attribute harus bernilai antara :min dan :max.',
        'string' => ':attribute harus berisi antara :min dan :max karakter.',
    ],
    'boolean' => ':attribute harus bernilai ya atau tidak.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi yang Anda masukkan salah.',
    'date' => ':attribute bukan tanggal yang valid.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus terdiri dari :digits angka.',
    'digits_between' => ':attribute harus terdiri dari :min sampai :max angka.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'enum' => 'Pilihan :attribute tidak valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'file' => ':attribute harus berupa berkas.',
    'filled' => ':attribute wajib diisi.',
    'image' => ':attribute harus berupa gambar.',
    'in' => 'Pilihan :attribute tidak valid.',
    'integer' => ':attribute harus berupa angka bulat.',
    'max' => [
        'array' => ':attribute tidak boleh lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih dari :max kilobyte.',
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'mimes' => ':attribute harus berupa berkas berjenis: :values.',
    'min' => [
        'array' => ':attribute harus memiliki minimal :min item.',
        'file' => ':attribute harus berukuran minimal :min kilobyte.',
        'numeric' => ':attribute harus bernilai minimal :min.',
        'string' => ':attribute harus berisi minimal :min karakter.',
    ],
    'not_in' => 'Pilihan :attribute tidak valid.',
    'numeric' => ':attribute harus berupa angka.',
    /*
     * Fortify's registration and password-reset forms use Password::defaults(), whose
     * failures are `validation.password.*`. Without these an owner setting a weak
     * password is shown a literal `validation.password.symbols` — the exact failure
     * this file exists to prevent, on the first form anybody ever meets.
     */
    'password' => [
        'letters' => ':attribute harus mengandung minimal satu huruf.',
        'mixed' => ':attribute harus mengandung huruf besar dan huruf kecil.',
        'numbers' => ':attribute harus mengandung minimal satu angka.',
        'symbols' => ':attribute harus mengandung minimal satu simbol.',
        'uncompromised' => ':attribute pernah bocor dalam kebocoran data. Pilih kata sandi lain.',
    ],
    'prohibited' => ':attribute tidak boleh diisi.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'required_if' => ':attribute wajib diisi bila :other adalah :value.',
    'same' => ':attribute dan :other harus sama.',
    'size' => [
        'array' => ':attribute harus berisi :size item.',
        'file' => ':attribute harus berukuran :size kilobyte.',
        'numeric' => ':attribute harus bernilai :size.',
        'string' => ':attribute harus berisi :size karakter.',
    ],
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah digunakan.',
    'uploaded' => ':attribute gagal diunggah.',
    'url' => ':attribute harus berupa tautan yang valid, diawali http:// atau https://.',

    'custom' => [],

    /*
     * Only the fields whose forms are NOT ours — Fortify's registration, login and
     * password reset have no request class to hang an `attributes()` method on, so
     * without these an owner reads "password harus mengandung..." with an English
     * noun in the middle of an Indonesian sentence. Everything the application itself
     * validates supplies its names next to the rules they belong to.
     */
    'attributes' => [
        'name' => 'nama',
        'email' => 'email',
        'password' => 'kata sandi',
        'password_confirmation' => 'konfirmasi kata sandi',
        'current_password' => 'kata sandi saat ini',
    ],
];
