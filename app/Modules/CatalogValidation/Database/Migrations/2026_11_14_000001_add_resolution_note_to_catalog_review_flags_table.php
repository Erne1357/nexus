<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La nota de lo que se hizo al resolver una observación (CV10, confirmado el 14 de noviembre de 2026): opcional, va
 * con quién y cuándo la resolvió, así que un CHECK exige que una observación con nota esté resuelta
 * (docs/dominio/modelo-de-datos.md, "CatalogValidation").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_review_flags', function (Blueprint $table) {
            $table->text('resolution_note')->nullable()->after('resolved_by');
        });

        DB::statement('alter table catalog_review_flags add constraint catalog_review_flags_resolution_note_check check (resolution_note is null or resolved_at is not null)');
    }

    public function down(): void
    {
        DB::statement('alter table catalog_review_flags drop constraint if exists catalog_review_flags_resolution_note_check');

        Schema::table('catalog_review_flags', function (Blueprint $table) {
            $table->dropColumn('resolution_note');
        });
    }
};
