<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AbuseSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Why a URL was refused or a code was blocked, from any source: the automated threat
 * check, a public report (M1-T7), or an admin.
 *
 * @property int $id
 * @property string|null $qr_code_id
 * @property string $url
 * @property AbuseSource $source
 * @property string|null $threat_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['qr_code_id', 'url', 'source', 'threat_type'])]
class AbuseFlag extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source' => AbuseSource::class];
    }

    /**
     * @return BelongsTo<QrCode, $this>
     */
    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }
}
