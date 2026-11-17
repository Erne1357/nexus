<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántos de los `requested_groups` de una fila salen de paquetes (tarea 5.6b; docs/dominio/modelo-de-datos.md,
 * `proposal_items`): la suma de lo que se abre en ese campus de los paquetes que llevan la materia. `requested_groups`
 * sigue siendo el total de la fila, así que nunca baja de `package_groups` (CHECK); lo que pide además el coordinador
 * («grupos sueltos») es la diferencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->integer('package_groups')->default(0);
        });

        DB::statement('alter table proposal_items add constraint proposal_items_package_groups_check check (package_groups >= 0 and requested_groups >= package_groups)');
    }

    public function down(): void
    {
        DB::statement('alter table proposal_items drop constraint if exists proposal_items_package_groups_check');

        Schema::table('proposal_items', function (Blueprint $table) {
            $table->dropColumn('package_groups');
        });
    }
};
