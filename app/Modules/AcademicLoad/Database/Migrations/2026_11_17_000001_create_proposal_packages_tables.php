<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paquetes de primer semestre y nuevo ingreso (tarea 5.6b; docs/dominio/modelo-de-datos.md). Un paquete es un conjunto de
 * materias que se asigna a varios alumnos de nuevo ingreso: la composición es una por carrera y modalidad
 * (`proposal_packages`, `proposal_package_subjects`) y cada columna de la propuesta captura cuántos abre de cada paquete
 * (`proposal_package_campuses`); cada uno abre un grupo de cada una de sus materias. `proposal_intakes` guarda el nuevo
 * ingreso esperado por columna: `suggested_*` es lo que sugiere Nexus y `new_students` / `count` lo que vale; `captured_at`
 * nulo es «sin tocar» y sigue a lo sugerido. `campus_id` es nulo solo en una modalidad sin salones (MS5), así que la unicidad
 * por columna es `nulls not distinct`. Los paquetes son parte de una propuesta en borrador, no catálogo: se borran de verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_packages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('career_proposal_id')->constrained();
            $table->string('code', 20);
            $table->string('name')->nullable();
            $table->smallInteger('position');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['career_proposal_id', 'code']);
            $table->index(['career_proposal_id', 'position']);
        });

        DB::statement('alter table proposal_packages add constraint proposal_packages_position_check check (position >= 1)');

        Schema::create('proposal_package_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('proposal_package_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subject_id')->constrained();
            $table->timestampsTz();

            $table->unique(['proposal_package_id', 'subject_id']);
            $table->index('subject_id');
        });

        Schema::create('proposal_package_campuses', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('proposal_package_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->integer('suggested_count')->nullable();
            $table->integer('count')->default(0);
            $table->timestampTz('captured_at')->nullable();
            $table->foreignUuid('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('campus_id');
        });

        DB::statement('create unique index proposal_package_campuses_column_unique on proposal_package_campuses (proposal_package_id, campus_id) nulls not distinct');
        DB::statement('alter table proposal_package_campuses add constraint proposal_package_campuses_count_check check (count >= 0)');
        DB::statement('alter table proposal_package_campuses add constraint proposal_package_campuses_suggested_count_check check (suggested_count is null or suggested_count >= 0)');

        Schema::create('proposal_intakes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('career_proposal_id')->constrained();
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->integer('suggested_new_students')->nullable();
            $table->integer('new_students')->default(0);
            $table->timestampTz('captured_at')->nullable();
            $table->foreignUuid('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('campus_id');
        });

        DB::statement('create unique index proposal_intakes_column_unique on proposal_intakes (career_proposal_id, campus_id) nulls not distinct');
        DB::statement('alter table proposal_intakes add constraint proposal_intakes_new_students_check check (new_students >= 0)');
        DB::statement('alter table proposal_intakes add constraint proposal_intakes_suggested_new_students_check check (suggested_new_students is null or suggested_new_students >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_intakes');
        Schema::dropIfExists('proposal_package_campuses');
        Schema::dropIfExists('proposal_package_subjects');
        Schema::dropIfExists('proposal_packages');
    }
};
