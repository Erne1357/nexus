<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El paquete del que nace un grupo (tarea 5.6b; docs/dominio/modelo-de-datos.md, `groups`): `package_id` es el paquete de
 * la propuesta y `package_number` cuál de los que abrió el campus (1..cuenta), ambos nulos en un grupo suelto (CHECK). La
 * cohorte del motor de horarios es `package:<package_id>:<package_number>`: los grupos con el mismo par no se empalman
 * (fase 6). Aquí solo se guarda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignUuid('package_id')->nullable()->constrained('proposal_packages');
            $table->smallInteger('package_number')->nullable();
        });

        DB::statement('alter table groups add constraint groups_package_check check ((package_id is null) = (package_number is null) and (package_number is null or package_number >= 1))');
        DB::statement('create index groups_package_cohort_index on groups (package_id, package_number) where package_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists groups_package_cohort_index');
        DB::statement('alter table groups drop constraint if exists groups_package_check');

        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
            $table->dropColumn('package_number');
        });
    }
};
