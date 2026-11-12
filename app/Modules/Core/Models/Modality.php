<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\ModalityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modalidad (ADR-0025; MS1, supuesto por confirmar): `PRES` Presencial, `EAD`, `ENL` En línea y `TNMV` TecNM Virtual.
 * Es la unidad de la carga; el campus queda como lugar físico. `settings` guarda el perfil de flujo y `profile` lo
 * expone como ModalityProfile de solo lectura; el flujo se decide por el perfil, nunca por `code`. Catálogo: soft
 * deletes e `is_active`; el código sigue reservado aunque la modalidad se borre.
 *
 * @property-read ModalityProfile $profile
 */
#[Fillable(['code', 'name', 'settings', 'is_active'])]
#[UseFactory(ModalityFactory::class)]
class Modality extends Model
{
    /** @use HasFactory<ModalityFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return Attribute<ModalityProfile, never>
     */
    protected function profile(): Attribute
    {
        // Sin caché de objeto: si cambia `settings`, el siguiente acceso arma el perfil nuevo.
        return Attribute::get(fn (): ModalityProfile => ModalityProfile::fromArray($this->settings ?? []))
            ->withoutObjectCaching();
    }
}
