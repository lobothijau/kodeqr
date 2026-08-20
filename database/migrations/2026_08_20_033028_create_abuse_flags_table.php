<?php

declare(strict_types=1);

use App\Enums\AbuseSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_flags', function (Blueprint $table) {
            $table->id();
            // Nullable: a threat verdict on create happens before any row exists, and
            // a public report (M1-T7) may name a slug that never existed. The url is
            // the record either way.
            $table->foreignUlid('qr_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 2048);
            $table->enum('source', AbuseSource::values());
            $table->string('threat_type', 64)->nullable();
            $table->timestamps();

            $table->index(['source', 'created_at']);
            $table->index('qr_code_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_flags');
    }
};
