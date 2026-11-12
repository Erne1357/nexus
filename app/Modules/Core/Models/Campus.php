<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\CampusFactory;
use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Modality as CatalogModality;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Campus: C1, C2, EAD, ENL y TNMV. `settings` guarda el perfil de flujo y `profile` lo expone
 * como CampusProfile de solo lectura; el flujo se decide por el perfil, nunca por `code`.
 *
 * @property-read CampusProfile $profile
 */
#[Fillable(['code', 'name', 'modality', 'modality_id', 'settings', 'is_active'])]
#[UseFactory(CampusFactory::class)]
class Campus extends Model
{
    /** @use HasFactory<CampusFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** La llave de sesión del código del último campus elegido en una pantalla de un solo campus (AZ-02). */
    public const string LAST_CHOSEN_KEY = 'core.last_campus';

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
     * Su modalidad en el catálogo `modalities` (ADR-0025): puente temporal mientras los lectores pasan de
     * `campuses.modality` al catálogo; se quita al contraer. Nulo solo en un campus guardado sin las Actions de
     * campus, su sembrador ni su factory.
     *
     * @return BelongsTo<CatalogModality, $this>
     */
    public function catalogModality(): BelongsTo
    {
        return $this->belongsTo(CatalogModality::class, 'modality_id');
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
