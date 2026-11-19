<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Queries\ReanalysisRow;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Demand\Rules\SuggestedGroups;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\GroupCapacity;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorGroup;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorRoom;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorSession;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupBase;
use App\Modules\Scheduling\Rules\ReanalysisAdvisor;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Las sugerencias del re-análisis de un bloque (una materia en un campus) de la carga (tarea 8.3a): lee sus grupos vivos
 * y, para los que tienen salón en todas sus sesiones, los salones más grandes libres a la hora de cada sesión, y se los
 * pasa con el diff del bloque a ReanalysisAdvisor. Solo para el bloque abierto y en un número fijo de consultas, sin
 * importar cuántos grupos o salones haya.
 *
 * Los grupos son los no cancelados de la carga (su periodo, modalidad y departamento) con la materia del bloque en su
 * campus (sin campus en una modalidad sin salones). Un salón más grande sirve a una sesión si es del campus, está activo,
 * es más grande que el suyo, es del tipo que pide la sesión (la práctica de una materia con laboratorio, laboratorio,
 * taller o sala de cómputo, HC-06; lo demás, aula; supuesto por confirmar) y a esa hora no lo ocupa otra sesión (HC-02),
 * no está bloqueado (HC-11) ni lo aparta el préstamo vivo de otro grupo (HC-10). Uno de otro departamento se ofrece
 * diciendo a quién se pide el préstamo, después de los que no lo piden; a igual cosa, el más chico y por clave.
 *
 * Para abrir un grupo (tarea 8.3b) le da al asesor la capacidad con la que nacería (GroupCapacity) y el plan vigente de
 * cada carrera del bloque con la materia y su semestre (NewGroupBase), en dos consultas más; el horario y el salón no
 * se calculan aquí sino al abrir la tarjeta (NewGroupPlacementQuery). Un bloque sin grupos vivos (demanda sin oferta, o
 * todos cancelados) también pasa por el asesor: lo único que puede proponer es abrir uno.
 */
final class ReanalysisSuggestionsQuery
{
    public function __construct(private readonly ReanalysisAdvisor $advisor) {}

    public function for(AcademicLoad $load, ReanalysisBlock $block): ReanalysisSuggestions
    {
        $withTrashed = fn (Relation $query) => $query->withTrashed();
        $campusId = $block->campus?->getKey() ?? $block->rows[0]->campusId ?? null;
        $groups = Group::query()
            ->active()
            ->where('term_id', $load->term_id)
            ->where('modality_id', $load->modality_id)
            ->where('department_id', $load->department_id)
            ->where('subject_id', $block->subjectId)
            ->when($campusId === null, fn (Builder $query) => $query->whereNull('campus_id'), fn (Builder $query) => $query->where('campus_id', $campusId))
            ->with([
                'quotas' => fn ($quotas) => $quotas->orderByDesc('is_owner')->orderBy('id'),
                'sessions' => fn ($sessions) => $sessions->orderBy('day')->orderBy('starts_at'),
                'sessions.room' => $withTrashed,
                'teacher' => $withTrashed,
            ])
            ->orderBy('code')
            ->get();

        $settings = DemandSettings::fromSettings();

        $held = InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::SharedGroup->value)
            ->where('status', AgreementStatus::Proposed->value)
            ->whereIn('group_id', $groups->modelKeys())
            ->groupBy('group_id')
            ->selectRaw('group_id, sum(max_seats) as held')
            ->pluck('held', 'group_id');
        $careerCodes = Career::withTrashed()
            ->whereIn('id', $groups->flatMap(fn (Group $group) => $group->quotas->pluck('career_id'))->unique()->values()->all())
            ->pluck('code', 'id')
            ->all();
        $subject = Subject::withTrashed()->whereKey($block->subjectId)->firstOrFail();
        $larger = $this->largerRooms($load, $subject, $campusId, $groups);

        $advisorGroups = $groups->map(fn (Group $group): AdvisorGroup => new AdvisorGroup(
            id: $group->id,
            code: $group->code,
            capacity: $group->capacity,
            quotas: $group->quotas->mapWithKeys(fn (GroupQuota $quota): array => [$quota->career_id => $quota->max_seats])->all(),
            sessions: $group->sessions->map(fn (GroupSession $session): AdvisorSession => new AdvisorSession(
                id: $session->id,
                day: $session->day,
                startsAt: $session->starts_at,
                endsAt: $session->ends_at,
                room: $session->room === null ? null : new AdvisorRoom($session->room->id, $session->room->code, $session->room->capacity),
                largerRooms: $larger[$session->id] ?? [],
            ))->all(),
            held: (int) ($held[$group->id] ?? 0),
            teacherName: $group->teacher?->name,
        ))->all();

