<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_export_manifests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('dataset', 64);
            $table->string('sport', 16);
            $table->unsignedSmallInteger('season');
            $table->string('format', 16);
            $table->string('content_type', 120);
            $table->string('disk', 64);
            $table->text('object_key');
            $table->text('manifest_key');
            $table->text('uri');
            $table->char('sha256', 64);
            $table->char('manifest_sha256', 64);
            $table->char('schema_hash', 64);
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('size_bytes');
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_max_id');
            $table->timestamp('exported_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['dataset', 'sport', 'season', 'format', 'sha256'],
                'dataset_exports_partition_content_unique',
            );
            $table->index(
                ['dataset', 'sport', 'season', 'exported_at'],
                'dataset_exports_partition_exported_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_export_manifests');
    }
};
