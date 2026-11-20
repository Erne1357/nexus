<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicación y contacto de un departamento (1.24.2), del mapa del campus. Solo texto: Academic no puede apuntar a
 * `buildings` (Facilities está a su derecha); `map_key` es `<polígono>|<oficina>` en el mapa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('location')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('map_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn(['location', 'phone', 'email', 'website', 'map_key']));
    }
};
