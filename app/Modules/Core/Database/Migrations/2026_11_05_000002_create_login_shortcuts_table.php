<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atajos de la pantalla de acceso (tarea 1.17, LG-02): lo que se consulta sin iniciar sesión. Un atajo
 * es un enlace (`url`) o una descarga (`file_path` en el disco público), según `kind` (enum
 * `LoginShortcutKind`), con orden y vigencia. Es catálogo: borrado lógico e `is_active`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_shortcuts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('kind', 20);
            $table->string('title', 80);
            $table->string('description', 160)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['is_active', 'sort_order']);
        });

        DB::statement("ALTER TABLE login_shortcuts ADD CONSTRAINT login_shortcuts_kind_check CHECK ((kind = 'enlace' AND url IS NOT NULL) OR (kind = 'descarga' AND file_path IS NOT NULL))");
        // Solo enlaces web: la pantalla es pública y un `javascript:` correría en quien lo toque.
        DB::statement("ALTER TABLE login_shortcuts ADD CONSTRAINT login_shortcuts_url_check CHECK (url IS NULL OR url ~ '^https?://')");
        DB::statement('ALTER TABLE login_shortcuts ADD CONSTRAINT login_shortcuts_window_check CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('login_shortcuts');
    }
};
