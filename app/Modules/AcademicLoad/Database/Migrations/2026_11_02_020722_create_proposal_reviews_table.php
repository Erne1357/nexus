<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La parte de cada departamento en una propuesta de carrera (docs/dominio/modelo-de-datos.md,
 * `proposal_reviews`): todo lo que la carrera le pide a ese departamento, que el departamento acepta o regresa
 * con comentario (confirmado el 2 de noviembre de 2026). Una por propuesta y departamento; la crea el envío y
 * nace `pending`. El CHECK limita el estado a sus tres valores y exige comentario no vacío en una regresada.
 * Quién y cuándo decidió quedan nulos mientras está pendiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('career_proposal_id')->constrained();
            $table->foreignUuid('department_id')->constrained();
            $table->string('status')->default('pending');
            $table->text('comment')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['career_proposal_id', 'department_id']);
            $table->index('department_id');
        });

        DB::statement("alter table proposal_reviews add constraint proposal_reviews_status_check check (status in ('pending', 'accepted', 'returned'))");
        DB::statement("alter table proposal_reviews add constraint proposal_reviews_returned_comment_check check (status <> 'returned' or btrim(coalesce(comment, '')) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_reviews');
    }
};
