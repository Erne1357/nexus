<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el tipo de plaza `apoyo` (personal de apoyo y asistencia a la educación, las categorías de dos letras del
 * SII) con horas base de 1 a 40, como asignatura. Sus horas cuentan como horas base igual que las de cualquier otra
 * plaza (confirmado el 6 de noviembre de 2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table teacher_positions drop constraint teacher_positions_kind_check');
        DB::statement('alter table teacher_positions drop constraint teacher_positions_hours_check');
        DB::statement("alter table teacher_positions add constraint teacher_positions_kind_check check (kind in ('tiempo_completo', 'tres_cuartos', 'medio_tiempo', 'asignatura', 'honorarios', 'apoyo'))");
        DB::statement(<<<'SQL'
            alter table teacher_positions add constraint teacher_positions_hours_check check (
                (kind = 'tiempo_completo' and base_hours = 40)
                or (kind = 'tres_cuartos' and base_hours = 30)
                or (kind = 'medio_tiempo' and base_hours = 20)
                or (kind in ('asignatura', 'apoyo') and base_hours between 1 and 40)
                or (kind = 'honorarios' and base_hours = 0)
            )
            SQL);
    }

    public function down(): void
    {
        if (DB::table('teacher_positions')->where('kind', 'apoyo')->exists()) {
            throw new RuntimeException('No se revierte: hay plazas de apoyo y las plazas no se borran.');
        }

        DB::statement('alter table teacher_positions drop constraint teacher_positions_kind_check');
        DB::statement('alter table teacher_positions drop constraint teacher_positions_hours_check');
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
    }
};
