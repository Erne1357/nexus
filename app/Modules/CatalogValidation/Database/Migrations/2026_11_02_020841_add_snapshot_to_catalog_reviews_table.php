<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto de una revisión al validarla (docs/dominio/modelo-de-datos.md, "CatalogValidation"): la lista de
 * `{id, code, name}` de los registros activos del catálogo en ese momento. Las altas y bajas del periodo
 * siguiente se comparan contra la foto de la última validación anterior del mismo departamento, catálogo y
 * campus; en el primer periodo no hay foto (CV3, confirmado el 2 de noviembre de 2026). Nula hasta la primera
 * validación; al reabrir se conserva hasta la siguiente (CV-S6, supuesto por confirmar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_reviews', function (Blueprint $table) {
            $table->jsonb('snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_reviews', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
