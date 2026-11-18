<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ficha de aceptación de una corrida (tarea 6.10; SV-05; revisión del Riel A en #594): RequestApplySolverRun la
 * pone al pasar la corrida a `applying` y se la da a ApplySolverRunJob. Un job viejo que encuentra la corrida otra vez
 * en `applying` (porque se liberó por vieja y se volvió a pedir) trae otra ficha y no la aplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solver_runs', function (Blueprint $table) {
            $table->uuid('apply_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('solver_runs', function (Blueprint $table) {
            $table->dropColumn('apply_token');
        });
    }
};
