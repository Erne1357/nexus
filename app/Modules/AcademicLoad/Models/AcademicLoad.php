<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\AcademicLoadFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carga académica de un departamento en una modalidad y un periodo: la unidad de cierre (ADR-0008; M1, confirmado el 12
 * de noviembre de 2026). Sistemas presencial es una sola carga con los grupos de C1 y C2: cada grupo conserva su campus.
 * Su estado cambia solo por las transiciones de la tarea 2.3; el flujo lo decide el perfil de su modalidad.
 */
#[Fillable(['term_id', 'modality_id', 'department_id', 'status', 'closed_by', 'closed_at', 'approved_by', 'approved_at', 'published_by', 'published_at', 'current_version'])]
#[UseFactory(AcademicLoadFactory::class)]
class AcademicLoad extends Model
{
    /** @use HasFactory<AcademicLoadFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoadStatus::class,
            'closed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'current_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * Su modalidad del catálogo (ADR-0025, M1): su perfil decide el flujo (visto bueno, salones, bloques).
     *
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * La carga de un departamento en una modalidad y un periodo (M1): el único punto por donde se busca «la carga de un
     * grupo» o «la carga de una propuesta», con su `term_id`, `modality_id` y `department_id`.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ofDepartment(Builder $query, string $termId, string $modalityId, string $departmentId): void
    {
        $query->where('academic_loads.term_id', $termId)
            ->where('academic_loads.modality_id', $modalityId)
            ->where('academic_loads.department_id', $departmentId);
    }

    /**
     * Su historial de transiciones.
     *
     * @return HasMany<LoadTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(LoadTransition::class);
    }

    /**
     * Sus versiones publicadas, de la primera a la última.
     *
     * @return HasMany<LoadSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(LoadSnapshot::class)->orderBy('version');
    }
}
