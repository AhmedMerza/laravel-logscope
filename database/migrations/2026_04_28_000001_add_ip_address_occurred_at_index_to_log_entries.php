<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a composite (ip_address, occurred_at) index to mirror the existing
     * (trace_id, occurred_at) and (user_id, occurred_at) indexes. Without it,
     * `WHERE ip_address = ? ORDER BY occurred_at DESC LIMIT 51` queries pick
     * the (status, occurred_at) plan and scan a large slice of the table
     * before applying the ip_address filter.
     */
    public function up(): void
    {
        Schema::table('log_entries', function (Blueprint $table) {
            $table->index(['ip_address', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('log_entries', function (Blueprint $table) {
            $table->dropIndex(['ip_address', 'occurred_at']);
        });
    }
};
