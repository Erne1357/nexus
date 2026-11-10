<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Queries\RoomLoansQuery;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\TeacherBaseHoursQuery;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ScheduleStateBuilder;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateQuota;
use App\Modules\Scheduling\Validation\State\StateRoom;
use App\Modules\Scheduling\Validation\State\StateRoomBlock;
use App\Modules\Scheduling\Validation\State\StateRoomLoan;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\State\StateSubject;
use App\Modules\Scheduling\Validation\State\StateTeacher;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lee de la base el ScheduleState de un periodo visto desde un campus (tarea 3.2), en pocas consultas agregadas:
 *
 * - todos los grupos del campus (los cancelados, inactivos) con sus sesiones y cupos;
 * - los grupos activos con docente de los otros campus del periodo, con sus sesiones, porque HC-01 cruza campus;
 * - las materias de esos grupos, los salones del campus y los que usan esas sesiones, y los bloqueos vigentes;
 * - los docentes de esos grupos: nombre, horas base (TeacherBaseHoursQuery), horas externas del periodo, bloques no
 *   disponibles y si es de honorarios;
 * - los perfiles de los campus, las carreras ofrecidas, los planes activos y los parámetros `staffing.*` y
 *   `scheduling.*`.
 *
 * - los préstamos de salón vivos (aceptados o propuestos) del campus, de RoomLoansQuery de AcademicLoad (HC-10).
 */
final class ScheduleStateQuery
{
    private const array SETTING_PREFIXES = ['staffing.', 'scheduling.'];

    public function __construct(
        private readonly TeacherBaseHoursQuery $baseHours,
        private readonly RoomLoansQuery $roomLoans,
    ) {}

    /**
     * La foto del periodo vista desde un campus; sin campus, solo los grupos activos con docente de todo el periodo
     * (lo que necesita validar a un docente).
     */
    public function forCampus(Term $term, ?Campus $campus = null): ScheduleState
    {
        $builder = new ScheduleStateBuilder($term->id);

        $groups = Group::query()
            ->where('term_id', $term->id)
            ->where(fn (Builder $query) => $query->when($campus !== null, fn (Builder $focus) => $focus->where('campus_id', $campus->id))
                ->orWhere(fn (Builder $other) => $other->whereNotNull('teacher_id')->where('status', '<>', GroupStatus::Cancelled->value)))
            ->get(['id', 'campus_id', 'subject_id', 'department_id', 'owner_career_id', 'study_plan_id', 'semester', 'code', 'teacher_id', 'capacity', 'status']);

        foreach ($groups as $group) {
            $builder->group(self::stateGroup($group));
        }

        $groupIds = $groups->pluck('id')->all();
        $roomIds = [];

        GroupSession::query()->whereIn('group_id', $groupIds)
            ->orderBy('day')->orderBy('starts_at')
            ->get(['id', 'group_id', 'day', 'starts_at', 'ends_at', 'room_id', 'kind'])
            ->each(function (GroupSession $session) use ($builder, &$roomIds): void {
                $builder->session(new StateSession($session->id, $session->group_id, $session->day,
                    Minutes::of($session->starts_at), Minutes::of($session->ends_at), $session->room_id, $session->kind));

                if ($session->room_id !== null) {
                    $roomIds[$session->room_id] = true;
                }
            });

        $focusIds = $campus === null ? [] : $groups->where('campus_id', $campus->id)->pluck('id')->all();

        GroupQuota::query()->whereIn('group_id', $focusIds)
            ->get(['group_id', 'career_id', 'max_seats', 'is_owner'])
            ->each(fn (GroupQuota $quota) => $builder->quota(new StateQuota($quota->group_id, $quota->career_id, $quota->max_seats, $quota->is_owner)));

        Subject::query()->withTrashed()->whereIn('id', $groups->pluck('subject_id')->unique()->all())
            ->get(['id', 'code', 'name', 'theory_hours', 'practice_hours', 'needs_lab', 'is_active', 'deleted_at'])
            ->each(fn (Subject $subject) => $builder->subject(new StateSubject($subject->id, $subject->code, $subject->name,
                $subject->theory_hours, $subject->practice_hours, $subject->needs_lab, $subject->is_active && ! $subject->trashed())));

        $this->addRooms($builder, $term, $campus, array_keys($roomIds));

        if ($campus !== null) {
            $this->roomLoans->liveFor($term->id, $campus->id)
                ->each(fn (InterdepartmentalAgreement $loan) => $builder->loan(new StateRoomLoan($loan->group_id, $loan->room_id, (int) $loan->day,
                    Minutes::of($loan->starts_at), Minutes::of($loan->ends_at), $loan->status === AgreementStatus::Accepted)));
        }
        $this->addTeachers($builder, $term, $groups->pluck('teacher_id')->filter()->unique()->values()->all());

        foreach (Campus::query()->get(['id', 'code', 'settings']) as $each) {
            $builder->profile($each->id, $each->profile)->campusCode($each->id, $each->code);
        }

        CareerCampus::query()->where('is_active', true)->get(['career_id', 'campus_id'])
            ->each(fn (CareerCampus $offering) => $builder->offering($offering->career_id, $offering->campus_id));

        StudyPlan::query()->where('is_active', true)->pluck('id')
            ->each(fn (string $id) => $builder->activePlan($id));

        Setting::query()->where('scope', SettingScope::Global)->whereNull('scope_id')
            ->where(function (Builder $query): void {
                foreach (self::SETTING_PREFIXES as $prefix) {
                    $query->orWhere('key', 'like', $prefix.'%');
                }
            })
            ->get(['key', 'value'])
            ->each(fn (Setting $setting) => $builder->setting($setting->key, $setting->value));

        return $builder->build();
    }

