<?php

declare(strict_types=1);

use App\Enums\AggregateDimension;
use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Enums\ScanDevice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Case-SENSITIVE by force: the slug alphabet is mixed-case, but the
            // mysql connection defaults to utf8mb4_unicode_ci. Under a CI collation
            // the 54-char alphabet folds to 31 distinct chars, and — far worse —
            // /x/ABC12X would resolve the row for slug aBc12X while caching under a
            // different Valkey key, so the observer's Cache::forget() would miss it
            // and serve a stale destination forever. Ignored by SQLite (binary TEXT).
            $slug = $table->string('slug', 8);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $slug->charset('ascii')->collation('ascii_bin');
            }

            $slug->unique();
            $table->enum('type', QrCodeType::values());
            $table->json('destination');
            $table->json('style')->nullable();
            $table->enum('status', QrCodeStatus::values())->default(QrCodeStatus::Active->value);
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });

        // Append-only: no updated_at. Idempotency for the M1-T4 batch processor
        // rides on the event_uuid unique index (constraint 9).
        Schema::create('scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('qr_code_id')->constrained()->cascadeOnDelete();
            // char(26) == Str::ulid(). NOT Str::uuid() (36 chars): under mysql
            // strict mode an over-long value throws, and M1-T4 requeues the chunk.
            $table->char('event_uuid', 26)->unique();
            $table->dateTime('occurred_at')->index();
            $table->char('ip_hash', 64);
            $table->char('country', 2)->nullable();
            $table->string('region', 64)->nullable();
            $table->string('city', 64)->nullable();
            $table->enum('device', ScanDevice::values());
            $table->string('os', 32)->nullable();
            $table->string('browser', 32)->nullable();
            $table->boolean('is_unique');
            $table->boolean('is_bot')->default(false);
            $table->string('referer', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['qr_code_id', 'occurred_at']);
        });

        Schema::create('scan_daily_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('qr_code_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('scans')->default(0);
            $table->unsignedInteger('uniques')->default(0);
            $table->timestamps();

            $table->unique(['qr_code_id', 'date']);
        });

        Schema::create('scan_dim_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('qr_code_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('dim', AggregateDimension::values());
            $table->string('key', 64);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['qr_code_id', 'date', 'dim', 'key'], 'scan_dim_aggregates_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_dim_aggregates');
        Schema::dropIfExists('scan_daily_aggregates');
        Schema::dropIfExists('scan_events');
        Schema::dropIfExists('qr_codes');
    }
};
