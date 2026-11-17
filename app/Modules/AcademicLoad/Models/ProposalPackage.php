<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\AcademicLoad\Database\Factories\ProposalPackageFactory;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un paquete de la propuesta de carrera (tarea 5.6b): un conjunto de materias de primer semestre, y a veces alguna de
 * segundo, que se asigna a varios alumnos de nuevo ingreso. La composición (`subjects`) es una para toda la carrera; cada
 * columna de la propuesta captura cuántos abre (`campuses`) y cada uno abre un grupo de cada una de sus materias. `code`
 * («P1», «P2»…) es único por propuesta y `name` es opcional («Matutino»).
 */
#[Fillable(['career_proposal_id', 'code', 'name', 'position', 'created_by'])]
#[UseFactory(ProposalPackageFactory::class)]
class ProposalPackage extends Model
{
    /** @use HasFactory<ProposalPackageFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<CareerProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CareerProposal::class, 'career_proposal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ProposalPackageSubject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(ProposalPackageSubject::class);
    }

    /**
     * @return HasMany<ProposalPackageCampus, $this>
     */
    public function campuses(): HasMany
    {
        return $this->hasMany(ProposalPackageCampus::class);
    }
}
