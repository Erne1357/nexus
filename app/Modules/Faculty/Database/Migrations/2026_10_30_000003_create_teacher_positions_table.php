<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plaza (nombramiento) de un docente: define sus horas base. Un docente puede tener varias vigentes a la
 * vez (confirmado el 30 de octubre de 2026). Cada tipo admite solo sus horas (S3, supuesto por confirmar):
 * tiempo completo 40, tres cuartos 30, medio tiempo 20, honorarios 0 y asignatura de 1 a 40. Un límite de
 * vigencia nulo es sin límite; las fechas cuentan completas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_positions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->string('kind');
            $table->unsignedSmallInteger('base_hours');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['teacher_id', 'valid_from', 'valid_to']);
        });

        DB::statement("alter table teacher_positions add constraint teacher_positions_kind_check check (kind in ('tiempo_completo', 'tres_cuartos', 'medio_tiempo', 'asignatura', 'honorarios'))");
        DB::statement(<<<'SQL'
            alter table teacher_positions add constraint teacher_positions_hours_check check (
                (kind = 'tiempo_completo' and base_hours = 40)
                or (kind = 'tres_cuartos' and base_hours = 30)
                or (kind = 'medio_tiempo' and base_hours = 20)
                or (kind = 'asignatura' and base_hours between 1 and 40)
                or (kind = 'honorarios' and base_hours = 0)
            )
            SQL);
        DB::statement('alter table teacher_positions add constraint teacher_positions_validity_check check (valid_from is null or valid_to is null or valid_to >= valid_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_positions');
    }
};
