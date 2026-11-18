<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aceptar una corrida se procesa en la cola (tarea 6.10; SV-05): el estado `applying` («Aplicando») va entre `done` y
 * `applied`. Cuenta como «en curso» para la corrida única de un departamento (SV-01): mientras se aplica no se lanza otra,
 * así que el índice único parcial lo incluye.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table solver_runs drop constraint solver_runs_status_check');
        DB::statement("alter table solver_runs add constraint solver_runs_status_check check (status in ('queued', 'running', 'done', 'failed', 'applied', 'discarded', 'cancelled', 'applying'))");
        DB::statement('drop index solver_runs_one_active_department_run');
        DB::statement("create unique index solver_runs_one_active_department_run on solver_runs (term_id, modality_id, department_id) where scope_kind = 'department' and status in ('queued', 'running', 'applying')");
    }

    public function down(): void
    {
        DB::statement("update solver_runs set status = 'done' where status = 'applying'");
        DB::statement('drop index solver_runs_one_active_department_run');
        DB::statement("create unique index solver_runs_one_active_department_run on solver_runs (term_id, modality_id, department_id) where scope_kind = 'department' and status in ('queued', 'running')");
        DB::statement('alter table solver_runs drop constraint solver_runs_status_check');
        DB::statement("alter table solver_runs add constraint solver_runs_status_check check (status in ('queued', 'running', 'done', 'failed', 'applied', 'discarded', 'cancelled'))");
    }
};
