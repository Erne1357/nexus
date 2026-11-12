<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.email` solo tenía un índice único sensible a mayúsculas (`users_email_unique`), pero el código lo busca con
 * `lower(email) = ?` (SendAccessInvitation, SyncStudentAccounts): cada búsqueda era una lectura secuencial (con 69 mil
 * usuarios, una corrida de cuentas de alumnos se volvió cuadrática) y dos correos que solo difieren en mayúsculas
 * podían convivir. Este índice único sobre `lower(email)` resuelve las dos cosas. `users_email_unique` se queda.
 *
 * up() revisa antes los correos que ya chocan sin distinguir mayúsculas. Si hay, se detiene con un RuntimeException que
 * lista los ids de sus usuarios y no toca ningún dato: no borra ni cambia correos.
 *
 * La base de demostración puede traer un índice temporal no único sobre la misma expresión
 * (`users_email_lower_demo_idx`); se quita antes de crear el definitivo, que lo reemplaza.
 *
 * down() quita el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicated = DB::table('users')
            ->whereNotNull('email')
            ->whereIn(DB::raw('lower(email)'), fn ($query) => $query
                ->from('users')
                ->selectRaw('lower(email)')
                ->whereNotNull('email')
                ->groupByRaw('lower(email)')
                ->havingRaw('count(*) > 1'))
            ->orderBy('id')
            ->pluck('id');

        if ($duplicated->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay %d usuarios cuyo correo se repite sin distinguir mayúsculas (users_email_lower_unique): %s. Corrige o quita los correos repetidos antes de migrar.',
                $duplicated->count(),
                $duplicated->implode(', '),
            ));
        }

        DB::statement('drop index if exists users_email_lower_demo_idx');
        DB::statement('create unique index users_email_lower_unique on users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('drop index if exists users_email_lower_unique');
    }
};
