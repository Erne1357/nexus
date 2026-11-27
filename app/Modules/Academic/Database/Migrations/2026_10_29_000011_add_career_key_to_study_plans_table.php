<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Único sobre (`id`, `career_id`) en `study_plans`, para que una llave compuesta exija que el plan de un alumno
 * sea de su carrera (`students`, tarea 1.4). `id` ya es único: el índice solo existe como destino de la llave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->unique(['id', 'career_id']);
        });
    }

    public function down(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->dropUnique(['id', 'career_id']);
        });
    }
};
