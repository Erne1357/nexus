<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campus con su modalidad y su perfil de flujo en `settings` (ADR-0009). Catálogo: soft deletes
 * e `is_active`; el código es la clave natural y sigue reservado aunque el campus se borre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('code')->unique();
            $table->string('name');
            $table->string('modality');
            $table->jsonb('settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campuses');
    }
};
