<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\PermissionSetFactory;
use App\Modules\Core\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un rol: un paquete de permisos que el admin arma y le da a los puestos (CF-01). El de fábrica de un puesto
 * (`factory_role`) sigue lo que el código le concede mientras `follows_factory` (CF-02); los demás, y el de fábrica
 * editado, llevan sus claves en `permission_set_permissions`. Catálogo: `is_active` y borrado lógico (CF-S2). Lo que
 * cada puesto puede lo lee GrantTable; solo Core/Authorization y las piezas de roles editables tocan sus tablas. Lo de
 * fábrica (`factory_role`, `follows_factory`, `factory_baseline`) no se asigna en masa: lo escriben las Actions con
 * forceFill().
 */
#[Fillable(['name', 'description', 'is_active'])]
#[UseFactory(PermissionSetFactory::class)]
class PermissionSet extends Model
{
    /** @use HasFactory<PermissionSetFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factory_role' => Role::class,
            'follows_factory' => 'boolean',
            'factory_baseline' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
