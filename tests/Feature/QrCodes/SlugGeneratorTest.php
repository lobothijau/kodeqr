<?php

declare(strict_types=1);

use App\Models\QrCode;
use App\Services\SlugGenerator;

it('generates 10,000 slugs without a practical collision', function () {
    $generator = new class extends SlugGenerator
    {
        public function draw(int $length): string
        {
            return $this->random($length);
        }
    };

    $slugs = [];

    for ($i = 0; $i < 10_000; $i++) {
        $slugs[] = $generator->draw(SlugGenerator::LENGTH);
    }

    // 54^6 is 2.45e10 and 10k slugs make ~5e7 pairs, so a run collides about 0.2% of
    // the time — asserting 10,000 distinct would flake once every ~490 CI runs. The
    // guarantee is not the entropy anyway: it is the UNIQUE index plus CreateQrCode's
    // retry. This asserts the entropy is in the right order of magnitude.
    $joined = implode('', $slugs);

    expect(count(array_unique($slugs)))->toBeGreaterThanOrEqual(9_999)
        ->and(array_unique(array_map(strlen(...), $slugs)))->toBe([SlugGenerator::LENGTH])
        // The excluded pairs are the whole point: someone reads these off a receipt
        // in bad light and types them into a phone.
        ->and(preg_match('/[01ILOilo]/', $joined))->toBe(0)
        ->and(str_replace(str_split(SlugGenerator::ALPHABET), '', $joined))->toBe('');
});

it('never hands out a slug that is already taken', function () {
    $taken = 'TaKen2';
    QrCode::factory()->create(['slug' => $taken]);

    $generator = new class($taken) extends SlugGenerator
    {
        public function __construct(private readonly string $taken) {}

        protected function random(int $length): string
        {
            return $length === SlugGenerator::LENGTH ? $this->taken : 'Fresh77';
        }
    };

    expect($generator->make())->toBe('Fresh77');
});

it('falls back to seven characters after five collisions', function () {
    $generator = new class extends SlugGenerator
    {
        public int $attempts = 0;

        protected function random(int $length): string
        {
            $this->attempts++;

            return substr('Taken23456', 0, $length);
        }
    };

    QrCode::factory()->create(['slug' => 'Taken2']);

    // Five six-char candidates, then the widened one — which the /x/{slug} route
    // constraint accepts at {6,8} precisely so this code is reachable once printed.
    expect($generator->make())->toBe('Taken23')
        ->and($generator->attempts)->toBe(6);
});

it('checks the widened slug for collisions too', function () {
    QrCode::factory()->create(['slug' => 'Taken2']);
    QrCode::factory()->create(['slug' => 'Taken23']);

    $generator = new class extends SlugGenerator
    {
        public int $calls = 0;

        protected function random(int $length): string
        {
            $this->calls++;

            // Every six-char candidate and the first widened one are already taken.
            return $this->calls <= 6 ? substr('Taken23456', 0, $length) : 'Fresh77';
        }
    };

    // Returning the widened slug unchecked would push the collision onto the UNIQUE
    // index, and on the observer path (factories, imports, tinker) there is no retry
    // waiting to catch it.
    expect($generator->make())->toBe('Fresh77');
});

it('refuses to hand back a slug it knows is taken', function () {
    QrCode::factory()->create(['slug' => 'Taken2']);
    QrCode::factory()->create(['slug' => 'Taken23']);

    $generator = new class extends SlugGenerator
    {
        protected function random(int $length): string
        {
            return substr('Taken23456', 0, $length);
        }
    };

    // Ten collisions across two lengths is a broken RNG, not bad luck.
    expect(fn () => $generator->make())->toThrow(RuntimeException::class);
});

it('treats a soft-deleted code as still owning its slug', function () {
    $code = QrCode::factory()->create(['slug' => 'GoneAA']);
    $code->delete();

    $generator = new class extends SlugGenerator
    {
        protected function random(int $length): string
        {
            return $length === SlugGenerator::LENGTH ? 'GoneAA' : 'Fresh77';
        }
    };

    // The paper it was printed on did not disappear. Reissuing the string would send
    // a stranger's scan to somebody else's destination.
    expect($generator->make())->toBe('Fresh77');
});
