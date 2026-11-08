<?php

namespace App\Modules\Scheduling\Database\Seeders;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Faculty\Models\Teacher;
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
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\ValidationScope;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Horarios, docentes y salones de prueba para los grupos que ya creó la aceptación de las propuestas (tarea 3.4).
 * Corre después de AcademicLoadDemoSeeder. Escribe las sesiones directo con el modelo (las Actions del editor de
 * horarios todavía no existen); el docente se escribe directo contra una foto del campus que se va actualizando (ver
 * assignTeacher()) y la clave pasa por AssignGroupCode. Mientras
 * siembra, los eventos que encolarían un recálculo por cada cambio (GroupScheduleChanged, GroupTeacherChanged,
 * GroupCancelled, GroupsCreated) se descartan; al final corre SyncScheduleConflicts una vez por campus.
 *
 * Por cada campus de `$campuses`, con los días y la jornada de su perfil y bloques de una hora, reparte los grupos
 * del periodo `$term` (con `ceil`, así cada caso tiene al menos un grupo si su proporción es mayor que cero):
 *
 * - 3 % cancelados (`cancelled_groups`) y 30 % confirmados (`confirmed_groups`, con docente); el resto, planeados;
 * - 10 % sin horario (`unscheduled_groups`) y 5 % con una hora de menos (`short_groups`, HC-12);
 * - 20 % sin docente (`teacherless_groups`); a los demás, un docente adscrito al departamento del grupo, probando el
 *   siguiente si la base lo rechaza por HC-01;
 * - `overlap_pairs` parejas (4 con el perfil demo, 40 con el massive) de materias con un solo grupo en el mismo plan y
 *   semestre, encimadas a propósito (HC-03);
 * - docente sin HC-08 ni HC-09 salvo el 3 % de los grupos (`teacher_conflict_groups`) que los rompe a propósito; sin
 *   candidato que sirva, el grupo queda sin docente;
 * - salón en el 75 % de las sesiones (`room_sessions`, solo si el campus usa salones): de capacidad suficiente y del
 *   tipo correcto; el 6 % va a un salón chico (`small_room_sessions`, HC-05) y el 25 % de las prácticas de materias con
 *   laboratorio a un aula (`lab_in_aula_sessions`, HC-06); el 3 % (`foreign_room_sessions`) va a un salón de otro departamento (HC-10). Los demás son salones sin dueño o del propio departamento, y las prácticas de materias con laboratorio van a laboratorio, taller o sala de cómputo. Un salón ocupado a esa hora se salta (la base lo rechaza,
 *   HC-02), así que no hay empalmes de salón.
 *
 * El horario sale de las horas de la materia (teoría más práctica) en sesiones de dos horas y una última de lo que
 * sobre, en días distintos y con la misma hora de inicio para que la letra de la clave tenga sentido. Los grupos del
 * mismo plan y semestre se acomodan a horas distintas: el primer grupo de cada materia en la mañana y el segundo en la
 * tarde, así el alumno tipo tiene una combinación válida. Las prácticas de materias con laboratorio van en sesiones
 * `practica`.
 *
 * Además, los `clean_departments` departamentos con más grupos de cada campus (3 con el perfil demo, 4 con el massive)
 * quedan limpios para que su carga pueda pasar a asignación de docentes: todos sus grupos activos tienen sesiones con
 * exactamente sus horas, sin parejas encimadas, salones chicos ni prácticas en aula, con salones sin dueño o de su
 * propio departamento y sin docente (los asigna después el seeder de Staffing). Los demás departamentos conservan la
 * variedad de arriba, y siempre queda al menos uno con choques duros. Las proporciones se calculan sobre los grupos de
 * los departamentos no limpios. `cleanDepartments()` devuelve los códigos por campus y se imprimen al sembrar.
 *
 * Determinista con `$seed` sobre los mismos datos. Idempotente: un grupo que ya tiene sesiones no se toca, ni se le
 * cambia un docente que ya tiene. Se niega a correr en producción.
 */
