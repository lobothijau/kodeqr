<?php

declare(strict_types=1);

use App\Enums\AggregateDimension;
use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Models\QrCode;
use App\Models\ScanEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('creates every core table', function () {
    expect(Schema::hasTable('qr_codes'))->toBeTrue()
        ->and(Schema::hasTable('scan_events'))->toBeTrue()
        ->and(Schema::hasTable('scan_daily_aggregates'))->toBeTrue()
        ->and(Schema::hasTable('scan_dim_aggregates'))->toBeTrue();
});

it('round-trips destination and style as arrays', function () {
    $qr = QrCode::factory()->create([
        'destination' => ['url' => 'https://example.test/menu', 'dest_url' => 'https://example.test/menu'],
        'style' => ['fg' => '#000000', 'bg' => '#FFFFFF'],
    ]);

    $fresh = $qr->fresh();

    expect($fresh->destination)->toBeArray()
        ->and($fresh->destination['dest_url'])->toBe('https://example.test/menu')
        ->and($fresh->style)->toBeArray()
        ->and($fresh->style['fg'])->toBe('#000000');
});

it('defaults a new code to active', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    // Raw insert so the column default is what sets the status, not the factory.
    DB::table('qr_codes')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'slug' => 'Ab3De7',
        'type' => QrCodeType::Url->value,
        'destination' => json_encode(['url' => 'https://example.test']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(QrCode::findOrFail($id)->status)->toBe(QrCodeStatus::Active);
});

it('soft deletes a code', function () {
    $qr = QrCode::factory()->create();

    $qr->delete();

    expect(QrCode::query()->count())->toBe(0)
        ->and(QrCode::withTrashed()->count())->toBe(1);
});

it('keeps scan_events append-only', function () {
    expect(Schema::hasColumn('scan_events', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('scan_events', 'occurred_at'))->toBeTrue();
});

it('rejects a duplicate event_uuid', function () {
    $uuid = (string) Str::ulid();

    ScanEvent::factory()->create(['event_uuid' => $uuid]);

    expect(fn () => ScanEvent::factory()->create(['event_uuid' => $uuid]))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate daily aggregate for the same code and date', function () {
    $qr = QrCode::factory()->create();

    $row = ['qr_code_id' => $qr->id, 'date' => '2026-08-19', 'scans' => 1, 'uniques' => 1];

    DB::table('scan_daily_aggregates')->insert($row);

    expect(fn () => DB::table('scan_daily_aggregates')->insert($row))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate dim aggregate for the same code, date, dim and key', function () {
    $qr = QrCode::factory()->create();

    $row = [
        'qr_code_id' => $qr->id,
        'date' => '2026-08-19',
        'dim' => AggregateDimension::City->value,
        'key' => 'Jakarta',
        'count' => 1,
    ];

    DB::table('scan_dim_aggregates')->insert($row);

    expect(fn () => DB::table('scan_dim_aggregates')->insert($row))
        ->toThrow(QueryException::class);
});

it('rejects an unknown qr code status at the database level', function () {
    $qr = QrCode::factory()->create();

    expect(fn () => DB::table('qr_codes')->where('id', $qr->id)->update(['status' => 'exploded']))
        ->toThrow(QueryException::class);
});

it('treats slugs that differ only by case as distinct', function () {
    $user = User::factory()->create();

    QrCode::factory()->for($user)->create(['slug' => 'aBc12X']);

    // Under utf8mb4_unicode_ci these collide; ascii_bin keeps them distinct. On
    // SQLite the comparison is already binary, so this only proves anything on MySQL.
    expect(fn () => QrCode::factory()->for($user)->create(['slug' => 'ABC12X']))
        ->not->toThrow(QueryException::class)
        ->and(QrCode::query()->whereIn('slug', ['aBc12X', 'ABC12X'])->count())->toBe(2);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'mysql',
    'Slug collation only observable on MySQL; SQLite compares TEXT binary.',
);
