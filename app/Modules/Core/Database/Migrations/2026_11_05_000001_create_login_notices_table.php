<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de la pantalla de acceso (tarea 1.17, LG-01): el carrusel de la izquierda del login. Cada aviso
 * lleva un título, una foto opcional (ruta en el disco público) y un enlace opcional, con orden y
 * vigencia. Es catálogo: borrado lógico e `is_active`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_notices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('title', 120);
            $table->string('image_path')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['is_active', 'sort_order']);
        });

        DB::statement('ALTER TABLE login_notices ADD CONSTRAINT login_notices_window_check CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('login_notices');
    }
};
