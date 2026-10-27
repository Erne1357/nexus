<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros editables sin despliegue. Una clave aparece una sola vez por alcance; `scope_id`
 * nulo (alcance global) cuenta como un valor más para la unicidad. Solo el alcance global va sin
 * `scope_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('key');
            $table->jsonb('value');
            $table->timestampsTz();

            $table->unique(['scope', 'scope_id', 'key'])->nullsNotDistinct();
        });

        DB::statement("alter table settings add constraint settings_scope_id_matches_scope check ((scope = 'global') = (scope_id is null))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
