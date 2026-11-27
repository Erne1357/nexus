<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles editables (CF-01, tarea 1.22-A; adenda del 14 de noviembre de 2026 a ADR-0018): el código declara los
 * permisos, el admin arma **roles** (paquetes de permisos) y le da a cada **puesto** (`Role`, en código) roles y
 * permisos sueltos. Lo que vale lo lee GrantTable.
 *
 * - `permission_sets`: el rol. Catálogo con `is_active` y borrado lógico. Nombre único sin importar mayúsculas entre
 *   los no borrados (CF-S2). `factory_role` es el puesto del que es rol de fábrica (uno por puesto; nunca el admin,
 *   CF-S1); `follows_factory` dice si sigue lo que el código concede a ese puesto (CF-02). `factory_baseline` guarda lo
 *   que el código le concedía al puesto cuando el rol dejó de seguirlo o se aplicaron los nuevos: lo que el código
 *   agregue después son «los permisos nuevos de fábrica».
 * - `permission_set_permissions`: las claves (`modulo.recurso.accion`) de un rol que no sigue al código.
 * - `position_permission_sets` y `position_permissions`: los roles y los permisos sueltos de cada puesto. El admin no
 *   lleva ninguno.
 * - `authz_grants_version`: una sola fila con la versión de las concesiones y un sello (`token`, un uuid nuevo en cada
 *   cambio). Los suben triggers de sentencia en las cuatro tablas, como `users.authz_version` (ADR-0018, decisión 9):
 *   ningún camino de escritura los olvida, y son parte de la llave de caché de GrantTable. El sello separa dos bases
 *   con la misma versión que comparten la caché (una recién migrada, otra restaurada o la de otro worktree).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array VERSIONED = ['permission_sets', 'permission_set_permissions', 'position_permission_sets', 'position_permissions'];

    public function up(): void
    {
        Schema::create('permission_sets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('name', 80);
            $table->string('description', 240)->nullable();
            $table->string('factory_role')->nullable();
            $table->boolean('follows_factory')->default(false);
            $table->jsonb('factory_baseline')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('permission_set_permissions', function (Blueprint $table) {
            $table->foreignUuid('permission_set_id')->constrained('permission_sets');
            $table->string('permission');
            $table->primary(['permission_set_id', 'permission']);
        });

        Schema::create('position_permission_sets', function (Blueprint $table) {
            $table->string('role');
            $table->foreignUuid('permission_set_id')->constrained('permission_sets');
            $table->primary(['role', 'permission_set_id']);
            $table->index('permission_set_id');
        });

        Schema::create('position_permissions', function (Blueprint $table) {
            $table->string('role');
            $table->string('permission');
            $table->primary(['role', 'permission']);
        });

        Schema::create('authz_grants_version', function (Blueprint $table) {
            $table->bigInteger('version')->default(0);
            $table->uuid('token')->default(DB::raw('uuidv7()'));
        });

        DB::unprepared(<<<'SQL'
            create unique index permission_sets_name_unique on permission_sets (lower(name)) where deleted_at is null;
            create unique index permission_sets_factory_role_unique on permission_sets (factory_role) where factory_role is not null;
            alter table permission_sets add constraint permission_sets_name_check check (btrim(name) <> '');
            alter table permission_sets add constraint permission_sets_factory_check check (
                (factory_role is null or factory_role <> 'admin') and (not follows_factory or factory_role is not null)
            );
            alter table permission_set_permissions add constraint permission_set_permissions_key_check check (permission ~ '^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$');
            alter table position_permission_sets add constraint position_permission_sets_role_check check (role <> 'admin');
            alter table position_permissions add constraint position_permissions_role_check check (role <> 'admin');
            alter table position_permissions add constraint position_permissions_key_check check (permission ~ '^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$');

            create unique index authz_grants_version_one_row on authz_grants_version ((true));
            insert into authz_grants_version (version) values (0);

            create or replace function authz_grants_bump() returns trigger
            language plpgsql as $$
            begin
                update authz_grants_version set version = version + 1, token = uuidv7();

                if not found then
                    insert into authz_grants_version (version) values (1);
                end if;

                return null;
            end;
            $$;
            SQL);

        foreach (self::VERSIONED as $table) {
            DB::statement("create trigger {$table}_bump_grants after insert or update or delete or truncate on {$table} for each statement execute function authz_grants_bump()");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::VERSIONED) as $table) {
            DB::statement("drop trigger if exists {$table}_bump_grants on {$table}");
        }

        DB::statement('drop function if exists authz_grants_bump()');

        Schema::dropIfExists('authz_grants_version');
        Schema::dropIfExists('position_permissions');
        Schema::dropIfExists('position_permission_sets');
        Schema::dropIfExists('permission_set_permissions');
        Schema::dropIfExists('permission_sets');
    }
};
