<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los lotes de fotos en ZIP (Communication, tarea 1.35, CO-S5): `kind` (personal o alumnos), `status` (en_cola,
 * procesando, terminado o fallido), el ZIP en el disco `imports`, los totales y el reporte por archivo en `report`
 * (jsonb: `{file, key, outcome, message}` por archivo). Los procesa ProcessPhotoBatch en la cola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_batches', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('kind');
            $table->string('status');
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('applied')->default(0);
            $table->jsonb('report')->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['kind', 'created_at']);
        });

        DB::statement("alter table photo_batches add constraint photo_batches_kind_check check (kind in ('personal', 'alumnos'))");
        DB::statement("alter table photo_batches add constraint photo_batches_status_check check (status in ('en_cola', 'procesando', 'terminado', 'fallido'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_batches');
    }
};
