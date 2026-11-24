<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcas de los avisos del reloj de la selección (tarea 1.33, diseño 6.3 y 6.9): cada aviso se manda una vez, así el
 * comando por minuto es idempotente aunque corra tarde o dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staffing_selection_entries', function (Blueprint $table) {
            $table->timestampTz('notified_soon_at')->nullable();
            $table->timestampTz('notified_start_at')->nullable();
            $table->timestampTz('notified_extra_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staffing_selection_entries', function (Blueprint $table) {
            $table->dropColumn(['notified_soon_at', 'notified_start_at', 'notified_extra_at']);
        });
    }
};
