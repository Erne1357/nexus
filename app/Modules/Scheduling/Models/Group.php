<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Database\Factories\GroupFactory;
use App\Modules\Scheduling\Enums\GroupStatus;
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
 * Oferta concreta de una materia en un periodo y campus (glosario, «Grupo»): su carrera dueña, el plan y semestre
 * reticular donde se acomoda, su clave del SII, su docente vigente, su capacidad y su estado. Su departamento es el
 * de la materia.
 */
#[Fillable(['term_id', 'campus_id', 'modality_id', 'subject_id', 'department_id', 'owner_career_id', 'study_plan_id', 'semester', 'code', 'teacher_id', 'capacity', 'status', 'proposal_item_id', 'notes', 'close_justification', 'close_justified_by', 'close_justified_at'])]
#[UseFactory(GroupFactory::class)]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'capacity' => 'integer',
            'status' => GroupStatus::class,
            'close_justified_at' => 'immutable_datetime',
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
     * Su modalidad del catálogo (ADR-0025): la de la propuesta de donde nace o, en los grupos anteriores a la ronda 2, la
     * de su campus (la copian sus sesiones). Liga el grupo a la carga de su departamento en esa modalidad (M1) y su
     * perfil decide si el grupo usa salones y bloques de tiempo.
     *
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function ownerCareer(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'owner_career_id');
    }

    /**
     * @return BelongsTo<StudyPlan, $this>
     */
    public function studyPlan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<ProposalItem, $this>
     */
    public function proposalItem(): BelongsTo
    {
        return $this->belongsTo(ProposalItem::class);
    }

    /**
     * @return HasMany<GroupSession, $this>
     */
    /**
     * Quien escribió la justificación de HC-15 (JustifyGroup).
     *
     * @return BelongsTo<User, $this>
     */
    public function justifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'close_justified_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(GroupSession::class);
    }

    /**
     * @return HasMany<GroupQuota, $this>
     */
    public function quotas(): HasMany
    {
        return $this->hasMany(GroupQuota::class);
    }

    /**
     * @return HasMany<ScheduleConflict, $this>
     */
    public function conflicts(): HasMany
    {
        return $this->hasMany(ScheduleConflict::class);
    }

    /**
     * Los grupos que cuentan: todos menos los cancelados.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('groups.status', '<>', GroupStatus::Cancelled->value);
    }
}
