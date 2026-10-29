<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alumno, como lo trae el SII: su carrera, su plan, su campus, su semestre y su estatus. Solo lectura en R1
 * y R2 (llega de importación). El número de control es la clave natural, único en todo el Tec y reservado
 * aunque el alumno se borre. `current_semester` puede faltar (un egresado) y `status` queda vacío si el SII
 * trae un estatus que Nexus no conoce (confirmado el 29 de octubre de 2026). Soft deletes e `is_active`,
 * como todo catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('control_number')->unique();
            $table->string('name');
            $table->foreignUuid('career_id')->constrained();
            $table->foreignUuid('study_plan_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->smallInteger('current_semester')->nullable();
            $table->string('status')->nullable();
            $table->foreignUuid('admitted_term_id')->nullable()->constrained('terms');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('career_id');
            $table->index('study_plan_id');
            $table->index('campus_id');
        });

        DB::statement('alter table students add constraint students_current_semester_check check (current_semester >= 1)');
        DB::statement("alter table students add constraint students_status_check check (status in ('activo', 'baja_temporal', 'baja_definitiva', 'egresado'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
