<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liga edificios y salones con el mapa del campus (1.24.2). `buildings.map_key` es el nombre del polígono del edificio
 * en el mapa; `rooms.map_key`, `<polígono>|<nombre del espacio>`. Nulos mientras no se crucen; únicos donde existen
 * (por campus en edificios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', fn (Blueprint $table) => $table->string('map_key')->nullable());
        Schema::table('rooms', fn (Blueprint $table) => $table->string('map_key')->nullable());

        DB::statement('create unique index buildings_campus_map_key_unique on buildings (campus_id, map_key) where map_key is not null');
        DB::statement('create unique index rooms_map_key_unique on rooms (map_key) where map_key is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists buildings_campus_map_key_unique');
        DB::statement('drop index if exists rooms_map_key_unique');
        Schema::table('buildings', fn (Blueprint $table) => $table->dropColumn('map_key'));
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn('map_key'));
    }
};
