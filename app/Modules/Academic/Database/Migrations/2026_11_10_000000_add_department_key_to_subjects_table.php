<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Único sobre (`id`, `department_id`) en `subjects`, para que una llave compuesta exija que el departamento de un
 * grupo sea el de su materia (`groups`, tarea 3.1) y lo siga si la materia cambia de departamento. `id` ya es único:
 * el índice solo existe como destino de la llave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unique(['id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['id', 'department_id']);
        });
    }
};