    /**
     * Docentes que la foto no trae (los candidatos del ranking): su nombre, horas y no disponibilidad del periodo. Un
     * docente borrado no entra (sus horas base ya no cuentan), así que las reglas de horas no lo revisan.
     *
     * @param  list<string>  $teacherIds
     * @return list<StateTeacher>
     */
    public function teachers(Term $term, array $teacherIds): array
    {
        if ($teacherIds === []) {
            return [];
        }

        $base = $this->baseHours->forTerm($term, $teacherIds);

        $external = TeacherExternalLoad::query()->where('term_id', $term->id)->whereIn('teacher_id', $teacherIds)
            ->groupBy('teacher_id')->selectRaw('teacher_id, sum(hours) as hours')
            ->pluck('hours', 'teacher_id');

        $unavailable = [];

        TeacherAvailability::query()->where('term_id', $term->id)->whereIn('teacher_id', $teacherIds)
            ->where('kind', AvailabilityKind::Unavailable->value)
            ->get(['teacher_id', 'day', 'starts_at', 'ends_at'])
            ->each(function (TeacherAvailability $block) use (&$unavailable): void {
                $unavailable[$block->teacher_id][] = [(int) $block->day, Minutes::of($block->starts_at), Minutes::of($block->ends_at)];
            });

        $honorarios = TeacherPosition::query()->currentOn($term->starts_on)->whereIn('teacher_id', $teacherIds)
            ->where('kind', PositionKind::Honorarios->value)
            ->distinct()->pluck('teacher_id')->flip();

        return Teacher::query()->whereIn('id', $teacherIds)->orderBy('id')->get(['id', 'name'])
            ->map(fn (Teacher $teacher): StateTeacher => new StateTeacher(
                $teacher->id,
                $teacher->name,
                $base[$teacher->id] ?? 0,
                (int) ($external[$teacher->id] ?? 0),
                $unavailable[$teacher->id] ?? [],
                $honorarios->has($teacher->id),
            ))
            ->all();
    }

    /**
     * @param  list<string>  $teacherIds
     */
    private function addTeachers(ScheduleStateBuilder $builder, Term $term, array $teacherIds): void
    {
        foreach ($this->teachers($term, $teacherIds) as $teacher) {
            $builder->teacher($teacher);
        }
    }

    /**
     * Los salones del campus y los de otros campus que usan las sesiones cargadas, con sus bloqueos del periodo.
     *
     * @param  list<string>  $usedRoomIds
     */
    private function addRooms(ScheduleStateBuilder $builder, Term $term, ?Campus $campus, array $usedRoomIds): void
    {
        $rooms = Room::query()->withTrashed()
            ->where(fn (Builder $query) => $query->when($campus !== null, fn (Builder $focus) => $focus->where('campus_id', $campus->id))->orWhereIn('id', $usedRoomIds))
            ->get(['id', 'campus_id', 'code', 'capacity', 'type', 'department_id', 'is_active', 'deleted_at']);

        foreach ($rooms as $room) {
            $builder->room(new StateRoom($room->id, $room->campus_id, $room->code, $room->capacity, $room->type,
                $room->department_id, $room->is_active && ! $room->trashed()));
        }

        RoomBlock::query()->whereIn('room_id', $rooms->pluck('id')->all())
            ->where(fn (Builder $query) => $query->whereNull('term_id')->orWhere('term_id', $term->id))
            ->get(['room_id', 'day', 'starts_at', 'ends_at', 'reason'])
            ->each(fn (RoomBlock $block) => $builder->block(new StateRoomBlock($block->room_id, $block->day,
                Minutes::of($block->starts_at), Minutes::of($block->ends_at), (string) $block->reason)));
    }

    private static function stateGroup(Group $group): StateGroup
    {
        return new StateGroup(
            $group->id,
            $group->campus_id,
            $group->subject_id,
            $group->department_id,
            $group->owner_career_id,
            $group->study_plan_id,
            $group->semester,
            $group->code,
            $group->teacher_id,
            $group->capacity,
            $group->status !== GroupStatus::Cancelled,
        );
    }
}
