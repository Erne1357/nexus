<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las corridas del motor de horarios (tarea 6.2; docs/dominio/modelo-de-datos.md, «Solver»): de la carga de un
 * departamento en una modalidad o de los grupos de una carrera, sin campus (una corrida abarca todos los campus de la
 * carga, decisión del usuario del 17 de noviembre de 2026). La entrada y la salida del contrato van en la fila o, si son
 * grandes, en un archivo bajo `storage/solver` del que la fila guarda la ruta: así cada corrida se puede reproducir.
 * El alcance pide exactamente su llave (CHECK), y una carga tiene a lo más una corrida en cola o corriendo (índice único
 * parcial; revisión del Riel C). Mientras las tablas no se muevan a su esquema (ADR-0022), nace en
 * `public`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solver_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('modality_id')->constrained();
            $table->string('scope_kind');
            $table->foreignUuid('department_id')->nullable()->constrained();
            $table->foreignUuid('career_id')->nullable()->constrained();
            $table->string('engine', 40);
            $table->string('status')->default('queued');
            $table->jsonb('input')->nullable();
            $table->string('input_path')->nullable();
            $table->jsonb('output')->nullable();
            $table->string('output_path')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignUuid('applied_by')->nullable()->constrained('users');
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['term_id', 'modality_id', 'department_id']);
            $table->index(['term_id', 'modality_id', 'career_id']);
        });

        DB::statement("alter table solver_runs add constraint solver_runs_scope_kind_check check (scope_kind in ('department', 'career'))");
        DB::statement("alter table solver_runs add constraint solver_runs_status_check check (status in ('queued', 'running', 'done', 'failed', 'applied'))");
        DB::statement("alter table solver_runs add constraint solver_runs_scope_key_check check ((scope_kind = 'department' and department_id is not null and career_id is null) or (scope_kind = 'career' and career_id is not null and department_id is null))");
        DB::statement("create unique index solver_runs_one_active_department_run on solver_runs (term_id, modality_id, department_id) where scope_kind = 'department' and status in ('queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('solver_runs');
    }
};
