<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lote de importación: un archivo subido para una plantilla (ADR-0005). El archivo vive en el disco
 * `imports` (storage/app/imports, fuera de git: trae datos personales). `campus_id` solo en las
 * plantillas de un campus; `failure_reason` dice por qué un lote quedó `failed`. Un lote no se borra:
 * es el registro de qué se importó, quién y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('template');
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->foreignUuid('mapping_id')->nullable()->constrained('import_mappings');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('status')->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->text('failure_reason')->nullable();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->foreignUuid('applied_by')->nullable()->constrained('users');
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['template', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
