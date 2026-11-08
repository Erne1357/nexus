<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\InterdepartmentalAgreementFactory;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un acuerdo entre dos departamentos en un periodo y un campus (tareas 3.6 y 3.7): el préstamo de un salón para un
 * día y unas horas, o el cupo de otra carrera en un grupo. `from` es quien lo pide (el que necesita el salón o el que
 * imparte el grupo); `to`, quien lo decide (el dueño del salón o el departamento de la carrera). `group_id` es un grupo
 * de Scheduling, sin relación aquí porque Scheduling está a la derecha en el mapa.
 */
#[Fillable([
    'term_id', 'campus_id', 'kind', 'from_department_id', 'to_department_id', 'group_id', 'room_id', 'day', 'starts_at',
    'ends_at', 'career_id', 'max_seats', 'status', 'comment', 'requested_by', 'decided_by', 'decided_at',
])]
#[UseFactory(InterdepartmentalAgreementFactory::class)]
class InterdepartmentalAgreement extends Model
{
    /** @use HasFactory<InterdepartmentalAgreementFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AgreementKind::class,
            'status' => AgreementStatus::class,
            'day' => 'integer',
            'max_seats' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * Propuestos o aceptados: los que apartan el salón o el cupo.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereIn('status', AgreementStatus::liveValues());
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
     * El departamento que lo pide.
     *
     * @return BelongsTo<Department, $this>
     */
    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    /**
     * El departamento que lo decide.
     *
     * @return BelongsTo<Department, $this>
     */
    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * La carrera del cupo, en un grupo compartido.
     *
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Quien lo aceptó, lo rechazó o lo canceló.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
