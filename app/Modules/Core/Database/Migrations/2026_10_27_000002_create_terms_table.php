<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Periodos escolares (`2027-1`). Hay un solo periodo actual para capturar carga
 * (docs/dominio/modelo-de-datos.md); un índice único parcial lo garantiza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->timestampsTz();
        });

        DB::statement('create unique index terms_single_current_unique on terms (is_current) where is_current');
    }

    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
