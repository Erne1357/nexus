<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas sin acceso (tarea 1.16, AC-01): `users.password` admite nulo, y nulo es **sin acceso**; nadie entra con él.
 * `access_invited_at` (timestamptz) es cuándo se le mandó al usuario su invitación de acceso, la última; nulo si nunca.
 *
 * down() se detiene si hay usuarios sin contraseña, porque no les inventa una: hay que darles acceso antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->timestampTz('access_invited_at')->nullable();
        });
    }

    public function down(): void
    {
        $withoutAccess = DB::table('users')->whereNull('password')->count();

        if ($withoutAccess > 0) {
            throw new RuntimeException("Hay {$withoutAccess} usuarios sin contraseña: dales acceso antes de volver a exigirla.");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('access_invited_at');
            $table->string('password')->nullable(false)->change();
        });
    }
};
