<?php

declare(strict_types=1);

use App\Actions\QrCodes\CreateQrCode;
use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Models\QrCode;
use App\Models\User;
use App\Services\SlugGenerator;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates a code with a generated slug and a rendered destination', function () {
    $owner = User::factory()->create();

    $code = app(CreateQrCode::class)($owner, [
        'type' => QrCodeType::Whatsapp,
        'destination' => ['phone' => '08123456789', 'text' => 'Halo'],
    ]);

    expect($code->slug)->toHaveLength(SlugGenerator::LENGTH)
        ->and($code->user_id)->toBe($owner->id)
        ->and($code->fresh()->destination['dest_url'])->toBe('https://wa.me/628123456789?text=Halo');
});

it('refuses to let the caller choose the slug, the owner or the status', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $code = app(CreateQrCode::class)($owner, [
        'type' => QrCodeType::Url,
        'destination' => ['url' => 'https://kodeqr.test'],
        'slug' => 'SquaT2',
        'user_id' => $other->id,
        // Constraint 8: status belongs to the billing and quota state machines. A
        // payload must never be able to hand itself `active` back.
        'status' => QrCodeStatus::Blocked,
    ]);

    // Not fillable either, so an M2 controller doing QrCode::create($validated)
    // cannot hand a caller a printed-forever identifier of their choosing.
    expect((new QrCode(['slug' => 'SquaT2', 'status' => QrCodeStatus::Blocked]))->slug)->toBeNull()
        ->and($code->slug)->not->toBe('SquaT2')
        ->and($code->user_id)->toBe($owner->id)
        // The column default, not the payload: the write never carried a status at all.
        ->and($code->fresh()->status)->toBe(QrCodeStatus::Active);
});

it('never returns a model whose save was halted', function () {
    $owner = User::factory()->create();

    QrCode::saving(fn (): bool => false);

    // save() reports a halted insert in its return value rather than by throwing.
    // Returning the model anyway hands a builder something that looks created while
    // qr_codes stays empty.
    expect(fn () => app(CreateQrCode::class)($owner, [
        'type' => QrCodeType::Url,
        'destination' => ['url' => 'https://kodeqr.test'],
    ]))->toThrow(RuntimeException::class)
        ->and(QrCode::query()->count())->toBe(0);
});

it('retries when the unique index rejects the slug it was given', function () {
    $owner = User::factory()->create();
    QrCode::factory()->create(['slug' => 'RaceD2']);

    // The real race is two creates whose pre-checks both come back clean before
    // either commits — unstageable in a test. This generator reproduces its only
    // observable consequence: an insert that loses to the index.
    $generator = new class extends SlugGenerator
    {
        public int $calls = 0;

        public function make(): string
        {
            $this->calls++;

            return $this->calls === 1 ? 'RaceD2' : 'RaceD3';
        }
    };

    $code = (new CreateQrCode($generator))($owner, [
        'type' => QrCodeType::Url,
        'destination' => ['url' => 'https://kodeqr.test'],
    ]);

    expect($code->slug)->toBe('RaceD3')
        ->and($generator->calls)->toBe(2)
        ->and(QrCode::query()->count())->toBe(2);
});

it('gives up rather than looping forever when every attempt collides', function () {
    $owner = User::factory()->create();
    QrCode::factory()->create(['slug' => 'AlwaY2']);

    $generator = new class extends SlugGenerator
    {
        public int $calls = 0;

        public function make(): string
        {
            $this->calls++;

            return 'AlwaY2';
        }
    };

    expect(fn () => (new CreateQrCode($generator))($owner, [
        'type' => QrCodeType::Url,
        'destination' => ['url' => 'https://kodeqr.test'],
    ]))->toThrow(UniqueConstraintViolationException::class);

    // Three inserts, two regenerations. Three collisions in a row is a broken RNG,
    // not contention, and retrying past that is how a create endpoint hangs.
    expect($generator->calls)->toBe(3)
        ->and(QrCode::query()->count())->toBe(1);
});
