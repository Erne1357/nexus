<?php

namespace App\Modules\Scheduling\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Scheduling\Actions\AssignGroupCode;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Support\CleanSchedulePlanner;
use App\Modules\Scheduling\Support\RoomSupply;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * El horario limpio de la demo por puntos (lleva al punto «asignacion», #338): a todo grupo activo del periodo que
 * todavía no tiene sesiones le da su horario completo, sin choques duros, sin docente y sin salón, con
 * CleanSchedulePlanner. Las prácticas con laboratorio de mayor capacidad van primero, porque son las que menos
 * lugares tienen. El tope de grupos simultáneos de cada departamento es el 70 % de sus docentes activos de
 * adscripción primaria (al menos uno, también para un departamento sin docentes) y se cuenta en todos los campus, que
 * se colocan en orden de clave. Los grupos que ya tienen sesiones no se mueven, pero cuentan. Primero calcula todos los
 * campus y solo si todo cabe escribe, en una transacción, las sesiones en bloque, sin los eventos que encolarían un
 * recálculo por grupo; luego pone la clave a cada grupo nuevo y recalcula los choques una vez por campus. Si un grupo no
 * cabe, se detiene con el grupo y el motivo, sin escribir nada. Idempotente. No corre en producción.
 */
final class CleanScheduleDemoSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * @param  list<string>  $campuses  claves de campus
     *
     * @throws RuntimeException en producción, sin el periodo o si un grupo no cabe sin choque
     */
    public function run(array $campuses = ['C2'], string $term = '20271'): void
    {
        $this->refuseProduction();

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe: corre antes DemoTermSeeder.");
        $found = Campus::query()->whereIn('code', $campuses)->orderBy('code')->get();
        $planner = new CleanSchedulePlanner($this->departmentCaps($termModel));
        $plans = $found->mapWithKeys(fn (Campus $campus): array => [$campus->code => $this->planCampus($termModel, $campus, $planner)]);

        Event::fakeFor(function () use ($found, $plans): void {
            DB::transaction(function () use ($found, $plans): void {
                foreach ($found as $campus) {
                    $this->persist($campus, ...$plans[$campus->code]);
                }
            });
        }, [GroupScheduleChanged::class, GroupTeacherChanged::class, GroupCancelled::class, GroupsCreated::class]);

        foreach ($found as $campus) {
            app(SyncScheduleConflicts::class)->handle($termModel, $campus);
        }
    }

    /**
     * El horario de los grupos sin sesiones del campus, con los que ya tienen sesiones como fijos.
     *
     * @return array{0: Collection<int, Group>, 1: array<string, list<array{day: int, start: int, end: int, kind: SessionKind}>>}
     */
    private function planCampus(Term $term, Campus $campus, CleanSchedulePlanner $planner): array
    {
        $profile = $campus->profile;
        $groups = Group::query()->with(['subject', 'sessions'])
            ->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->where('status', '!=', GroupStatus::Cancelled)
            ->get()
            ->sortBy(fn (Group $group): array => [
                $group->subject->needs_lab && $group->subject->practice_hours > 0 ? 0 : 1,
                -$group->capacity,
                "{$group->study_plan_id}|{$group->semester}",
                $group->subject->code,
                $group->code ?? '',
                $group->id,
            ])
            ->values();

        $pending = $groups->filter(fn (Group $group): bool => $group->sessions->isEmpty())->values();

        if ($pending->isEmpty()) {
            return [$pending, []];
        }

        $validator = app(ScheduleValidator::class);
        $validator->forget();
        $supply = $profile->usesRooms ? RoomSupply::fromState($validator->stateFor($term->id, $campus->id), $campus->id) : null;

        $plan = $planner->plan(
            $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'label' => "el grupo de {$group->subject->code} {$group->subject->name} ({$campus->code}, semestre {$group->semester}, {$group->capacity} lugares)",
                'cohort' => "{$campus->id}|{$group->study_plan_id}|{$group->semester}",
                'subject' => $group->subject_id,
                'department' => $group->department_id,
                'capacity' => (int) $group->capacity,
                'pieces' => CleanSchedulePlanner::pieces((int) $group->subject->theory_hours, (int) $group->subject->practice_hours, (bool) $group->subject->needs_lab, count($profile->days)),
            ] + ($group->sessions->isEmpty() ? [] : ['sessions' => $group->sessions->map(fn (GroupSession $session): array => [
                'day' => $session->day,
                'start' => Minutes::of($session->starts_at),
                'end' => Minutes::of($session->ends_at),
                'kind' => $session->kind,
                'roomId' => $session->room_id,
            ])->all()]))->all(),
            $profile->days,
            Minutes::of($profile->dayStart),
            Minutes::of($profile->dayEnd),
            $profile->blockMinutes,
            $supply,
        );

        return [$pending, $plan];
    }

    /**
     * Escribe las sesiones del plan en bloque y pone la clave a cada grupo nuevo.
     *
     * @param  Collection<int, Group>  $groups
     * @param  array<string, list<array{day: int, start: int, end: int, kind: SessionKind}>>  $plan
     */
    private function persist(Campus $campus, Collection $groups, array $plan): void
    {
        $now = now();
        $rows = [];

        foreach ($plan as $groupId => $sessions) {
            foreach ($sessions as $session) {
                $rows[] = [
                    'group_id' => $groupId,
                    'day' => $session['day'],
                    'starts_at' => Minutes::format($session['start']),
                    'ends_at' => Minutes::format($session['end']),
                    'kind' => $session['kind']->value,
                    'room_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('group_sessions')->insert($chunk);
        }

        foreach ($groups as $group) {
            app(AssignGroupCode::class)->handle($group);
        }

        $this->command?->info(sprintf('Horario limpio en %s: %d grupos, %d sesiones.', $campus->code, $groups->count(), count($rows)));
    }

    /**
     * El tope de grupos simultáneos de cada departamento con grupos en el periodo: el 70 % de sus docentes activos de
     * adscripción primaria, al menos uno (un departamento sin docentes también tiene tope).
     *
     * @return array<string, int>
     */
    private function departmentCaps(Term $term): array
    {
        $teachers = TeacherDepartment::query()
            ->where('is_primary', true)
            ->whereHas('teacher', fn ($query) => $query->where('is_active', true))
            ->selectRaw('department_id, count(*) as teachers')
            ->groupBy('department_id')
            ->pluck('teachers', 'department_id');

        return Group::query()->where('term_id', $term->id)->distinct()->pluck('department_id')
            ->mapWithKeys(fn (string $department): array => [$department => max(1, (int) floor(($teachers[$department] ?? 0) * CleanSchedulePlanner::DEPARTMENT_SHARE))])
            ->all();
    }
}
