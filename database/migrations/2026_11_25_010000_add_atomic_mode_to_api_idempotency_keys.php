<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            // Null retains legacy provenance; incomplete legacy writes cannot be safely resumed.
            $table->string('execution_mode', 32)->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve atomic idempotency provenance; roll back application code only after quiescence and reconciliation.');
    }
};
