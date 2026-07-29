<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_artifacts', function (Blueprint $table): void {
            $table->string('artifact_disk', 64)->nullable()->after('artifact_path');
            $table->text('artifact_object_key')->nullable()->after('artifact_disk');
            $table->text('artifact_uri')->nullable()->after('artifact_object_key');
            $table->unsignedBigInteger('artifact_size')->nullable()->after('artifact_hash');
            $table->string('artifact_content_type', 128)->nullable()->after('artifact_size');

            $table->string('dataset_path')->nullable()->after('dataset_hash');
            $table->string('dataset_disk', 64)->nullable()->after('dataset_path');
            $table->text('dataset_object_key')->nullable()->after('dataset_disk');
            $table->text('dataset_uri')->nullable()->after('dataset_object_key');
            $table->unsignedBigInteger('dataset_size')->nullable()->after('dataset_uri');
            $table->string('dataset_content_type', 128)->nullable()->after('dataset_size');

            $table->string('evaluation_report_disk', 64)->nullable()->after('evaluation_report_path');
            $table->text('evaluation_report_object_key')->nullable()->after('evaluation_report_disk');
            $table->text('evaluation_report_uri')->nullable()->after('evaluation_report_object_key');
            $table->unsignedBigInteger('evaluation_report_size')->nullable()->after('evaluation_report_hash');
            $table->string('evaluation_report_content_type', 128)->nullable()->after('evaluation_report_size');
        });
    }

    public function down(): void
    {
        Schema::table('model_artifacts', function (Blueprint $table): void {
            $table->dropColumn([
                'artifact_disk',
                'artifact_object_key',
                'artifact_uri',
                'artifact_size',
                'artifact_content_type',
                'dataset_path',
                'dataset_disk',
                'dataset_object_key',
                'dataset_uri',
                'dataset_size',
                'dataset_content_type',
                'evaluation_report_disk',
                'evaluation_report_object_key',
                'evaluation_report_uri',
                'evaluation_report_size',
                'evaluation_report_content_type',
            ]);
        });
    }
};
