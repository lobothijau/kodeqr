<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AbuseReason;
use App\Enums\AbuseSource;
use App\Enums\QrCodeStatus;
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
 * @property AbuseReason|null $reason
 * @property string|null $reporter_email
 * @property QrCodeStatus|null $previous_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['qr_code_id', 'url', 'source', 'threat_type', 'reason', 'reporter_email', 'previous_status'])]
class AbuseFlag extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AbuseSource::class,
            'reason' => AbuseReason::class,
            'previous_status' => QrCodeStatus::class,
        ];
    }

    /**
     * Trashed codes included.
     *
     * A report can name a soft-deleted code — the lookup that matched it used
     * `withTrashed()` — so a relation that hid them handed the operator "matches a
     * code: yes" with no destination, no status and no command to run. An email
     * nobody can act on is the same as no email.
     *
     * @return BelongsTo<QrCode, $this>
     */
    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class)->withTrashed();
    }
}
