<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Revisar proyección» de la propuesta de carrera (tarea 5.6, opción C elegida por el usuario el 17 de noviembre de 2026;
 * docs/dominio/modelo-de-datos.md, `proposal_items`): `reviewed_at` y `reviewed_by` marcan la última vez que el coordinador
 * revisó una fila que la pantalla le pide revisar (la proyección cambió, se aparta del sugerido, especialidad o sin
 * historia). Nulo es «por revisar». Se borran cuando cambia lo revisado: lo sugerido al recalcular (SyncProposalSuggestions)
 * o lo capturado al corregir (UpdateProposalItem). Supuesto por confirmar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
        });
    }
};
