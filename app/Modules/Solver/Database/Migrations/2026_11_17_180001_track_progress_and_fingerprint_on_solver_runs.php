<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que necesita correr una corrida en la cola (tarea 6.8; docs/dominio/modelo-de-datos.md, «Solver»):
 *
 * - `progress` (0 a 100, CHECK) y `best_score`, el avance y el mejor puntaje blando que el motor reporta mientras corre;
 * - `input_hash`, la huella de la entrada al lanzar (SV-02, InputFingerprint), para saber al aceptar si la carga cambió;
 * - `cancel_requested_at`, cuándo se pidió cancelar una corrida que ya corre (SV-03);
 * - los estados `discarded` (el jefe la descartó) y `cancelled` (se canceló antes de terminar).
 *
 * El índice único parcial de una corrida de departamento en curso (SV-01) sigue contando solo `queued` y `running`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solver_runs', function (Blueprint $table) {
            $table->smallInteger('progress')->default(0);
            $table->char('input_hash', 64)->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
        });

        DB::statement('alter table solver_runs add column best_score numeric null');
        DB::statement('alter table solver_runs add constraint solver_runs_progress_check check (progress between 0 and 100)');
        DB::statement('alter table solver_runs drop constraint solver_runs_status_check');
        DB::statement("alter table solver_runs add constraint solver_runs_status_check check (status in ('queued', 'running', 'done', 'failed', 'applied', 'discarded', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement("update solver_runs set status = 'done' where status = 'discarded'");
        DB::statement("update solver_runs set status = 'failed' where status = 'cancelled'");
        DB::statement('alter table solver_runs drop constraint solver_runs_status_check');
        DB::statement("alter table solver_runs add constraint solver_runs_status_check check (status in ('queued', 'running', 'done', 'failed', 'applied'))");

        Schema::table('solver_runs', function (Blueprint $table) {
            $table->dropColumn(['progress', 'best_score', 'input_hash', 'cancel_requested_at']);
        });
    }
};
