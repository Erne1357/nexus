<?php

namespace App\Modules\Staffing\Models;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Database\Factories\SelectionFactory;
use App\Modules\Staffing\Enums\SelectionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Selección de carga por turnos de un departamento en un periodo, modalidad y campus (tarea 1.33).
 */
#[Fillable(['term_id', 'modality_id', 'department_id', 'campus_id', 'status', 'starts_at', 'window_starts', 'window_ends', 'weekdays', 'slot_minutes', 'published_at', 'first_round_ends_at', 'extra_round_starts_at', 'extra_round_ends_at', 'finished_at', 'cancelled_at', 'created_by'])]
#[UseFactory(SelectionFactory::class)]
class Selection extends Model
{
    /** @use HasFactory<SelectionFactory> */
    use HasFactory, HasUuids;

    protected $table = 'staffing_selections';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => SelectionStatus::class,
            'weekdays' => 'array',
            'slot_minutes' => 'integer',
            'starts_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'first_round_ends_at' => 'immutable_datetime',
            'extra_round_starts_at' => 'immutable_datetime',
            'extra_round_ends_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<SelectionEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SelectionEntry::class);
    }

    /**
     * @return BelongsToMany<AcademicLoad, $this>
     */
    public function loads(): BelongsToMany
    {
        return $this->belongsToMany(AcademicLoad::class, 'staffing_selection_loads', 'selection_id', 'academic_load_id');
    }
}
