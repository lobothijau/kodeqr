<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanDevice;
use Database\Factories\ScanEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only. Never updated in place — see constraint 9.
 *
 * @property int $id
 * @property string $qr_code_id
 * @property string $event_uuid A 26-char ULID (Str::ulid()), not a UUID.
 * @property Carbon $occurred_at
 * @property string $ip_hash
 * @property string|null $country
 * @property string|null $region
 * @property string|null $city
 * @property ScanDevice $device
 * @property string|null $os
 * @property string|null $browser
 * @property bool $is_unique
 * @property bool $is_bot
 * @property string|null $referer
 * @property Carbon|null $created_at
 */
#[Fillable([
    'qr_code_id', 'event_uuid', 'occurred_at', 'ip_hash', 'country', 'region',
    'city', 'device', 'os', 'browser', 'is_unique', 'is_bot', 'referer',
])]
class ScanEvent extends Model
{
    /** @use HasFactory<ScanEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device' => ScanDevice::class,
            'occurred_at' => 'datetime',
            'is_unique' => 'boolean',
            'is_bot' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<QrCode, $this>
     */
    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }
}
