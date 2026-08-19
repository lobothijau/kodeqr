<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AggregateDimension;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $qr_code_id
 * @property Carbon $date
 * @property AggregateDimension $dim
 * @property string $key
 * @property int $count
 */
#[Fillable(['qr_code_id', 'date', 'dim', 'key', 'count'])]
class ScanDimAggregate extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'dim' => AggregateDimension::class,
            'count' => 'integer',
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
