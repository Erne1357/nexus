<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los salones sin dueño que una revisión de salones reclamó para su departamento (CV16, confirmado el 20 de noviembre
 * de 2026; docs/dominio/modelo-de-datos.md, "CatalogValidation"). Sirve para recordar qué reclamó cada revisión: solo eso
 * puede soltar el jefe, y solo mientras la revisión no esté validada. Soltar no borra la fila: llena `released_at`, y
 * la revisión puede volver a reclamar el mismo salón (otra fila). Solo un reclamo vigente por revisión y salón (índice
 * único parcial sobre los no soltados). Llave UUIDv7 (ADR-0014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_review_room_claims', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('catalog_review_id')->constrained('catalog_reviews');
            $table->foreignUuid('room_id')->constrained('rooms');
            $table->foreignUuid('claimed_by')->constrained('users');
            $table->timestampTz('claimed_at');
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('create unique index catalog_review_room_claims_active_unique on catalog_review_room_claims (catalog_review_id, room_id) where released_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_review_room_claims');
    }
};
