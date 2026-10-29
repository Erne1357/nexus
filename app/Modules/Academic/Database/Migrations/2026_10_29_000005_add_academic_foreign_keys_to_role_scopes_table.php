<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las llaves foráneas de `role_scopes.department_id` y `career_id`, que Core dejó sin FK porque no depende
 * de Academic (ver su migración): un alcance ya no puede apuntar a un departamento o una carrera que no
 * existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_scopes', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments');
            $table->foreign('career_id')->references('id')->on('careers');
        });
    }

    public function down(): void
    {
        Schema::table('role_scopes', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['career_id']);
        });
    }
};
