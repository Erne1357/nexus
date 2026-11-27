<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fila de un lote (staging, ADR-0005): la fila tal como vino (`raw`), ya normalizada, sus errores y
 * advertencias por campo, y el registro que escribió (`target`). Es el detalle por registro de una
 * importación: la bitácora solo guarda una entrada por lote (reglas-de-negocio.md, "Reglas de
 * datos"). `row_number` es el del archivo y no se repite en el lote.
 *
 * `raw` es `json` y no `jsonb` para conservar las columnas en el orden del archivo. `key_hash` es un
 * hash de la clave natural normalizada: con él ValidateImportBatch encuentra en SQL las filas que
 * repiten clave, sin tener en memoria las claves de un kardex completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('batch_id')->constrained('import_batches');
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->jsonb('normalized');
            $table->jsonb('errors')->default('{}');
            $table->jsonb('warnings')->default('{}');
            $table->string('status');
            $table->char('key_hash', 64)->nullable();
            $table->nullableUuidMorphs('target');
            $table->timestampsTz();

            $table->unique(['batch_id', 'row_number']);
            $table->index(['batch_id', 'status']);
            $table->index(['batch_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
