<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los registros del catálogo que un lote desactivó por no venir en el archivo (tarea 1.3): el detalle
 * por registro de "desactivar faltantes", como `import_rows` lo es de lo que se escribió, porque la
 * bitácora guarda una sola entrada por lote (reglas-de-negocio.md, "Reglas de datos"). Con él se sabe
 * qué reactivar a mano si el archivo venía incompleto. Solo se escribe una vez: no lleva `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_deactivations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('batch_id')->constrained('import_batches');
            $table->uuidMorphs('target');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['batch_id', 'target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_deactivations');
    }
};
