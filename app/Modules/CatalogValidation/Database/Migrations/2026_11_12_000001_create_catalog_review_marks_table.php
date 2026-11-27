<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de revisado de un registro en una revisión de catálogo (docs/dominio/modelo-de-datos.md, "CatalogValidation"):
 * el jefe marca cada registro y la revisión solo se valida con todos revisados (CV7, confirmado el 12 de noviembre de
 * 2026). `record_id` es el id del registro del catálogo de la revisión (un docente, un salón, una materia, un plan o una
 * carrera); el tipo lo da el catálogo, así que no lleva llave foránea. Una sola marca por revisión y registro, con quién
 * y cuándo. No es catálogo ni bitácora: quitar la marca la borra, y la bitácora de la revisión registra las dos cosas.
 * Llave UUIDv7 (ADR-0014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_review_marks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('catalog_review_id')->constrained('catalog_reviews');
            $table->uuid('record_id');
            $table->foreignUuid('reviewed_by')->constrained('users');
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();

            $table->unique(['catalog_review_id', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_review_marks');
    }
};
