<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La FK de `position_mailboxes.career_id` a `careers` (CE-02, tarea 1.63): Core crea la tabla sin ella porque no depende
 * de Academic, como hizo con `role_assignments.career_id`. La tabla es nueva y está vacía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('position_mailboxes', function (Blueprint $table) {
            $table->foreign('career_id')->references('id')->on('careers');
        });
    }

    public function down(): void
    {
        Schema::table('position_mailboxes', function (Blueprint $table) {
            $table->dropForeign(['career_id']);
        });
    }
};
