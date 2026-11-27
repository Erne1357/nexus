<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién canceló un acuerdo y cuándo (`cancelled_by`, `cancelled_at`), aparte de quién lo decidió: cancelar uno ya
 * aceptado no borra quién lo aceptó. El CHECK de la decisión se reemplaza: `proposed` no lleva decisión ni
 * cancelación; `accepted` y `rejected` llevan decisión (quién y cuándo, juntos) y no cancelación; `cancelled` lleva
 * cancelación (juntas) y puede o no llevar decisión, según se haya aceptado antes o se haya cancelado propuesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interdepartmental_agreements', function (Blueprint $table) {
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestampTz('cancelled_at')->nullable();
        });

        DB::statement('alter table interdepartmental_agreements drop constraint interdepartmental_agreements_decision_check');
        DB::statement(<<<'SQL'
            alter table interdepartmental_agreements add constraint interdepartmental_agreements_decision_check check (
                (status = 'proposed' and decided_by is null and decided_at is null and cancelled_by is null and cancelled_at is null)
                or (status in ('accepted', 'rejected') and decided_by is not null and decided_at is not null and cancelled_by is null and cancelled_at is null)
                or (status = 'cancelled' and cancelled_by is not null and cancelled_at is not null and (decided_by is null) = (decided_at is null))
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('alter table interdepartmental_agreements drop constraint interdepartmental_agreements_decision_check');
        DB::statement("alter table interdepartmental_agreements add constraint interdepartmental_agreements_decision_check check ((status = 'proposed') = (decided_at is null) and (decided_at is null) = (decided_by is null))");

        Schema::table('interdepartmental_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });
    }
};
