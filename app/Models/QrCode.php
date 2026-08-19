<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Observers\QrCodeObserver;
use Database\Factories\QrCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $slug
 * @property QrCodeType $type
 * @property array<string, mixed> $destination
 * @property array<string, mixed>|null $style
 * @property QrCodeStatus $status
 * @property int $scan_count
 * @property Carbon|null $last_scanned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
// `status` is deliberately NOT fillable: constraint 8 gives it to the billing and
// quota state machines, so an owner-supplied payload must never flip a blocked or
// over_quota code back to active.
#[ObservedBy(QrCodeObserver::class)]
#[Fillable(['user_id', 'slug', 'type', 'destination', 'style'])]
class QrCode extends Model
{
    /** @use HasFactory<QrCodeFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QrCodeType::class,
            'status' => QrCodeStatus::class,
            'destination' => 'array',
            'style' => 'array',
            'scan_count' => 'integer',
            'last_scanned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ScanEvent, $this>
     */
    public function scanEvents(): HasMany
    {
        return $this->hasMany(ScanEvent::class);
    }
}
