<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-16 (tarea 1.41): `prior_choice_at` marca el renglón de quien tiene elección previa (momento en que la cola lo
 * detectó). Es una columna nula, así que es compatible con el release anterior; el estado del renglón es `done`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staffing_selection_entries', function (Blueprint $table) {
            $table->timestampTz('prior_choice_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staffing_selection_entries', function (Blueprint $table) {
            $table->dropColumn('prior_choice_at');
        });
    }
};
