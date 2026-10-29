<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién dirige cada departamento en cada campus y desde cuándo. En Campus II el organigrama oficial tiene
 * un solo departamento: sus jefes por departamento son de hecho, y aquí se registran igual. `valid_to` nulo
 * es vigente sin fecha de fin; las fechas cuentan completas.
 *
 * Un solo `jefe` vigente por departamento y campus: la base rechaza dos periodos que se traslapan, salvo
 * que uno esté borrado. De `jefe_proyecto_docente` puede haber varios a la vez (confirmado el 29 de octubre
 * de 2026). La exclusión con rangos de fechas necesita `btree_gist` para comparar los uuid en un índice GiST.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists btree_gist');

        Schema::create('department_heads', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('department_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->string('kind');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['department_id', 'campus_id']);
            $table->index('user_id');
        });

        DB::statement('alter table department_heads add constraint department_heads_validity_check check (valid_to is null or valid_to >= valid_from)');
        DB::statement(<<<'SQL'
            alter table department_heads add constraint department_heads_one_jefe exclude using gist (
                department_id with =,
                campus_id with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (kind = 'jefe' and deleted_at is null)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('department_heads');
    }
};
