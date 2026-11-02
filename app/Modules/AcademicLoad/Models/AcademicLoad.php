<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\AcademicLoadFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carga académica de un departamento en un campus y un periodo: la unidad de cierre (ADR-0008). Su estado
 * cambia solo por las transiciones de la tarea 2.3.
 */
#[Fillable(['term_id', 'campus_id', 'department_id', 'status', 'closed_by', 'closed_at', 'approved_by', 'approved_at', 'published_by', 'published_at', 'current_version'])]
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
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
