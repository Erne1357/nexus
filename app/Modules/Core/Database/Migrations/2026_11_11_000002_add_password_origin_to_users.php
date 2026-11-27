<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Origen de la contraseña (AC-12, PasswordOrigin): `default`, `sii_nip` o `user`; nulo sin contraseña. Los usuarios
 * que ya la tienen se marcan como `default` si todavía deben cambiarla y como `user` si ya la cambiaron. Ninguno
 * existente es `sii_nip`: los alumnos reciben su usuario después (AC-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_origin', 20)->nullable();
        });

        DB::table('users')->whereNotNull('password')->update([
            'password_origin' => DB::raw("case when must_change_password then 'default' else 'user' end"),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_origin');
        });
    }
};
