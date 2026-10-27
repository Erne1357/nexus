<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que necesita un lote para validarse y aplicarse en la cola (tarea 1.2). `run_id` es la corrida
 * que lo tomó, para que el fallo de un job duplicado no deje fallida la corrida que sigue viva. Las
 * cuentas se escriben bloque por bloque: `processed_rows` son las filas que lleva la etapa en curso
 * (leídas al validar, revisadas al aplicar), que la pantalla muestra con `wire:poll`; `created_rows`,
 * `updated_rows` y `unchanged_rows` son lo que ya quedó escrito en el catálogo, así que un lote que se
 * interrumpe a media aplicación sigue diciendo cuánto escribió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->uuid('run_id')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('unchanged_rows')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['run_id', 'processed_rows', 'created_rows', 'updated_rows', 'unchanged_rows']);
        });
    }
};
