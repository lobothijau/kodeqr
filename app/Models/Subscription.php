<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Package;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prepaid package. One row per user, mutated in place — no auto-renewal, no
 * grace period. See documentation/billing.md.
 *
 * @property string $id
 * @property int $user_id
 * @property Plan $plan
 * @property Package $package
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
// `status` is not fillable: the nightly expiry sweep owns the transitions, and
// extend() owns the only other write to it.
#[Fillable(['user_id', 'plan', 'package', 'starts_at', 'ends_at'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => Plan::class,
            'package' => Package::class,
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Derived from ends_at, never from the status column, so a lapse or a renewal
     * takes effect the instant it happens rather than at the next sweep.
     */
    public function isActive(): bool
    {
        return now()->lessThan($this->ends_at);
    }

    /**
     * The single home of the stacking formula: ends_at = max(now, ends_at) + package.
     *
     * M3-T2's webhook calls this and must never restate it — top-ups extend, they
     * never overwrite. A paid extension always means active, so this owns the status
     * write too rather than leaving it for the caller to remember.
     */
    public function extend(Package $package): void
    {
        $from = $this->ends_at->greaterThan(now()) ? $this->ends_at : now();

        $this->ends_at = $package->addTo($from);
        $this->status = SubscriptionStatus::Active;

        $this->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
