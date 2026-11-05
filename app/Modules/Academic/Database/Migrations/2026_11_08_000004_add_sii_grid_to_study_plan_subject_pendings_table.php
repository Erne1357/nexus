<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La retícula del SII de la que salió un pendiente (`carrera/retícula`, como «5/5»), para que Servicios Escolares la
 * consulte en el SII al asignarle semestre (D19). Nula si el pendiente no vino de la migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plan_subject_pendings', function (Blueprint $table) {
            $table->string('sii_grid', 50)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('study_plan_subject_pendings', function (Blueprint $table) {
            $table->dropColumn('sii_grid');
        });
    }
};
