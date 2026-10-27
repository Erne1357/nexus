<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\CampusFactory;
use App\Modules\Core\Enums\Modality;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Campus: C1, C2, EAD, ENL y TNMV. `settings` guarda el perfil de flujo y `profile` lo expone
 * como CampusProfile de solo lectura; el flujo se decide por el perfil, nunca por `code`.
 *
 * @property-read CampusProfile $profile
 */
#[Fillable(['code', 'name', 'modality', 'settings', 'is_active'])]
#[UseFactory(CampusFactory::class)]
class Campus extends Model
{
    /** @use HasFactory<CampusFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modality' => Modality::class,
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return Attribute<CampusProfile, never>
     */
    protected function profile(): Attribute
    {
        // Sin caché de objeto: si cambia `settings`, el siguiente acceso arma el perfil nuevo.
        return Attribute::get(fn (): CampusProfile => CampusProfile::fromArray($this->settings ?? []))
            ->withoutObjectCaching();
    }

    /**
     * Etapas del periodo propias de este campus; las de `campus_id` nulo se obtienen con
     * TermStage::appliesTo().
     *
     * @return HasMany<TermStage, $this>
     */
    public function termStages(): HasMany
    {
        return $this->hasMany(TermStage::class);
    }
}
