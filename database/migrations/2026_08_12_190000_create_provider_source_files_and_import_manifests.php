<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_source_files', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('provider', 32);
            $table->string('dataset', 64);
            $table->char('sha256', 64);
            $table->string('disk', 64);
            $table->text('object_key');
            $table->text('uri');
            $table->string('original_filename');
            $table->string('content_type', 120)->nullable();
            $table->string('compression', 20)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'dataset', 'sha256'],
                'provider_source_files_content_unique',
            );
            $table->index(
                ['provider', 'dataset', 'created_at'],
                'provider_source_files_dataset_created_idx',
            );
        });

        Schema::create('provider_import_manifests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('provider_source_file_id')
                ->nullable()
                ->constrained('provider_source_files')
                ->nullOnDelete();
            $table->string('provider', 32);
            $table->string('dataset', 64);
            $table->string('status', 20);
            $table->json('options')->nullable();
            $table->unsignedBigInteger('rows_read')->default(0);
            $table->unsignedBigInteger('rows_imported')->default(0);
            $table->unsignedBigInteger('rows_skipped')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(
                ['provider', 'dataset', 'started_at'],
                'provider_import_manifests_dataset_started_idx',
            );
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_import_manifests');
        Schema::dropIfExists('provider_source_files');
    }
};
