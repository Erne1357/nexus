<?php

use App\Modules\Core\Database\Contract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEL-16 (tarea 1.41): el renglón de quien tiene elección previa lleva el estado `prior_choice`. Solo se amplía el
 * CHECK del estado (Postgres no tiene otra forma que reemplazarlo), así que es seguro con el código anterior en marcha:
 * el release anterior nunca escribe el valor nuevo y acepta los que ya existían. El escáner de ADR-0033 lo ve como un
 * DROP, por eso lleva `#[Contract]`.
 */
return new #[Contract('Solo amplía el CHECK del estado de los renglones de selección; el release anterior sigue funcionando sin cambios.')] class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table staffing_selection_entries drop constraint staffing_selection_entries_status_check');
        DB::statement("alter table staffing_selection_entries add constraint staffing_selection_entries_status_check check (status in ('waiting','done','pending_extra','unresolved','prior_choice'))");
    }

    public function down(): void
    {
        DB::statement("update staffing_selection_entries set status = 'done' where status = 'prior_choice'");
        DB::statement('alter table staffing_selection_entries drop constraint staffing_selection_entries_status_check');
        DB::statement("alter table staffing_selection_entries add constraint staffing_selection_entries_status_check check (status in ('waiting','done','pending_extra','unresolved'))");
    }
};
