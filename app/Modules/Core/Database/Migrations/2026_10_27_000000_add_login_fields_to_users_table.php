<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login por username (ADR-0004): el correo pasa a opcional y un usuario nuevo nace activo y
 * obligado a cambiar su contraseña en el primer acceso (supuesto 2 de PROJECT.md).
 *
 * Las filas que ya existan reciben su correo (o `user_<id>`) como username antes de volverlo
 * obligatorio y único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable();
            $table->string('email')->nullable()->change();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->timestampTz('last_login_at')->nullable();
        });

        DB::table('users')->whereNull('username')->update(['username' => DB::raw("coalesce(email, 'user_' || id)")]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'is_active', 'must_change_password', 'last_login_at']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
