<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propuesta de carrera (docs/dominio/modelo-de-datos.md, "AcademicLoad"): lo que el coordinador pide abrir
 * para su carrera en un campus y un periodo, así que hay una por periodo, campus y carrera. Nace en `draft`;
 * el CHECK acepta solo sus cuatro estados. Quién y cuándo la envió quedan nulos hasta el primer envío y
 * guardan el último. No es catálogo: no lleva `is_active` ni borrado lógico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('career_id')->constrained();
            $table->string('status')->default('draft');
            $table->foreignUuid('submitted_by')->nullable()->constrained('users');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['term_id', 'campus_id', 'career_id']);
            $table->index('campus_id');
            $table->index('career_id');
        });

        DB::statement("alter table career_proposals add constraint career_proposals_status_check check (status in ('draft', 'submitted', 'returned', 'accepted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('career_proposals');
    }
};
