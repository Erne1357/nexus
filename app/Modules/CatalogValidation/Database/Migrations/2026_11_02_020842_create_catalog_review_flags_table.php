<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Observaciones del jefe sobre registros concretos de una revisión de catálogo (docs/dominio/modelo-de-datos.md,
 * "CatalogValidation"). No bloquean validar (CV4, confirmado el 2 de noviembre de 2026); se resuelven con quién y
 * cuándo. CHECK del tipo (enum ReviewFlag), del comentario, obligatorio en una observación «otra» (CV-S2,
 * supuesto por confirmar), y de la resolución, que lleva las dos columnas o ninguna. No es catálogo: sin `is_active` ni
 * borrado lógico, porque una observación no se quita, se resuelve. Llave UUIDv7 (ADR-0014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_review_flags', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('review_id')->constrained('catalog_reviews');
            $table->uuidMorphs('flaggable');
            $table->string('flag');
            $table->text('comment')->nullable();
            $table->foreignUuid('flagged_by')->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('review_id');
        });

        DB::statement("alter table catalog_review_flags add constraint catalog_review_flags_flag_check check (flag in ('inactive', 'wrong_capacity', 'duplicate', 'missing_data', 'other'))");
        DB::statement("alter table catalog_review_flags add constraint catalog_review_flags_comment_check check (flag <> 'other' or btrim(coalesce(comment, '')) <> '')");
        DB::statement('alter table catalog_review_flags add constraint catalog_review_flags_resolved_check check ((resolved_at is null) = (resolved_by is null))');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_review_flags');
    }
};
