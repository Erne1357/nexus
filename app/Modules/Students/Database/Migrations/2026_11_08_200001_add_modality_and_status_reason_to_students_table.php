<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad del alumno (presencial, distancia o mixta: es del alumno y no de la carrera, D15) y motivo del SII
 * (BD1, BT1, TIT, TRA…), que Nexus guarda junto a su propio estatus de cuatro valores (D12). Ambos pueden faltar.
 * Confirmado el 5 de noviembre de 2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('modality')->nullable();
            $table->string('status_reason', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['modality', 'status_reason']);
        });
    }
};
