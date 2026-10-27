<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance de un rol (ADR-0004): sobre qué campus, departamento o carrera lo ejerce un usuario y
 * desde cuándo. `valid_to` nulo es vigente sin fecha de fin.
 *
 * `department_id` y `career_id` van sin FK porque `departments` y `careers` son del módulo
 * Academic (fase 1) y Core no depende de nadie: Academic agrega esas FK en su propia migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('role');
            $table->foreignId('campus_id')->nullable()->constrained();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('career_id')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'role']);
            $table->index('campus_id');
            $table->index('department_id');
            $table->index('career_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_scopes');
    }
};
