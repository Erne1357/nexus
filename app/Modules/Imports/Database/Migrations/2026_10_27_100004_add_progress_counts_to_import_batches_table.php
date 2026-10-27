<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas de avance de un lote, que la validación y la aplicación escriben bloque por bloque desde la
 * cola (tarea 1.2): `processed_rows` son las filas que lleva la etapa en curso (leídas al validar,
 * revisadas al aplicar) y la pantalla las muestra con `wire:poll`; `created_rows`, `updated_rows` y
 * `unchanged_rows` son lo que ya quedó escrito en el catálogo. Así un lote que se interrumpe a media
 * aplicación sigue diciendo cuánto escribió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('unchanged_rows')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['processed_rows', 'created_rows', 'updated_rows', 'unchanged_rows']);
        });
    }
};
