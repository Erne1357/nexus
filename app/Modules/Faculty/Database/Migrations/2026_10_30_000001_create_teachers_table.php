<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Docente. El número de empleado es la clave natural y sigue reservado aunque se borre; el usuario es
 * opcional y liga a un solo docente. El departamento y el campus los da la adscripción, no esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('employee_number')->unique();
            $table->foreignUuid('user_id')->nullable()->unique()->constrained();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('degree')->nullable();
            $table->date('hired_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("alter table teachers add constraint teachers_degree_check check (degree is null or degree in ('licenciatura', 'especialidad', 'maestria', 'doctorado'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
