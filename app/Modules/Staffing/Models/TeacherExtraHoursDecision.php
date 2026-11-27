<?php

namespace App\Modules\Staffing\Models;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\ExtraHoursDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PL-04: la decisión del jefe de departamento sobre las horas extra de un docente en un periodo (autorizadas como
 * honorarios o no autorizadas) y las horas extra sobre las que decidió. La escribe DecideExtraHours.
 */
#[Fillable(['teacher_id', 'term_id', 'decision', 'hours', 'decided_by', 'decided_at'])]
class TeacherExtraHoursDecision extends Model
{
    use HasUuids;

    protected $table = 'teacher_extra_hours_decisions';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'decision' => ExtraHoursDecision::class,
            'hours' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
