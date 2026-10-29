<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Desactivar faltantes" al aplicar un lote (tarea 1.3, reglas-de-negocio.md, "Reglas de datos"):
 * `deactivate_missing` es lo que eligió el admin para ese lote y `deactivated_rows`, cuántos registros
 * del catálogo desactivó por no venir en el archivo. Cuáles fueron queda en `import_deactivations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->boolean('deactivate_missing')->default(false);
            $table->unsignedInteger('deactivated_rows')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['deactivate_missing', 'deactivated_rows']);
        });
    }
};
