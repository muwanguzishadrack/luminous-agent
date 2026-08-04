<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `/settings/whatsapp` renders the display-name review state next to — never
 * under — the two-step verification state, so `name_status` needs a column of
 * its own (docs/reference/whatsapp-cloud-api.md §5, docs/modules/m0 §7).
 * `last_synced_at` backs the "Last synced …" label on the refresh control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            // APPROVED|AVAILABLE_WITHOUT_REVIEW|DECLINED|EXPIRED|PENDING_REVIEW|NONE
            $table->string('name_status')->default('NONE');
            $table->timestampTz('last_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['name_status', 'last_synced_at']);
        });
    }
};
