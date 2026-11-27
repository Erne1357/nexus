<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salones. Llevan `campus_id` explícito (las tablas de la carga académica lo llevan) y una llave compuesta
 * (`building_id`, `campus_id`) a `buildings` impide que el salón quede en un campus distinto al de su edificio.
 * La clave es única por campus, también contra un salón borrado. `type` y `capacity` se revisan con CHECK;
 * `features` es una lista jsonb. La unicidad (`id`, `campus_id`) sirve a `subject_room_defaults`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('campus_id')->constrained();
            $table->uuid('building_id');
            $table->string('code');
            $table->string('name')->nullable();
            $table->string('type');
            $table->integer('capacity');
            $table->foreignUuid('department_id')->nullable()->constrained();
            $table->jsonb('features')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['campus_id', 'code']);
            $table->unique(['id', 'campus_id']);
            $table->index('building_id');
            $table->index('department_id');
            $table->foreign(['building_id', 'campus_id'])->references(['id', 'campus_id'])->on('buildings');
        });

        DB::statement("alter table rooms add constraint rooms_type_check check (type in ('aula', 'laboratorio', 'taller', 'sala_computo', 'auditorio'))");
        DB::statement('alter table rooms add constraint rooms_capacity_check check (capacity > 0)');
        DB::statement("alter table rooms add constraint rooms_features_check check (jsonb_typeof(features) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
