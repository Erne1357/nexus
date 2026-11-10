<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clave del SII con la que el alumno cursó la materia (`historia_alumno.materia`): la migración lleva cada clave a
 * la materia de la retícula del alumno, y así el kárdex conserva cuál fue la original. Vacía en lo que no viene del
 * SII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kardex_records', function (Blueprint $table) {
            $table->string('sii_subject_code', 20)->nullable()->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('kardex_records', function (Blueprint $table) {
            $table->dropColumn('sii_subject_code');
        });
    }
};
