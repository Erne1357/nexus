<?php

namespace App\Modules\Imports\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Imports\Enums\ImportsPermission;
use App\Modules\Imports\Models\ImportBatch;

/**
 * Quién importa, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Importar catálogos":
 * `admin`): solo el admin ve, sube y aplica lotes, de cualquier campus. Nadie borra un lote: es el
 * registro de qué se importó (no hay `delete`).
 */
final class ImportBatchPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(ImportsPermission::BatchView);
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $this->actor($user)->allows(ImportsPermission::BatchView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(ImportsPermission::BatchCreate);
    }

    public function apply(User $user, ImportBatch $batch): bool
    {
        return $this->actor($user)->allows(ImportsPermission::BatchApply);
    }
}
