<?php

declare(strict_types=1);

use App\Enums\AbuseReason;
use App\Enums\QrCodeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abuse_flags', function (Blueprint $table) {
            // Nullable because a threat_check flag has no reporter and no reason —
            // these two columns belong to `source = report` rows only.
            $table->enum('reason', AbuseReason::values())->nullable()->after('threat_type');
            // Optional by design: requiring an address would cost reports from the
            // people least willing to give one, who are the ones being defrauded.
            $table->string('reporter_email')->nullable()->after('reason');
            // What the code was before an admin block, so `--unblock` can put it back
            // instead of guessing. Guessing meant `active`, which republished a code
            // its OWNER had paused — a different decision by a different person.
            $table->enum('previous_status', QrCodeStatus::values())->nullable()->after('reporter_email');
        });
    }

    public function down(): void
    {
        Schema::table('abuse_flags', function (Blueprint $table) {
            $table->dropColumn(['reason', 'reporter_email', 'previous_status']);
        });
    }
};
