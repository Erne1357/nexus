<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La baja de una cuenta en el panel (CF-05 y CF-09, confirmado el 14 de noviembre de 2026): `deactivated_at` es cuándo
 * la dio de baja el admin con DeactivateUser y `deactivated_by`, quién; las dos nulas si no, y ReactivateUser las vuelve
 * a dejar nulas. Una cuenta dada de baja así no la reactiva ningún otro camino (la sincronización de los docentes, la
 * migración del SII): solo el admin, desde el panel. Lo garantiza también la base con dos CHECK:
 * `users_deactivated_inactive` (con `deactivated_at`, `is_active` es falso) y `users_deactivated_by_requires_at`.
 *
 * Las cuentas que ya están inactivas no las dio de baja el panel, así que se quedan con las dos nulas. down() quita los
 * CHECK y las columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignUuid('deactivated_by')->nullable()->constrained('users');
        });

        DB::statement('alter table users add constraint users_deactivated_inactive check (deactivated_at is null or not is_active)');
        DB::statement('alter table users add constraint users_deactivated_by_requires_at check (deactivated_by is null or deactivated_at is not null)');
    }

    public function down(): void
    {
        DB::statement('alter table users drop constraint if exists users_deactivated_by_requires_at');
        DB::statement('alter table users drop constraint if exists users_deactivated_inactive');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn('deactivated_at');
        });
    }
};