        return new ReanalysisSuggestions(
            suggestions: $this->advisor->advise($block->rows, $advisorGroups, $careerCodes, $settings, $this->opening($block, $subject, $campusId)),
            groups: $advisorGroups,
            models: $groups->keyBy('id')->all(),
            seats: AdvisorGroup::seatsByCareer($advisorGroups),
            careerCodes: $careerCodes,
            threshold: $settings->highlightThreshold,
        );
    }

    /**
     * Los salones más grandes libres a la hora de cada sesión de los grupos con salón en todas sus sesiones, por id de la
     * sesión: los salones del campus, las sesiones con salón, los bloqueos y los préstamos vivos de esos días, en cuatro
     * consultas.
     *
     * @param  Collection<int, Group>  $groups
     * @return array<string, list<AdvisorRoom>>
     */
    private function largerRooms(AcademicLoad $load, Subject $subject, ?string $campusId, Collection $groups): array
    {
        $roomed = $groups->filter(fn (Group $group): bool => $group->sessions->isNotEmpty() && $group->sessions->every(fn (GroupSession $session): bool => $session->room_id !== null));

        if ($campusId === null || $roomed->isEmpty()) {
            return [];
        }
        $days = $roomed->flatMap(fn (Group $group) => $group->sessions->pluck('day'))->unique()->values()->all();
        $needsLab = (bool) $subject->needs_lab;
        $rooms = Room::query()
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->with(['department' => fn (Relation $query) => $query->withTrashed()])
            ->orderBy('capacity')
            ->orderBy('code')
            ->get();
        $busy = GroupSession::query()
            ->where('term_id', $load->term_id)
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->whereNotNull('room_id')
            ->whereIn('day', $days)
            ->toBase()
            ->get(['id', 'room_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('room_id');
        $blocks = RoomBlock::query()
            ->whereIn('room_id', $rooms->modelKeys())
            ->whereIn('day', $days)
            ->where(fn (Builder $query) => $query->whereNull('term_id')->orWhere('term_id', $load->term_id))
            ->toBase()
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('room_id');
        $loans = InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $load->term_id)
            ->where('campus_id', $campusId)
            ->whereIn('status', AgreementStatus::liveValues())
            ->whereIn('day', $days)
            ->toBase()
            ->get(['group_id', 'room_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('room_id');

        $larger = [];

        foreach ($roomed as $group) {
            foreach ($group->sessions as $session) {
                $overlaps = fn (object $row): bool => (int) $row->day === $session->day
                    && Minutes::overlap(Minutes::of($row->starts_at), Minutes::of($row->ends_at), Minutes::of($session->starts_at), Minutes::of($session->ends_at));
                $practice = $session->kind === SessionKind::Practica && $needsLab;

                $larger[$session->id] = $rooms
                    ->filter(fn (Room $room): bool => $room->capacity > $session->room->capacity
                        && ($practice ? $room->type->isPracticeSpace() : $room->type === RoomType::Aula)
                        && ! ($busy[$room->id] ?? collect())->contains(fn (object $row): bool => $row->id !== $session->id && $overlaps($row))
                        && ! ($blocks[$room->id] ?? collect())->contains($overlaps)
                        && ! ($loans[$room->id] ?? collect())->contains(fn (object $row): bool => $row->group_id !== $group->id && $overlaps($row)))
                    ->map(fn (Room $room): AdvisorRoom => new AdvisorRoom(
                        $room->id,
                        $room->code,
                        $room->capacity,
                        $room->department_id !== null && $room->department_id !== $group->department_id ? ($room->department?->name ?? 'su departamento') : null,
                    ))
                    ->sort(fn (AdvisorRoom $a, AdvisorRoom $b): int => [$a->loanFrom !== null, $a->capacity, $a->code] <=> [$b->loanFrom !== null, $b->capacity, $b->code])
                    ->values()
                    ->all();
            }
        }

        return $larger;
    }

    /**
     * Con qué se abriría un grupo en el bloque (tarea 8.3b): la capacidad de GroupCapacity y el plan vigente de cada
     * carrera del bloque que lleva la materia, con su semestre (como el diff, ReanalysisDiffQuery). Null en un bloque sin
     * campus (modalidad sin salones): ahí el grupo se abre en Horarios.
     */
    private function opening(ReanalysisBlock $block, Subject $subject, ?string $campusId): ?NewGroupBase
    {
        if ($campusId === null || $block->campus === null) {
            return null;
        }

        $plans = StudyPlanSubject::query()
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->where('study_plans.is_current', true)
            ->whereNull('study_plans.deleted_at')
            ->whereIn('study_plans.career_id', array_map(fn (ReanalysisRow $row): string => $row->careerId, $block->rows))
            ->where('study_plan_subjects.subject_id', $subject->id)
            ->whereNull('study_plan_subjects.deleted_at')
            ->orderBy('study_plans.code')
            ->toBase()
            ->get(['study_plans.career_id', 'study_plans.id', 'study_plan_subjects.semester'])
            ->unique('career_id')
            ->mapWithKeys(fn (object $row): array => [$row->career_id => ['studyPlanId' => $row->id, 'semester' => max(1, (int) $row->semester)]])
            ->all();

        return new NewGroupBase($subject->id, $subject->code, $campusId, $block->campus->code, GroupCapacity::for($subject, $campusId, SuggestedGroups::fromSettings()), $plans);
    }
}
