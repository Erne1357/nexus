<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de cambio de un grupo (docs/dominio/modelo-de-datos.md, `change_requests`; tarea 3.11).
 *
 * - No es catálogo: sin borrado lógico, y las filas nunca se borran (la solicitud es historia del grupo).
 * - `term_id`, `campus_id` y `department_id` se copian del grupo para consultas y policies.
 * - `teacher_id` es el docente que pide; nulo cuando pide un coordinador.
 * - Una solicitud abierta (pendiente, o aprobada sin aplicar) por docente y grupo: índice único parcial
 *   (confirmado el 7 de noviembre de 2026). El coordinador no tiene ese límite (teacher_id nulo).
 * - El motivo no va en blanco, y rechazar exige un comentario con texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('department_id')->constrained();
            $table->foreignUuid('group_id')->constrained();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('teacher_id')->nullable()->constrained();
            $table->string('kind');
            $table->jsonb('current');
            $table->jsonb('proposed');
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignUuid('applied_by')->nullable()->constrained('users');
            $table->timestampTz('applied_at')->nullable();
            $table->jsonb('impact')->nullable();
            $table->timestampsTz();

            $table->index(['term_id', 'campus_id', 'department_id', 'status']);
            $table->index('group_id');
        });

        DB::statement("alter table change_requests add constraint change_requests_kind_check check (kind in ('schedule', 'room'))");
        DB::statement("alter table change_requests add constraint change_requests_status_check check (status in ('pending', 'approved', 'rejected', 'applied'))");
        DB::statement("alter table change_requests add constraint change_requests_reason_check check (btrim(reason) <> '')");
        DB::statement("alter table change_requests add constraint change_requests_rejection_comment_check check (status <> 'rejected' or (decision_comment is not null and btrim(decision_comment) <> ''))");
        DB::statement("create unique index change_requests_one_open on change_requests (teacher_id, group_id) where status in ('pending', 'approved') and teacher_id is not null");
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};
