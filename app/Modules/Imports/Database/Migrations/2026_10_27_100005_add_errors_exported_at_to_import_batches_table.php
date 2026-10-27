<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo quedó listo el CSV de errores del lote (ExportBatchErrors, tarea 1.2). Lo escribe la cola al
 * terminar de validar y de aplicar, y vuelve a null al empezar a aplicar: el archivo de la validación
 * deja de valer hasta que se rehace. La pantalla decide con esta marca, no con la fecha del archivo, que
 * depende del reloj del sistema de archivos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->timestampTz('errors_exported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('errors_exported_at');
        });
    }
};