final class SchedulingDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const array DEFAULT_RATIOS = [
        'cancelled_groups' => 0.03,
        'confirmed_groups' => 0.30,
        'unscheduled_groups' => 0.10,
        'short_groups' => 0.05,
        'teacherless_groups' => 0.20,
        'room_sessions' => 0.75,
        'small_room_sessions' => 0.06,
        'lab_in_aula_sessions' => 0.25,
        'foreign_room_sessions' => 0.03,
        'teacher_conflict_groups' => 0.03,
    ];

    private const array OVERLAP_PAIRS = ['demo' => 4, 'massive' => 40];

    private const array CLEAN_DEPARTMENTS = ['demo' => 3, 'massive' => 4];

    /** Docentes candidatos que se revisan por grupo antes de dejarlo sin docente. */
    private const int TEACHER_CANDIDATES = 15;

    private Randomizer $random;

    /** @var array<string, list<string>> campus => códigos de los departamentos limpios */
    private array $cleanByCampus = [];

    /** @var array<string, true> */
    private array $cleanIds = [];

    /** @var array<string, float|int> */
    private array $ratios;

    /** @var array<string, list<string>> departamento => docentes */
    private array $teachersByDepartment = [];

    /** @var array<string, int> departamento => siguiente docente a probar */
    private array $teacherCursor = [];

    /** @var list<Room> */
    private array $rooms = [];

    /** @var array<string, array<string, true>> salón => celdas `día:hora` ocupadas */
    private array $roomBusy = [];

    /**
     * @param  list<string>  $campuses  claves de campus
     * @param  array<string, float|int>  $ratios  sobrescribe las proporciones y `overlap_pairs`
     *
     * @throws RuntimeException en producción o si el periodo no existe
     */
    public function run(string $profile = 'demo', array $campuses = ['C2'], string $term = '20271', int $seed = 2027, array $ratios = []): void
    {
        $this->refuseProduction();
        $this->random = new Randomizer(new Mt19937($seed));
        $this->ratios = [...self::DEFAULT_RATIOS, 'overlap_pairs' => self::OVERLAP_PAIRS[$profile] ?? self::OVERLAP_PAIRS['demo'], 'clean_departments' => self::CLEAN_DEPARTMENTS[$profile] ?? self::CLEAN_DEPARTMENTS['demo'], ...$ratios];
        $this->cleanByCampus = [];

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe: corre antes DemoTermSeeder.");
        $user = User::query()->where('username', 'admin')->first() ?? User::query()->first()
            ?? throw new RuntimeException('Hace falta un usuario para la bitácora: corre antes AdminUserSeeder.');

        $found = Campus::query()->whereIn('code', $campuses)->orderBy('code')->get();

        Event::fakeFor(function () use ($found, $termModel, $user): void {
            foreach ($found as $campus) {
                $this->seedCampus($termModel, $campus, $user);
            }
        }, [GroupScheduleChanged::class, GroupTeacherChanged::class, GroupCancelled::class, GroupsCreated::class]);

        foreach ($found as $campus) {
            app(SyncScheduleConflicts::class)->handle($termModel, $campus);
        }
    }

    /**
     * Códigos de los departamentos que quedaron limpios en la última corrida, por clave de campus.
     *
     * @return array<string, list<string>>
     */
    public function cleanDepartments(): array
    {
        return $this->cleanByCampus;
    }

    /**
     * Los departamentos con más grupos del campus (contando los cancelados, para que no cambie al repetir), sin dejar a todos limpios.
     *
     * @param  Collection<int, Group>  $groups
     * @return list<string> ids de departamento
     */
    private function pickClean($groups): array
    {
        $counts = $groups->countBy('department_id')->all();
        $codes = Department::query()->withTrashed()->whereIn('id', array_keys($counts))->pluck('code', 'id')->all();
        $wanted = min((int) $this->ratios['clean_departments'], count($counts) - 1);

        uksort($counts, fn (string $a, string $b): int => [$counts[$b], $codes[$a] ?? $a] <=> [$counts[$a], $codes[$b] ?? $b]);

        return array_slice(array_keys($counts), 0, max(0, $wanted));
    }

    private function seedCampus(Term $term, Campus $campus, User $user): void
    {
        $groups = Group::query()->with('subject')
            ->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->orderBy('id')->get();

        if ($groups->isEmpty()) {
            return;
        }

        $this->loadTeachers();
        $this->loadRooms($term, $campus);

        $cleanIds = $this->pickClean($groups);
        $this->cleanIds = array_fill_keys($cleanIds, true);
        $this->cleanByCampus[$campus->code] = array_values(Department::query()->withTrashed()->whereIn('id', $cleanIds)->orderBy('code')->pluck('code')->all());
        $this->command?->info("Departamentos limpios en {$campus->code}: ".($this->cleanByCampus[$campus->code] === [] ? 'ninguno' : implode(', ', $this->cleanByCampus[$campus->code])).'.');

        $plan = $this->plan($groups->reject(fn (Group $group): bool => isset($this->cleanIds[$group->department_id]))->values());
        $profile = $campus->profile;
        $touched = [];
        $placements = [];
        $occupied = [];
        $subjectsSeen = [];
        $slots = [];

        $ordered = $groups->sortBy(fn (Group $group): string => "{$group->study_plan_id}|{$group->semester}|{$group->subject_id}|{$group->id}")->values();

        foreach ($ordered as $group) {
            $cohort = "{$group->study_plan_id}|{$group->semester}";
            $variant = $subjectsSeen[$cohort][$group->subject_id] ?? 0;
            $subjectsSeen[$cohort][$group->subject_id] = $variant + 1;
            $slots[$group->id] = [$cohort, $variant, count($subjectsSeen[$cohort]) - 1];
        }

        $passes = [
            $ordered->reject(fn (Group $group): bool => isset($this->cleanIds[$group->department_id])),
            $ordered->filter(fn (Group $group): bool => isset($this->cleanIds[$group->department_id])),
        ];

        foreach ($passes as $index => $pass) {
            if ($index === 1) {
                $this->placeFollowers($plan['follower'], $groups, $placements, $profile, $touched, $slots, $occupied);
            }

            foreach ($pass as $group) {
                [$cohort, $variant, $ordinal] = $slots[$group->id];
                $clean = isset($this->cleanIds[$group->department_id]);

                if ($group->sessions()->exists() || isset($plan['unscheduled'][$group->id]) || isset($plan['follower'][$group->id]) || $this->hoursOf($group) === 0) {
                    continue;
                }

                $pieces = $this->pieces($group, isset($plan['short'][$group->id]), $profile->days);
                $placement = $this->place($pieces, $profile->days, $profile->dayStart, $profile->dayEnd, $occupied["{$cohort}|{$variant}"], $variant, $ordinal);
                $placements[$group->id] = $placement;
                $this->persist($group, $placement, $profile->usesRooms, $clean);
                $touched[$group->id] = $group;
            }
        }

        foreach ($touched as $group) {
            app(AssignGroupCode::class)->handle($group);
        }

        $validator = app(ScheduleValidator::class);
        $validator->forget();
        $state = $validator->stateFor($term->id, $campus->id);
        $state = $state->withTeachers(app(ScheduleStateQuery::class)->teachers($term, array_values(array_unique(array_merge(...array_values($this->teachersByDepartment ?: [[]]))))));

        foreach ($groups as $group) {
            if (! isset($this->cleanIds[$group->department_id]) && ! isset($plan['cancelled'][$group->id]) && ! isset($plan['teacherless'][$group->id]) && $group->teacher_id === null) {
                $state = $this->assignTeacher($group, $validator, $state);
            }

            $status = match (true) {
                isset($plan['cancelled'][$group->id]) => GroupStatus::Cancelled,
                isset($plan['confirmed'][$group->id]) => GroupStatus::Confirmed,
                default => null,
            };

            if ($status !== null && $group->status === GroupStatus::Planned) {
                $group->update(['status' => $status]);
            }
        }
    }

    /**
     * Acomoda a cada seguidor encima de su líder y lo registra como ocupado en el plan y semestre.
     *
     * @param  array<string, string>  $followers
     * @param  Collection<int, Group>  $groups
     * @param  array<string, list<array{day: int, start: int, hours: int, kind: SessionKind}>>  $placements
     * @param  array<string, Group>  $touched
     * @param  array<string, array{0: string, 1: int, 2: int}>  $slots
     * @param  array<string, array<string, true>>  $occupied
     */
    private function placeFollowers(array $followers, $groups, array &$placements, CampusProfile $profile, array &$touched, array $slots, array &$occupied): void
    {
        foreach ($followers as $followerId => $leaderId) {
            $follower = $groups->firstWhere('id', $followerId);

            if ($follower === null || isset($placements[$followerId]) || ! isset($placements[$leaderId]) || $follower->sessions()->exists()) {
                continue;
            }

            $placement = $this->overlapping($this->pieces($follower, false, $profile->days), $placements[$leaderId], $profile->days);
            $this->persist($follower, $placement, $profile->usesRooms);
            $touched[$followerId] = $follower;
            $this->occupy($placement, $occupied["{$slots[$followerId][0]}|{$slots[$followerId][1]}"]);
        }
    }

    /**
     * Reparte los grupos en los casos de la docblock; cada conjunto es `id => true`.
     *
     * @param  Collection<int, Group>  $groups
     * @return array{cancelled: array<string, true>, unscheduled: array<string, true>, short: array<string, true>, confirmed: array<string, true>, teacherless: array<string, true>, follower: array<string, string>}
     */
    private function plan($groups): array
    {
        $total = $groups->count();
        $ids = $this->random->shuffleArray($groups->pluck('id')->all());

        $cancelled = $this->carve($ids, $total, 'cancelled_groups');
        $unscheduled = $this->carve($ids, $total, 'unscheduled_groups');
        $short = $this->carve($ids, $total, 'short_groups');
        $normal = $ids;

        $confirmedPool = $normal;
        $confirmed = $this->carve($confirmedPool, $total, 'confirmed_groups');

        $teacherlessPool = $this->random->shuffleArray(array_values(array_diff($groups->pluck('id')->all(), $cancelled, $confirmed)));
        $teacherless = $this->carve($teacherlessPool, $total, 'teacherless_groups');

        return [
            'cancelled' => array_fill_keys($cancelled, true),
            'unscheduled' => array_fill_keys($unscheduled, true),
            'short' => array_fill_keys($short, true),
            'confirmed' => array_fill_keys($confirmed, true),
            'teacherless' => array_fill_keys($teacherless, true),
            'follower' => $this->overlapPairs($groups, $normal),
        ];
    }

    /**
     * Saca del inicio de `$pool` la parte `$key` de `$total`, redondeada hacia arriba.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function carve(array &$pool, int $total, string $key): array
    {
        $take = (float) $this->ratios[$key] > 0 ? (int) ceil($total * (float) $this->ratios[$key]) : 0;
        $taken = array_slice($pool, 0, $take);
        $pool = array_slice($pool, $take);

        return $taken;
    }

    /**
     * Parejas de grupos de materias con un solo grupo en el periodo y campus, del mismo plan y semestre, entre los
     * grupos normales: devuelve `seguidor => líder`. El seguidor se acomoda encima del líder (HC-03).
     *
     * @param  Collection<int, Group>  $groups
     * @param  list<string>  $normal
     * @return array<string, string>
     */
    private function overlapPairs($groups, array $normal): array
    {
        $wanted = (int) $this->ratios['overlap_pairs'];

        if ($wanted <= 0) {
            return [];
        }

        $perSubject = $groups->countBy('subject_id');
        $eligible = array_flip($normal);
        $byCohort = $groups
            ->filter(fn (Group $group): bool => isset($eligible[$group->id]) && $perSubject[$group->subject_id] === 1 && $this->hoursOf($group) > 0)
            ->sortBy('id')
            ->groupBy(fn (Group $group): string => "{$group->study_plan_id}|{$group->semester}");

        $pairs = [];

        foreach ($byCohort as $cohort) {
            if (count($pairs) >= $wanted) {
                break;
            }

            if ($cohort->count() >= 2) {
                $pairs[$cohort->values()[1]->id] = $cohort->values()[0]->id;
            }
        }

        return $pairs;
    }

    private function hoursOf(Group $group): int
    {
        return (int) $group->subject->theory_hours + (int) $group->subject->practice_hours;
    }

    /**
     * Las franjas semanales del grupo: `[horas, tipo]`, con la práctica de las materias con laboratorio aparte.
     * Un grupo `$short` se queda con una hora de menos (o de más, si la materia es de una sola hora).
     *
     * @param  list<int>  $days
     * @return list<array{0: int, 1: SessionKind}>
     */
    private function pieces(Group $group, bool $short, array $days): array
    {
        $subject = $group->subject;
        $theory = (int) $subject->theory_hours;
        $practice = (int) $subject->practice_hours;
        $limit = count($days);

        $pieces = $subject->needs_lab && $practice > 0 && $theory > 0
            ? [...$this->split($theory, SessionKind::Teoria), ...$this->split($practice, SessionKind::Practica)]
            : $this->split($theory + $practice, SessionKind::Teoria);

        if (count($pieces) > $limit) {
            $pieces = $this->split($theory + $practice, SessionKind::Teoria, $limit);
        }

        if ($short) {
            $last = count($pieces) - 1;
            $pieces[$last][0] += $pieces[$last][0] === 1 && $this->hoursOf($group) === 1 ? 1 : -1;

            if ($pieces[$last][0] === 0) {
                array_pop($pieces);
            }
        }

        return $pieces;
    }

    /**
     * @return list<array{0: int, 1: SessionKind}>
     */
    private function split(int $hours, SessionKind $kind, ?int $max = null): array
    {
        $count = min((int) ceil($hours / 2), $max ?? PHP_INT_MAX);
        $pieces = [];

        for ($index = 0; $index < $count; $index++) {
            $piece = (int) ceil($hours / ($count - $index));
            $pieces[] = [$piece, $kind];
            $hours -= $piece;
        }

        return $pieces;
    }

    /**
     * Busca la hora de inicio y los días donde todas las franjas caben sin chocar con lo que ya tiene el plan y
     * semestre en esa variante; si no hay hueco, deja la primera opción (queda un empalme).
     *
     * @param  list<array{0: int, 1: SessionKind}>  $pieces
     * @param  list<int>  $days
     * @param  array<string, true>|null  $occupied
     * @return list<array{day: int, start: int, hours: int, kind: SessionKind}>
     */
    private function place(array $pieces, array $days, string $dayStart, string $dayEnd, ?array &$occupied, int $variant, int $ordinal): array
    {
        $occupied ??= [];
        $first = (int) substr($dayStart, 0, 2);
        $end = (int) substr($dayEnd, 0, 2);
        $morning = range($first, max($first, min(13, $end - 1)));
        $afternoon = $end > 14 ? range(14, $end - 1) : [];
        $bands = $variant % 2 === 0 ? [$morning, $afternoon] : [$afternoon, $morning];

        $starts = [];

        foreach ($bands as $band) {
            if ($band === []) {
                continue;
            }

            $offset = ($ordinal * 2) % count($band);
            array_push($starts, ...array_slice($band, $offset), ...array_slice($band, 0, $offset));
        }

        $longest = max(array_map(fn (array $piece): int => $piece[0], $pieces));
        $patterns = $this->dayPatterns(count($pieces), $days);
        $fallback = null;

        foreach ($starts as $start) {
            if ($start + $longest > $end) {
                continue;
            }

            foreach ($patterns as $pattern) {
                $placement = $this->placement($pieces, $pattern, $start);
                $fallback ??= $placement;

                if ($this->isFree($placement, $occupied)) {
                    $this->occupy($placement, $occupied);

                    return $placement;
                }
            }
        }

        $placement = $fallback ?? $this->placement($pieces, $patterns[0] ?? [$days[0]], $first);
        $this->occupy($placement, $occupied);

        return $placement;
    }

    /**
     * @param  list<int>  $days
     * @return list<list<int>>
     */
    private function dayPatterns(int $count, array $days): array
    {
        $size = count($days);
        $step = (int) ceil($size / max(1, $count));
        $patterns = [];

        for ($from = 0; $from < $size; $from++) {
            $pattern = [];

            for ($index = 0; $index < $count; $index++) {
                $pattern[] = $days[($from + $index * $step) % $size];
            }

            if (count(array_unique($pattern)) === $count) {
                sort($pattern);
                $patterns[] = $pattern;
            }
        }

        return $patterns === [] ? [array_slice($days, 0, $count)] : $patterns;
    }

    /**
     * @param  list<array{0: int, 1: SessionKind}>  $pieces
     * @param  list<int>  $pattern
     * @return list<array{day: int, start: int, hours: int, kind: SessionKind}>
     */
    private function placement(array $pieces, array $pattern, int $start): array
    {
        $placement = [];

        foreach ($pieces as $index => [$hours, $kind]) {
            $placement[] = ['day' => $pattern[$index], 'start' => $start, 'hours' => $hours, 'kind' => $kind];
        }

        return $placement;
    }

    /**
     * La franja del seguidor sobre las del líder: mismo primer día y misma hora de inicio.
     *
     * @param  list<array{0: int, 1: SessionKind}>  $pieces
     * @param  list<array{day: int, start: int, hours: int, kind: SessionKind}>  $leader
     * @param  list<int>  $days
     * @return list<array{day: int, start: int, hours: int, kind: SessionKind}>
     */
    private function overlapping(array $pieces, array $leader, array $days): array
    {
        $pattern = array_values(array_unique([...array_column($leader, 'day'), ...$days]));

        return $this->placement($pieces, array_slice($pattern, 0, count($pieces)), $leader[0]['start']);
    }

    /**
     * @param  list<array{day: int, start: int, hours: int, kind: SessionKind}>  $placement
     * @param  array<string, true>  $occupied
     */
    private function isFree(array $placement, array $occupied): bool
    {
        foreach ($placement as $slot) {
            foreach (range($slot['start'], $slot['start'] + $slot['hours'] - 1) as $hour) {
                if (isset($occupied["{$slot['day']}:{$hour}"])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<array{day: int, start: int, hours: int, kind: SessionKind}>  $placement
     * @param  array<string, true>  $occupied
     */
    private function occupy(array $placement, array &$occupied): void
    {
        foreach ($placement as $slot) {
            foreach (range($slot['start'], $slot['start'] + $slot['hours'] - 1) as $hour) {
                $occupied["{$slot['day']}:{$hour}"] = true;
            }
        }
    }

    /**
     * Guarda las sesiones del grupo, con salón si el campus los usa y toca.
     *
     * @param  list<array{day: int, start: int, hours: int, kind: SessionKind}>  $placement
     */
    private function persist(Group $group, array $placement, bool $usesRooms, bool $clean = false): void
    {
        foreach ($placement as $slot) {
            $room = $usesRooms && $this->chance('room_sessions') ? $this->pickRoom($group, $slot, $clean) : null;
            $attributes = [
                'day' => $slot['day'],
                'starts_at' => sprintf('%02d:00', $slot['start']),
                'ends_at' => sprintf('%02d:00', $slot['start'] + $slot['hours']),
                'kind' => $slot['kind'],
            ];

            try {
                DB::transaction(fn () => $group->sessions()->create([...$attributes, 'room_id' => $room?->id]));
                $room !== null && $this->markBusy($room, $slot);
            } catch (QueryException) {
                $group->sessions()->create([...$attributes, 'room_id' => null]);
            }
        }
    }

    /**
     * @param  array{day: int, start: int, hours: int, kind: SessionKind}  $slot
     */
    private function pickRoom(Group $group, array $slot, bool $clean): ?Room
    {
        $isLabPractice = $slot['kind'] === SessionKind::Practica && $group->subject->needs_lab;
        $capacity = (int) $group->capacity;

        $mine = fn (Room $room): bool => $room->department_id === null || $room->department_id === $group->department_id;
        $practice = fn (Room $room): bool => $room->type->isPracticeSpace();

        $candidates = match (true) {
            $clean => $this->cleanRooms($group, $isLabPractice),
            $isLabPractice && $this->chance('lab_in_aula_sessions') => $this->roomsWhere(fn (Room $room): bool => ! $practice($room) && $mine($room)),
            $isLabPractice && $this->chance('foreign_room_sessions') => $this->roomsWhere(fn (Room $room): bool => $practice($room) && ! $mine($room) && $room->capacity >= $capacity),
            $isLabPractice => $this->roomsWhere(fn (Room $room): bool => $practice($room) && $mine($room) && $room->capacity >= $capacity),
            $this->chance('small_room_sessions') => $this->roomsWhere(fn (Room $room): bool => ! $practice($room) && $mine($room) && $room->capacity < $capacity),
            $this->chance('foreign_room_sessions') => $this->roomsWhere(fn (Room $room): bool => ! $practice($room) && ! $mine($room) && $room->capacity >= $capacity),
            default => $this->roomsWhere(fn (Room $room): bool => ! $practice($room) && $mine($room) && $room->capacity >= $capacity),
        };

        if ($candidates === []) {
            return null;
        }

        $offset = $this->random->getInt(0, count($candidates) - 1);

        foreach (array_keys($candidates) as $index) {
            $room = $candidates[($index + $offset) % count($candidates)];

            if (! $this->isBusy($room, $slot)) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Salones sin conflicto para un grupo limpio: de capacidad suficiente, del tipo que toca y sin dueño o del propio
     * departamento del grupo (HC-05, HC-06, HC-10).
     *
     * @return list<Room>
     */
    private function cleanRooms(Group $group, bool $isLabPractice): array
    {
        return $this->roomsWhere(fn (Room $room): bool => $room->type->isPracticeSpace() === $isLabPractice
            && $room->capacity >= (int) $group->capacity
            && ($room->department_id === null || $room->department_id === $group->department_id));
    }

    /**
     * @param  callable(Room): bool  $filter
     * @return list<Room>
     */
    private function roomsWhere(callable $filter): array
    {
        return array_values(array_filter($this->rooms, $filter));
    }

    /**
     * @param  array{day: int, start: int, hours: int, kind: SessionKind}  $slot
     */
    private function isBusy(Room $room, array $slot): bool
    {
        foreach (range($slot['start'], $slot['start'] + $slot['hours'] - 1) as $hour) {
            if (isset($this->roomBusy[$room->id]["{$slot['day']}:{$hour}"])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{day: int, start: int, hours: int, kind: SessionKind}  $slot
     */
    private function markBusy(Room $room, array $slot): void
    {
        foreach (range($slot['start'], $slot['start'] + $slot['hours'] - 1) as $hour) {
            $this->roomBusy[$room->id]["{$slot['day']}:{$hour}"] = true;
        }
    }

    private function chance(string $key): bool
    {
        $ratio = (float) $this->ratios[$key];

        return $ratio >= 1.0 || ($ratio > 0.0 && $this->random->getInt(1, 10000) <= (int) round($ratio * 10000));
    }

    private function loadTeachers(): void
    {
        $this->teachersByDepartment = TeacherDepartment::query()
            ->whereIn('teacher_id', Teacher::query()->where('is_active', true)->select('id'))
            ->orderBy('teacher_id')
            ->get(['teacher_id', 'department_id'])
            ->groupBy('department_id')
            ->map(fn ($rows): array => $rows->pluck('teacher_id')->all())
            ->all();
    }

    private function loadRooms(Term $term, Campus $campus): void
    {
        $this->rooms = Room::query()->where('campus_id', $campus->id)->where('is_active', true)->orderBy('code')->get()->all();
        $this->roomBusy = [];

        RoomBlock::query()->where(fn ($query) => $query->whereNull('term_id')->orWhere('term_id', $term->id))->whereIn('room_id', collect($this->rooms)->pluck('id'))
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->each(function (RoomBlock $block): void {
                $from = (int) substr((string) $block->starts_at, 0, 2);
                $to = (int) ceil((int) substr((string) $block->ends_at, 0, 2) + (int) substr((string) $block->ends_at, 3, 2) / 60);

                foreach (range($from, max($from, $to - 1)) as $hour) {
                    $this->roomBusy[$block->room_id]["{$block->day}:{$hour}"] = true;
                }
            });

        GroupSession::query()->where('term_id', $term->id)->where('campus_id', $campus->id)->where('is_active', true)->whereNotNull('room_id')
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->each(function (GroupSession $session): void {
                $from = (int) substr((string) $session->starts_at, 0, 2);
                $to = (int) ceil((int) substr((string) $session->ends_at, 0, 2) + (int) substr((string) $session->ends_at, 3, 2) / 60);

                foreach (range($from, max($from, $to - 1)) as $hour) {
                    $this->roomBusy[$session->room_id]["{$session->day}:{$hour}"] = true;
                }
            });
    }

    /**
     * Le da a un grupo un docente adscrito a su departamento que no rompa HC-08 ni HC-09; solo una parte pequeña
     * (`teacher_conflict_groups`) puede romperlos a propósito. Si ninguno sirve, el grupo se queda sin docente.
     *
     * Pregunta contra la foto del campus que se leyó una sola vez y que va actualizando con cada asignación
     * (`withTeacher()`), y escribe `teacher_id` directo, sin SetGroupTeacher: con cientos de grupos, la Action relee la
     * foto completa en cada asignación (correcto para una persona, demasiado lento para sembrar el masivo). HC-01 lo
     * sigue cuidando la exclusión de la base; los eventos de Scheduling están apagados durante la siembra.
     */
    private function assignTeacher(Group $group, ScheduleValidator $validator, ScheduleState $state): ScheduleState
    {
        $pool = $this->teachersByDepartment[$group->department_id] ?? [];

        if ($pool === []) {
            return $state;
        }

        $cursor = $this->teacherCursor[$group->department_id] ?? 0;
        $ids = array_map(fn (int $step): string => $pool[($cursor + $step) % count($pool)], range(0, min(self::TEACHER_CANDIDATES, count($pool)) - 1));
        $deliberate = $this->chance('teacher_conflict_groups');

        foreach ($ids as $id) {
            if ($state->teacher($id) === null) {
                continue;
            }

            $result = new ValidationResult($validator->validate(
                $state->withTeacher($group->id, $id),
                new ValidationScope([$group->id], [$id]),
                ScheduleValidator::TEACHER_RULES,
            )->forGroup($group->id));

            if ($deliberate ? $result->forRule('HC-01') !== [] || ! $result->hasHard() : $result->hasHard()) {
                continue;
            }

            try {
                DB::transaction(fn () => $group->update(['teacher_id' => $id]));
            } catch (QueryException) {
                continue;
            }

            $this->teacherCursor[$group->department_id] = array_search($id, $pool, true) + 1;

            return $state->withTeacher($group->id, $id);
        }

        return $state;
    }
}
