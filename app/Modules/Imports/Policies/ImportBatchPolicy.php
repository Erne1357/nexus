<?php

namespace App\Modules\Imports\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use App\Modules\Imports\Models\ImportBatch;

/**
 * Quién importa, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Importar catálogos":
 * `admin`): solo el admin ve, sube y aplica lotes, de cualquier campus. Nadie borra un lote: es el
 * registro de qué se importó (no hay `delete`).
 */
final class ImportBatchPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function create(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function apply(User $user, ImportBatch $batch): bool
    {
        return $this->scope($user)->isAdmin();
    }
}
