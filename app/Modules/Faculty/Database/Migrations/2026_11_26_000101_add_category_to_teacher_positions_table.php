<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clave de categoría de la plaza (`E3817`: E3, jornada 8 = tiempo completo, categoría 17 = titular C), tal como la trae
 * el SII. Con ella la regla PL-01 saca las horas frente a grupo de cada plaza. Nulo si no se conoce (expand-safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_positions', function (Blueprint $table) {
            $table->string('category', 8)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_positions', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
