<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_preseason_team_signals', function (Blueprint $table): void {
            $table->json('qb_season_usage_payload')->nullable();
            foreach (['incoming_transfer_value', 'outgoing_transfer_value', 'transfer_net_value',
                'transfer_qb_net_value', 'transfer_ol_net_value', 'transfer_dl_net_value',
                'transfer_wr_net_value', 'transfer_cb_net_value'] as $column) {
                $table->decimal($column, 8, 3)->nullable()->default(null)->change();
            }
        });
    }

    public function down(): void
    {
        // Preserve nullable transfer values: reverting them would invent zeros for unknown ratings.
        Schema::table('cfb_preseason_team_signals', fn (Blueprint $table) => $table->dropColumn('qb_season_usage_payload'));
    }
};
