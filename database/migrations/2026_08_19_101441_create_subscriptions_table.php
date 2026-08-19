<?php

declare(strict_types=1);

use App\Enums\Package;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Unique: one row per user, mutated in place. M3-T2 stacks top-ups onto
            // the same row via Subscription::extend() rather than appending history.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // purchasableValues() excludes free and lapsed: both are answered by
            // the presence and dates of this row, never stored as a plan value.
            $table->enum('plan', Plan::purchasableValues());
            $table->enum('package', Package::values());
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', SubscriptionStatus::values())
                ->default(SubscriptionStatus::Active->value);
            $table->timestamps();

            // The nightly expiry sweep and the reminder emails scan on this.
            $table->index('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
