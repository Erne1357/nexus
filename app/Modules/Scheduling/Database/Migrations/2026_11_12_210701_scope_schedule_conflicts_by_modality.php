<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cambiar (ronda 2 de la carga por modalidad, M1): los choques se recalculan por periodo y modalidad, con los grupos de
 * todos sus campus. `schedule_conflicts.campus_id` deja de ser la carga y pasa a ser el campus del grupo, un filtro del
 * tablero; admite nulo para el grupo de una modalidad sin salones, que no tiene campus (MS4). Los choques que ya
 * existen conservan el campus de su grupo, que es el mismo que tenían.
 *
 * down() vuelve a exigirlo; si hay choques sin campus, se detiene antes de cambiar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table schedule_conflicts alter column campus_id drop not null');
    }

    public function down(): void
    {
        if (DB::table('schedule_conflicts')->whereNull('campus_id')->exists()) {
            throw new RuntimeException('Hay choques sin campus (schedule_conflicts.campus_id nulo): no se puede volver a exigirlo.');
        }

        DB::statement('alter table schedule_conflicts alter column campus_id set not null');
    }
};
