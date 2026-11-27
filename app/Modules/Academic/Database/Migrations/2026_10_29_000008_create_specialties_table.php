<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de especialidad de un plan. Catálogo: soft deletes e `is_active`; el código es único dentro de su
 * plan, también contra una especialidad borrada (confirmado el 29 de octubre de 2026). El único sobre
 * (`study_plan_id`, `id`) existe para que la retícula exija que la especialidad de una materia sea de su
 * mismo plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('study_plan_id')->constrained();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['study_plan_id', 'code']);
            $table->unique(['study_plan_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialties');
    }
};
