<?php

namespace App\Modules\Staffing\Models;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Database\Factories\GroupTeacherAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una asignación de docente a grupo en el historial (HC-16). Es de solo agregar: la base solo deja fijar
 * `replaced_at` y `replaced_by` una vez, cuando otra asignación la reemplaza.
 */
#[Fillable(['term_id', 'campus_id', 'group_id', 'teacher_id', 'assigned_by', 'assigned_at', 'ranking_position_at_assignment', 'ranking_size_at_assignment', 'reason', 'replaced_at', 'replaced_by'])]
#[UseFactory(GroupTeacherAssignmentFactory::class)]
class GroupTeacherAssignment extends Model
{
    /** @use HasFactory<GroupTeacherAssignmentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
            'ranking_position_at_assignment' => 'integer',
            'ranking_size_at_assignment' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * La asignación vigente: la que nadie ha reemplazado.
     *
     * @param  Builder<GroupTeacherAssignment>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('replaced_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
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
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
