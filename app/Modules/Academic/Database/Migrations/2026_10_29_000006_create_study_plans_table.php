<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de estudios: una retícula por versión de una carrera. Los inactivos se conservan porque hay alumnos
 * cursándolos. Catálogo: soft deletes e `is_active`; el código es la clave natural y sigue reservado aunque
 * el plan se borre. La exportación del SII no trae `semesters_count`, `total_credits` ni `valid_from`: quedan
 * vacíos hasta que se capturen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_plans', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('career_id')->constrained();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('version');
            $table->smallInteger('semesters_count')->nullable();
            $table->smallInteger('total_credits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('career_id');
        });

        DB::statement('alter table study_plans add constraint study_plans_semesters_count_check check (semesters_count > 0)');
        DB::statement('alter table study_plans add constraint study_plans_total_credits_check check (total_credits >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_plans');
    }
};
