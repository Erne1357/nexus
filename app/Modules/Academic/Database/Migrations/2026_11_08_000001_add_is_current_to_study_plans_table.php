<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan actual de una carrera: una carrera puede tener varias retículas activas, pero solo una es la actual, la que
 * se asigna a los alumnos de nuevo ingreso (D15, confirmado el 5 de noviembre de 2026). Un índice único parcial lo
 * garantiza entre los planes sin borrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->boolean('is_current')->default(false);
        });

        DB::statement('create unique index study_plans_career_current_unique on study_plans (career_id) where is_current and deleted_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists study_plans_career_current_unique');

        Schema::table('study_plans', function (Blueprint $table) {
            $table->dropColumn('is_current');
        });
    }
};
