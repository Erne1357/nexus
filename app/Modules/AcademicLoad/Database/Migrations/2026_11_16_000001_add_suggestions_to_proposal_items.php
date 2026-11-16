<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La propuesta guarda lo que sugirió Nexus aparte de lo que captura el coordinador (tarea 5.5; docs/dominio/modelo-de-datos.md,
 * `proposal_items`): `suggested_expected_students` y `suggested_groups` (≥ 0, CHECK) son lo sugerido la última vez que el
 * coordinador lo vio o lo aceptó, nulos si no había proyección; `forecast_method` dice con qué método se calculó. `captured_at`
 * y `captured_by` marcan la última vez que el coordinador cambió esperados o grupos: nulo es «sin tocar», y esas filas siguen
 * solas a la proyección cuando se recalcula (confirmado el 16 de noviembre de 2026).
 *
 * Relleno: una fila cuyo `updated_at` es posterior a su `created_at` ya fue corregida, así que `captured_at = updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->integer('suggested_expected_students')->nullable();
            $table->integer('suggested_groups')->nullable();
            $table->string('forecast_method')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->foreignUuid('captured_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('alter table proposal_items add constraint proposal_items_suggested_expected_students_check check (suggested_expected_students >= 0)');
        DB::statement('alter table proposal_items add constraint proposal_items_suggested_groups_check check (suggested_groups >= 0)');
        DB::statement('update proposal_items set captured_at = updated_at where updated_at > created_at');
    }

    public function down(): void
    {
        DB::statement('alter table proposal_items drop constraint if exists proposal_items_suggested_expected_students_check');
        DB::statement('alter table proposal_items drop constraint if exists proposal_items_suggested_groups_check');

        Schema::table('proposal_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('captured_by');
            $table->dropColumn(['suggested_expected_students', 'suggested_groups', 'forecast_method', 'captured_at']);
        });
    }
};
