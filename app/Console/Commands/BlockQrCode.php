<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AbuseSource;
use App\Enums\QrCodeStatus;
use App\Models\AbuseFlag;
use App\Models\QrCode;
use Illuminate\Console\Command;

/**
 * The kill switch, and until there is an admin UI it is the only one.
 *
 * Deliberately blunt: one slug, one status flip, no confirmation prompt. The whole
 * point is that somebody woken at 2am by an abuse report can stop a scam inside a
 * minute without reading anything first.
 */
class BlockQrCode extends Command
{
    protected $signature = 'qr:block {slug : The 6-8 character slug from the /x/ link} {--unblock : Restore the code to active}';

    protected $description = 'Block or unblock a QR code by slug, taking effect on the next scan';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $code = QrCode::where('slug', $slug)->first();

        if ($code === null) {
            // No enumeration concern here — this runs on a machine whose operator can
            // already read the table.
            $this->error("No QR code with slug [{$slug}].");

            return self::FAILURE;
        }

        $unblock = (bool) $this->option('unblock');

        // Blocking something already blocked is a no-op, not a second block. Writing
        // another audit row would record `previous_status = blocked`, and the next
        // --unblock would read that back and "restore" the code to blocked — wedging
        // the kill switch on while printing that it had been released. Two operators
        // reacting to one report is the ordinary case, not the exotic one.
        if (! $unblock && $code->status === QrCodeStatus::Blocked) {
            $this->info("[{$slug}] is already blocked. Nothing to do.");

            return self::SUCCESS;
        }

        if ($unblock && $code->status !== QrCodeStatus::Blocked) {
            $this->warn("[{$slug}] is {$code->status->value}, not blocked — nothing to do.");

            return self::SUCCESS;
        }

        $previous = $code->status;

        // Unblocking restores what the code was BEFORE the block, read back off the
        // audit row. Defaulting to `active` was wrong in one specific and plausible
        // way: block a code its owner had paused, unblock it later, and the owner's
        // own decision to take it down is silently reversed by us. Nothing re-derives
        // `paused` — it exists only because a person chose it.
        $code->status = $unblock
            ? ($this->statusBeforeBlock($code) ?? QrCodeStatus::Active)
            : QrCodeStatus::Blocked;

        // The observer forgets `qr:v2:{slug}` on save, which is what makes this take
        // effect on the next scan rather than up to six hours later. It is asserted in
        // the command's test, not just the observer's: this is the one place where a
        // stale cache means a live scam.
        $code->save();

        if (! $unblock) {
            // An audit row for every block, even when the block came from a phone call
            // rather than a report. A status with no recorded reason is a status the
            // next operator cannot safely undo.
            AbuseFlag::create([
                'qr_code_id' => $code->id,
                'url' => $code->destination['dest_url'] ?? route('redirect.show', ['slug' => $slug]),
                'source' => AbuseSource::Admin,
                'previous_status' => $previous,
            ]);
        }

        // Reports the status it actually landed on. "Restored to active" was printed
        // unconditionally, so an operator restoring a paused code was told scanning
        // worked again while /x/ went on answering 410.
        $this->info($unblock
            ? "[{$slug}] restored to {$code->status->value}. Cache cleared; the next scan sees it."
            : "[{$slug}] blocked. Cache cleared; the next scan sees the blocked page.");

        return self::SUCCESS;
    }

    /**
     * What the most recent admin block found the code in.
     *
     * Null when there is no such row — a code blocked before this column existed, or
     * one whose flags were pruned. `active` is the right guess then: it is the only
     * state that cannot be a mistake in the direction that matters, since an owner
     * who wanted it paused can pause it again, while a scam left blocked by accident
     * is a support ticket nobody opens.
     */
    private function statusBeforeBlock(QrCode $code): ?QrCodeStatus
    {
        return AbuseFlag::where('qr_code_id', $code->id)
            ->where('source', AbuseSource::Admin)
            ->whereNotNull('previous_status')
            // Belt and braces with the repeat-block guard above: a `blocked` row from
            // before that guard existed must never be handed back as a restore target.
            ->where('previous_status', '!=', QrCodeStatus::Blocked)
            ->latest('id')
            ->first()?->previous_status;
    }
}
