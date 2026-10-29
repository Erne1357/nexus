<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materia global, con el departamento que la imparte: el tronco común se comparte entre carreras, y su
 * posición y su seriación son de cada plan (decisión de modelado 2). Catálogo: soft deletes e `is_active`; el
 * código es la clave natural y sigue reservado aunque la materia se borre. La exportación del SII no trae el
 * tipo (`kind`): queda vacío hasta que se capture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignUuid('department_id')->constrained();
            $table->smallInteger('theory_hours');
            $table->smallInteger('practice_hours');
            $table->smallInteger('credits');
            $table->string('kind')->nullable();
            $table->boolean('needs_lab')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('department_id');
        });

        DB::statement('alter table subjects add constraint subjects_hours_check check (theory_hours >= 0 and practice_hours >= 0)');
        DB::statement('alter table subjects add constraint subjects_credits_check check (credits >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
