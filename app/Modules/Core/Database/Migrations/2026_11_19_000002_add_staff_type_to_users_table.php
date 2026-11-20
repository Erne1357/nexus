<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El tipo de personal del usuario (R-V2-3, confirmado el 19 de noviembre de 2026): `users.staff_type`, nulo, `docente` o
 * `administrativo` (CHECK `users_staff_type_check`). Es aparte de los puestos. down() quita el CHECK y la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_type', 20)->nullable();
        });

        DB::statement("alter table users add constraint users_staff_type_check check (staff_type is null or staff_type in ('docente', 'administrativo'))");
    }

    public function down(): void
    {
        DB::statement('alter table users drop constraint if exists users_staff_type_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('staff_type');
        });
    }
};
