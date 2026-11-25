<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-16 ampliada (tarea 1.45): `prior_choice_confirm_required_at` es la foto al publicar de quien quedó con elección previa
 * arriba de la banda de su base y debe confirmar sus horas. Columna nula, compatible con el release anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staffing_selection_entries', function (Blueprint $table) {
            $table->timestampTz('prior_choice_confirm_required_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staffing_selection_entries', function (Blueprint $table) {
            $table->dropColumn('prior_choice_confirm_required_at');
        });
    }
};
