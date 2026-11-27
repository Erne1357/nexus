<?php

use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3.P6: los avisos guardados antes de `data.role` no dicen a qué puesto iban; al abrirlos, un usuario con dos puestos
 * activos veía «Ya no eres ...». Se rellena el puesto con el que los mandaba su clase, solo donde `data` no trae
 * `role`. down() no hace nada: el puesto rellenado es el que el aviso siempre debió tener.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([CatalogReviewsPending::class => 'jefe_departamento'] as $type => $role) {
            DB::table('notifications')
                ->where('type', $type)
                ->whereRaw("data->'role' IS NULL")
                ->update(['data' => DB::raw("data || jsonb_build_object('role', '{$role}')")]);
        }
    }

    public function down(): void
    {
        // Sin reversa (ver el docblock).
    }
};
