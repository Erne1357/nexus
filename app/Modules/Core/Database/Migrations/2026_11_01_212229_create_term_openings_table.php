<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En qué campus se abrió cada periodo (D1, confirmado el 1 de noviembre de 2026): el periodo se marca
 * como actual una vez para todo el Tec y, aparte, se abre en cada campus que va a capturar. Una
 * apertura por periodo y campus, con quién y cuándo la hizo (OpenTerm la guarda la primera vez; volver
 * a abrir no la cambia). No es catálogo: no lleva `is_active` ni borrado lógico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_openings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('opened_by')->constrained('users');
            $table->timestampTz('opened_at');
            $table->timestampsTz();

            $table->unique(['term_id', 'campus_id']);
            $table->index('campus_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_openings');
    }
};
