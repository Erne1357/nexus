<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retícula histórica (D18, confirmado el 10 de noviembre de 2026): un plan en el que ningún alumno está inscrito en el
 * periodo actual. La migración del SII lo marca y lo deja tal cual viene del SII: lo que el SII trae sin semestre
 * espera en «Por validar» sin semestre sugerido y sin contar como trabajo pendiente de Servicios Escolares. Deja de
 * serlo solo, en la siguiente corrida, si un alumno vuelve a inscribirse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->boolean('is_historical')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->dropColumn('is_historical');
        });
    }
};
