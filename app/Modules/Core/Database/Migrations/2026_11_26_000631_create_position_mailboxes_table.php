<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El buzón institucional de un puesto (CE-02, tarea 1.63; ADR-0039): a dónde va el correo de un aviso que compete al
 * puesto y no a la persona («jefatura_syc@cdjuarez.tecnm.mx»). Un puesto es un rol más su alcance (ADR-0018): unidad,
 * carrera, campus y modalidad; una columna nula es comodín. Es catálogo: `is_active` y borrado lógico, nunca borrado
 * físico (se desactiva). Solo un buzón activo por rol y alcance (índice único parcial con las nulas como un valor). La
 * FK de `career_id` la agrega Academic, porque Core no depende de Academic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_mailboxes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('role', 40);
            $table->foreignUuid('org_unit_id')->nullable()->constrained('org_units');
            $table->uuid('career_id')->nullable();
            $table->foreignUuid('campus_id')->nullable()->constrained('campuses');
            $table->foreignUuid('modality_id')->nullable()->constrained('modalities');
            $table->string('email');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('role');
        });

        DB::statement("CREATE UNIQUE INDEX position_mailboxes_one_active ON position_mailboxes (role, coalesce(org_unit_id, '00000000-0000-0000-0000-000000000000'::uuid), coalesce(career_id, '00000000-0000-0000-0000-000000000000'::uuid), coalesce(campus_id, '00000000-0000-0000-0000-000000000000'::uuid), coalesce(modality_id, '00000000-0000-0000-0000-000000000000'::uuid)) WHERE is_active AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('position_mailboxes');
    }
};
