<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto vigente del personal (CO-03, tarea 1.35): `users.photo_path` (la ruta de la foto normal en el disco
 * `staff_photos`) y `users.photo_updated_at` (timestamptz), nulas. Quitar la foto las deja nulas y el archivo se queda.
 * Solo agrega columnas nulas (expand/contract, ADR-0033): el release anterior las ignora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable();
            $table->timestampTz('photo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'photo_updated_at']);
        });
    }
};
