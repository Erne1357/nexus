<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adscripción: el departamento y el campus de un docente (confirmado el 30 de octubre de 2026). Única por
 * docente, departamento y campus, también contra una borrada. A lo más una principal sin borrar por docente:
 * la del SII, para RH y reportes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_departments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('department_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['teacher_id', 'department_id', 'campus_id']);
            $table->index(['department_id', 'campus_id']);
            $table->index('campus_id');
        });

        DB::statement('create unique index teacher_departments_one_primary on teacher_departments (teacher_id) where is_primary and deleted_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_departments');
    }
};
