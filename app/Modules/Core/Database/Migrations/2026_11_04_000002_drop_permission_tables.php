<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Borra las cinco tablas de spatie/laravel-permission: desde ADR-0018 los puestos son el enum `Role` y los
 * nombramientos, `role_assignments`. La migración anterior (2026_11_04_000001) ya dio un nombramiento `admin` a
 * cada admin de spatie. down() las vuelve a crear, vacías, con la migración histórica
 * (2026_10_27_000006), que lee `config/permission.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    public function down(): void
    {
        (require __DIR__.'/2026_10_27_000006_create_permission_tables.php')->up();
    }
};
