<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\AcademicLoad\Queries\ReanalysisRow;
use App\Modules\Demand\Enums\DemandBalance;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorGroup;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorRoom;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorSession;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupBase;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;

/**
 * Las sugerencias del re-análisis de una materia en un campus (tarea 8.3a; RA-04, RA-07, RA-09). Puro: recibe las filas del
 * diff de demanda del bloque (lo esperado por carrera, ReanalysisRow) y sus grupos vivos (AdvisorGroup, de
 * ReanalysisSuggestionsQuery), y no lee la base.
 *
 * Compara lo esperado de cada carrera con sus lugares **de ahora** (los cupos de los grupos vivos, no los de la versión
 * publicada), con la clase de RA-04 (`DemandSettings::balanceOf()`): así, lo que el jefe ya aplicó deja de sugerirse. Las
 * carreras de primer semestre no se clasifican (RA-09) y no dan sugerencias. Lo que propone (supuestos por confirmar):
 *
 * 1. **Faltante**, de la carrera con más faltante a la de menos: cada sugerencia suma hasta lo esperado y la siguiente
 *    solo se propone si con las anteriores la carrera sigue faltante (así, aplicar una no hace aparecer ni desaparecer
 *    las demás de golpe):
 *    - **Ampliar cupo** en cada grupo donde la carrera ya tiene lugares (por clave): primero lo que el grupo tiene libre
 *      (HC-07: capacidad menos lo repartido y lo que apartan sus propuestas) y luego, si hace falta, sube la capacidad
 *      hasta el salón más chico de sus sesiones (HC-05). Un grupo con alguna sesión sin salón no sube de capacidad. Una
 *      carrera sin lugares en el grupo no se agrega aquí: con otro departamento pide su acuerdo (ProposeGroupQuota).
 *    - **Cambiar salón** si aun así falta: en un grupo con salón en todas sus sesiones, cada sesión que no alcanza pasa al
 *      salón más chico que sí, libre a esa hora (los que no piden préstamo primero); si ninguno alcanza, al más grande.
 *      Dos grupos no se llevan el mismo salón a la misma hora.
 *    - **Abrir grupo** (tarea 8.3b), una por carrera que sigue faltante: un grupo nuevo suelto y sin docente, con la
 *      capacidad de GroupCapacity, la carrera como dueña (su plan vigente con la materia y su semestre) y lo que le falta
 *      como cupo, hasta la capacidad (`newGroup`, NewGroupSpec). El horario y el salón no los pone el asesor: los pide el
 *      drawer al motor al abrir la tarjeta (NewGroupPlacementQuery). Sin `$opening` (un bloque sin campus) o sin plan
 *      vigente de la carrera, la tarjeta solo avisa y se abre en Horarios.
 * 2. **Sobrante**: **cerrar un grupo** que tiene lugares de una carrera sobrante, si al cerrarlo ninguna carrera queda
 *    faltante. Los lugares que hacen falta (lo esperado menos lo que queda) pasan a los demás grupos donde la carrera ya
 *    tiene cupo, dentro de lo que tienen libre (sin subir su capacidad). Una carrera sin clasificar conserva todos sus
 *    lugares. Se prefiere un grupo sin docente y luego el de menos lugares. Uno con docente se sugiere con la advertencia
 *    de quién lo imparte y cuántas horas, pero no se aplica hasta quitarle el docente en Asignación docente
 *    (`blockedReason`), como pide CancelGroup. Una sola por bloque: después de cerrarlo se calcula otra vez.
 *
 * Las sugerencias se calculan una tras otra sobre el mismo estado (lo que una toma, la siguiente ya no lo tiene), para que
 * se puedan aplicar juntas; al aplicar, las Actions de la fase 3 vuelven a revisar todo con la base.
 */
final class ReanalysisAdvisor
{
    /** @var array<string, int> capacidad de cada grupo, al ir sugiriendo */
    private array $capacity = [];

    /** @var array<string, array<string, int>> cupos de cada grupo, al ir sugiriendo */
    private array $quotas = [];

    /** @var list<array{room: string, session: AdvisorSession}> los salones que ya tomó un cambio */
    private array $takenRooms = [];

    /** @var array<string, array{capacity: int, quotas: array<string, int>}> cada grupo como se leyó, antes de sugerir nada */
    private array $original = [];

    /**
     * @param  list<ReanalysisRow>  $rows
     * @param  list<AdvisorGroup>  $groups
     * @param  array<string, string>  $careerCodes  id => clave de las carreras con cupo que no están en las filas
     * @param  NewGroupBase|null  $opening  con qué se abriría un grupo nuevo en el bloque (tarea 8.3b)
     * @return list<ReanalysisSuggestion>
     */
    public function advise(array $rows, array $groups, array $careerCodes, DemandSettings $settings, ?NewGroupBase $opening = null): array
    {
        $this->capacity = [];
        $this->quotas = [];
        $this->takenRooms = [];
        $this->original = [];

        foreach ($groups as $group) {
            $this->capacity[$group->id] = $group->capacity;
            $this->quotas[$group->id] = $group->quotas;
            $this->original[$group->id] = ['capacity' => $group->capacity, 'quotas' => $group->quotas];
        }

        usort($groups, fn (AdvisorGroup $a, AdvisorGroup $b): int => $a->code <=> $b->code);

        $demand = [];

        foreach ($rows as $row) {
            $careerCodes[$row->careerId] = $row->careerCode;

            if ($row->balance !== null) {
                $demand[$row->careerId] = $row->expectedStudents;
            }
        }

        $seats = $this->seatsByCareer($groups);
        $shortfall = [];
        $surplus = [];

        foreach ($demand as $careerId => $expected) {
            $balance = $settings->balanceOf($expected, $seats[$careerId] ?? 0);

            if ($balance === DemandBalance::Shortfall) {
                $shortfall[$careerId] = $expected - ($seats[$careerId] ?? 0);
            } elseif ($balance === DemandBalance::Surplus) {
                $surplus[] = $careerId;
            }
        }

        uksort($shortfall, fn (string $a, string $b): int => [$shortfall[$b], $careerCodes[$a]] <=> [$shortfall[$a], $careerCodes[$b]]);

        $expand = [];
        $rooms = [];
        $missing = [];

        foreach ($shortfall as $careerId => $gap) {
            $expected = $demand[$careerId];
            $context = ['career' => $careerId, 'code' => $careerCodes[$careerId], 'expected' => $expected, 'seats' => $seats[$careerId] ?? 0, 'after' => false];
            $short = fn (int $gap): bool => $gap > 0 && $settings->balanceOf($expected, $expected - $gap) === DemandBalance::Shortfall;

            foreach ($groups as $group) {
                if ($short($gap) && isset($this->quotas[$group->id][$careerId]) && ($suggestion = $this->expand($group, $context, $gap)) !== null) {
                    $expand[] = $suggestion;
                    $gap -= $suggestion->seats;
                    $context = [...$context, 'seats' => $context['seats'] + $suggestion->seats, 'after' => true];
                }
            }

            foreach ($groups as $group) {
                if ($short($gap) && isset($this->quotas[$group->id][$careerId]) && ($suggestion = $this->changeRoom($group, $context, $gap)) !== null) {
                    $rooms[] = $suggestion;
                    $gap -= $suggestion->seats;
                    $context = [...$context, 'seats' => $context['seats'] + $suggestion->seats, 'after' => true];
                }
            }

            if ($short($gap)) {
                $missing[$careerId] = $gap;
            }
        }

        $touched = array_map(fn (ReanalysisSuggestion $suggestion): ?string => $suggestion->groupId, [...$expand, ...$rooms]);
        $close = $surplus === [] ? null : $this->close($groups, $touched, $surplus, $seats, $demand, $careerCodes, $settings);

        return [
            ...$expand,
            ...$rooms,
            ...array_map(fn (string $careerId, int $gap): ReanalysisSuggestion => $this->open($careerId, $careerCodes[$careerId], $gap, $groups, $opening), array_keys($missing), $missing),
            ...($close === null ? [] : [$close]),
        ];
    }

    /**
     * Ampliar el cupo de la carrera en el grupo con lo libre y, si hace falta, subiendo la capacidad hasta su salón.
     *
     * @param  array{career: string, code: string, expected: int, seats: int, after: bool}  $context
     */
    private function expand(AdvisorGroup $group, array $context, int $gap): ?ReanalysisSuggestion
    {
        $capacity = $this->capacity[$group->id];
        $used = array_sum($this->quotas[$group->id]);
        $free = max(0, $capacity - $used - $group->held);
        $limit = $group->roomLimit();
        $headroom = $limit === null ? 0 : max(0, $limit - $capacity);
        $room = $free + $headroom;

        if ($room <= 0) {
            return null;
        }

        $add = min($gap, $room);
        $from = $this->quotas[$group->id][$context['career']];
        $newCapacity = max($capacity, $used + $group->held + $add);
        $smallest = $limit === null ? null : $this->smallestRoom($group);

        $evidence = [
            $this->demandLine($context),
            "Grupo {$group->code}: {$used} de {$capacity} lugares repartidos".($group->held > 0 ? " y {$group->held} apartados" : '')
                .($smallest === null ? '' : " · salón {$smallest->code} de {$smallest->capacity} (HC-05)"),
        ];
        $effect = ["{$context['code']} pasa de {$from} a ".($from + $add)." lugares en el grupo {$group->code}."];

        if ($newCapacity > $capacity) {
            $effect[] = "La capacidad del grupo {$group->code} sube de {$capacity} a {$newCapacity}, dentro de su salón (HC-05, HC-07).";
        }

        $effect[] = $this->touches([$context['code']], $group->teacherName, 'que conserva su horario');
        $this->take($group, $context['career'], $add, $newCapacity);

        return new ReanalysisSuggestion(
            key: "expand_quota:{$group->id}:{$context['career']}",
            kind: ReanalysisSuggestionKind::ExpandQuota,
            title: "Ampliar el cupo de {$context['code']} en el grupo {$group->code} de {$from} a ".($from + $add),
            evidence: $evidence,
            effect: $effect,
            groupId: $group->id,
            careerId: $context['career'],
            seats: $add,
            maxSeats: $room,
            raisesCapacity: $newCapacity > $capacity,
            expected: $this->expectedOf([$group->id]),
        );
    }

    /**
     * Cambiar a salones más grandes las sesiones del grupo que no alcanzan, para subir su capacidad.
     *
     * @param  array{career: string, code: string, expected: int, seats: int, after: bool}  $context
     */
    private function changeRoom(AdvisorGroup $group, array $context, int $gap): ?ReanalysisSuggestion
    {
        $limit = $group->roomLimit();
        $capacity = $this->capacity[$group->id];
        $used = array_sum($this->quotas[$group->id]);

        if ($limit === null) {
            return null;
        }

        $first = $this->pickRooms($group, $used + $group->held + $gap);
        $reach = min(array_map(fn (AdvisorRoom $room): int => $room->capacity, $first));

        if ($reach <= max($capacity, $limit)) {
            return null;
        }

        $chosen = $this->pickRooms($group, $reach);
        $moves = [];

        foreach ($group->sessions as $session) {
            if ($chosen[$session->id]->id !== $session->room->id) {
                $moves[] = [
                    'sessionId' => $session->id, 'when' => $session->label(), 'from' => $session->room->code, 'fromId' => $session->room->id, 'to' => $chosen[$session->id],
                    'day' => $session->day, 'startsAt' => $session->startsAt, 'endsAt' => $session->endsAt,
                ];
                $this->takenRooms[] = ['room' => $chosen[$session->id]->id, 'session' => $session];
            }
        }

        $room = $reach - $used - $group->held;
        $add = min($gap, $room);
        $from = $this->quotas[$group->id][$context['career']];
        $newCapacity = max($capacity, $used + $group->held + $add);
        $targets = array_values(array_unique(array_map(fn (array $move): string => "{$move['from']} → {$move['to']->code}", $moves)));

        $evidence = [
            $this->demandLine($context),
            "Grupo {$group->code}: {$used} de {$capacity} lugares repartidos · su salón más chico es de {$limit} (HC-05)",
        ];

        foreach ($moves as $move) {
            $evidence[] = "{$move['to']->code}: {$move['to']->capacity} lugares · libre {$move['when']}";
        }

        $effect = [
            (count($moves) === count($group->sessions) ? 'Sus sesiones pasan' : 'Pasan '.count($moves).' de sus '.count($group->sessions).' sesiones')
                .' a '.implode(' y ', array_unique(array_map(fn (array $move): string => $move['to']->code, $moves))).'.',
            "La capacidad del grupo {$group->code} sube de {$capacity} a {$newCapacity} y {$context['code']} pasa de {$from} a ".($from + $add).' lugares.',
        ];

        foreach (array_unique(array_filter(array_map(fn (array $move): ?string => $move['to']->loanFrom === null ? null : "{$move['to']->code}|{$move['to']->loanFrom}", $moves))) as $loan) {
            [$code, $owner] = explode('|', $loan, 2);
            $effect[] = "Pide el préstamo de {$code} a {$owner} (HC-10).";
        }

        $effect[] = $this->touches([$context['code']], $group->teacherName, 'que conserva su horario en otro salón');
        $this->take($group, $context['career'], $add, $newCapacity);

        return new ReanalysisSuggestion(
            key: "change_room:{$group->id}:{$context['career']}",
            kind: ReanalysisSuggestionKind::ChangeRoom,
            title: "Cambiar el salón del grupo {$group->code}: ".implode(' y ', $targets),
            evidence: $evidence,
            effect: $effect,
            groupId: $group->id,
            careerId: $context['career'],
            seats: $add,
            maxSeats: $room,
            moves: $moves,
            raisesCapacity: $newCapacity > $capacity,
            expected: $this->expectedOf([$group->id]),
        );
    }

    /**
     * El salón de cada sesión para llegar a `$target` lugares: el suyo si alcanza; si no, el primero libre que alcanza
     * (en el orden de la consulta: sin préstamo y más chico primero) o, si ninguno, el más grande libre.
     *
     * @return array<string, AdvisorRoom> por id de la sesión
     */
    private function pickRooms(AdvisorGroup $group, int $target): array
    {
        $chosen = [];

        foreach ($group->sessions as $session) {
            $free = array_values(array_filter($session->largerRooms, fn (AdvisorRoom $room): bool => ! $this->isTaken($room, $session)));
            $fits = array_values(array_filter($free, fn (AdvisorRoom $room): bool => $room->capacity >= $target));
            $largest = array_reduce($free, fn (?AdvisorRoom $carry, AdvisorRoom $room): AdvisorRoom => $carry === null || $room->capacity > $carry->capacity ? $room : $carry);

            $chosen[$session->id] = match (true) {
                $session->room->capacity >= $target => $session->room,
                $fits !== [] => $fits[0],
                $largest !== null => $largest,
                default => $session->room,
            };
        }

        return $chosen;
    }

    private function isTaken(AdvisorRoom $room, AdvisorSession $session): bool
    {
        return array_any($this->takenRooms, fn (array $taken): bool => $taken['room'] === $room->id && $taken['session']->overlaps($session));
    }

    /**
     * Cerrar el primer grupo (sin docente, luego el de menos lugares, luego por clave) que tiene lugares de una carrera
     * sobrante y que, reubicando lo que hace falta, no deja a ninguna carrera faltante. Nunca uno que amplía o cambia de
     * salón otra sugerencia (`$touched`). Lo que queda de cada carrera se cuenta con los lugares de ahora (`$seats`), no
     * con los que otras sugerencias todavía no aplican; lo libre de los que reciben, después de ellas (lo más chico de los
     * dos), así cerrar sirve se apliquen o no.
     *
     * @param  list<AdvisorGroup>  $groups
     * @param  list<string|null>  $touched
     * @param  list<string>  $surplus
     * @param  array<string, int>  $seats
     * @param  array<string, int>  $demand
     * @param  array<string, string>  $careerCodes
     */
    private function close(array $groups, array $touched, array $surplus, array $seats, array $demand, array $careerCodes, DemandSettings $settings): ?ReanalysisSuggestion
    {
        $candidates = array_values(array_filter($groups, fn (AdvisorGroup $group): bool => ! in_array($group->id, $touched, true)
            && array_intersect(array_keys($group->quotas), $surplus) !== []));
        usort($candidates, fn (AdvisorGroup $a, AdvisorGroup $b): int => [$a->teacherName !== null, $a->used(), $a->code]
            <=> [$b->teacherName !== null, $b->used(), $b->code]);

        foreach ($candidates as $group) {
            $relocations = $this->relocate($group, $groups, $seats, $demand, $careerCodes, $settings);

            if ($relocations !== null) {
                return $this->closeSuggestion($group, $relocations, $surplus, $seats, $demand, $careerCodes);
            }
        }

        return null;
    }

    /**
     * Lo que pasa a los demás grupos si se cierra `$group`, o null si así alguna carrera quedaría faltante (o sin todos
     * sus lugares, si no se clasifica).
     *
     * @param  list<AdvisorGroup>  $groups
     * @param  array<string, int>  $seats
     * @param  array<string, int>  $demand
     * @param  array<string, string>  $careerCodes
     * @return list<array{groupId: string, groupCode: string, careerId: string, careerCode: string, seats: int}>|null
     */
    private function relocate(AdvisorGroup $closing, array $groups, array $seats, array $demand, array $careerCodes, DemandSettings $settings): ?array
    {
        $free = [];

        foreach ($groups as $group) {
            $free[$group->id] = max(0, $this->capacity[$group->id] - array_sum($this->quotas[$group->id]) - $group->held);
        }

        $relocations = [];

        foreach ($this->quotas[$closing->id] as $careerId => $quota) {
            $remaining = $seats[$careerId] - $quota;
            $need = isset($demand[$careerId]) ? min($quota, max(0, $demand[$careerId] - $remaining)) : $quota;
            $moved = 0;

            $receivers = array_values(array_filter($groups, fn (AdvisorGroup $group): bool => $group->id !== $closing->id && isset($this->quotas[$group->id][$careerId])));
            usort($receivers, fn (AdvisorGroup $a, AdvisorGroup $b): int => [$free[$b->id], $a->code] <=> [$free[$a->id], $b->code]);

            foreach ($receivers as $receiver) {
                $put = min($need - $moved, $free[$receiver->id]);

                if ($put > 0) {
                    $relocations[] = ['groupId' => $receiver->id, 'groupCode' => $receiver->code, 'careerId' => $careerId, 'careerCode' => $careerCodes[$careerId] ?? '—', 'seats' => $put];
                    $free[$receiver->id] -= $put;
                    $moved += $put;
                }
            }

            if ($moved < $need && (! isset($demand[$careerId]) || $settings->balanceOf($demand[$careerId], $remaining + $moved) === DemandBalance::Shortfall)) {
                return null;
            }
        }

        return $relocations;
    }

    /**
     * @param  list<array{groupId: string, groupCode: string, careerId: string, careerCode: string, seats: int}>  $relocations
     * @param  list<string>  $surplus
     * @param  array<string, int>  $seats
     * @param  array<string, int>  $demand
     * @param  array<string, string>  $careerCodes
     */
    private function closeSuggestion(AdvisorGroup $group, array $relocations, array $surplus, array $seats, array $demand, array $careerCodes): ReanalysisSuggestion
    {
        $evidence = [];

        foreach (array_intersect(array_keys($this->quotas[$group->id]), $surplus) as $careerId) {
            $evidence[] = "{$careerCodes[$careerId]}: {$demand[$careerId]} esperados · {$seats[$careerId]} lugares · sobran ".($seats[$careerId] - $demand[$careerId]);
        }

        $quotas = $this->quotas[$group->id];
        $evidence[] = "Grupo {$group->code}: ".array_sum($quotas).' lugares ('.implode(', ', array_map(fn (string $careerId, int $quota): string => ($careerCodes[$careerId] ?? '—')." {$quota}", array_keys($quotas), $quotas)).')';

        $byGroup = [];

        foreach ($relocations as $relocation) {
            $byGroup[$relocation['groupCode']][] = "{$relocation['careerCode']} +{$relocation['seats']}";
        }

        $careers = array_map(fn (string $careerId): string => $careerCodes[$careerId] ?? '—', array_keys($quotas));
        $effect = [
            $relocations === []
                ? 'Nadie pierde lugar: los grupos que quedan alcanzan.'
                : 'Sus lugares pasan a: '.implode('; ', array_map(fn (string $code, array $parts): string => "grupo {$code} (".implode(', ', $parts).')', array_keys($byGroup), $byGroup)).'.',
            "El grupo {$group->code} se cancela y pierde su horario (queda en su bitácora).",
            ...($group->held > 0 ? ["Se cancelan sus propuestas de grupo compartido, que apartan {$group->held} lugares."] : []),
            $this->touches($careers, $group->teacherName, null),
        ];

        $hours = rtrim(rtrim(number_format($group->weeklyHours(), 1), '0'), '.');
        $warning = $group->teacherName === null ? null
            : "{$group->teacherName} imparte el grupo {$group->code} ({$hours} h a la semana): al cerrarlo deja de impartirlo y se le avisa al republicar (RA-05).";
        $blocked = $group->teacherName === null ? null
            : "Primero quita a {$group->teacherName} del grupo {$group->code} en Asignación docente; luego lo puedes cerrar.";

        return new ReanalysisSuggestion(
            key: "close_group:{$group->id}",
            kind: ReanalysisSuggestionKind::CloseGroup,
            title: "Cerrar el grupo {$group->code}",
            evidence: $evidence,
            effect: $effect,
            warning: $warning,
            groupId: $group->id,
            relocations: $relocations,
            blockedReason: $blocked,
            expected: $this->expectedOf([$group->id, ...array_column($relocations, 'groupId')]),
        );
    }

    /**
     * Abrir un grupo para una carrera que sigue faltante con todo lo de arriba (tarea 8.3b). `expected` lleva todos los
     * grupos del bloque como se leyeron: quien aplica revisa con los candados puestos que ninguno haya crecido ni haya
     * nacido otro.
     *
     * @param  list<AdvisorGroup>  $groups
     */
    private function open(string $careerId, string $code, int $gap, array $groups, ?NewGroupBase $opening): ReanalysisSuggestion
    {
        $spec = $opening?->specFor($careerId, $code, $gap);
        $evidence = [
            "{$code}: faltan {$gap} lugares aunque se amplíen los grupos que caben",
            $groups === [] ? 'No hay grupos de la materia en el campus.' : 'Ningún grupo crece más: su salón no da para más y no hay uno mayor libre a su hora (HC-05)',
        ];

        if ($spec === null) {
            return new ReanalysisSuggestion(
                key: "open_group:{$careerId}",
                kind: ReanalysisSuggestionKind::OpenGroup,
                title: "Hace falta abrir un grupo para {$code}",
                evidence: $evidence,
                effect: [$opening === null
                    ? 'Ábrelo en Horarios: la materia no tiene campus en esta modalidad.'
                    : "Ábrelo en Horarios: {$code} no tiene un plan vigente con la materia."],
                careerId: $careerId,
                expected: $this->expectedOf(array_map(fn (AdvisorGroup $group): string => $group->id, $groups)),
            );
        }

        return new ReanalysisSuggestion(
            key: "open_group:{$careerId}",
            kind: ReanalysisSuggestionKind::OpenGroup,
            title: "Abrir un grupo de {$spec->subjectCode} para {$code}",
            evidence: $evidence,
            effect: [
                "Grupo nuevo en {$spec->campusCode}, suelto, con la clave que le toque por su hora.",
                "Capacidad {$spec->capacity} · {$spec->seats} lugares para {$code} (semestre {$spec->semester}).",
                'Sin docente: asígnalo en Asignación docente.',
            ],
            careerId: $careerId,
            seats: $spec->seats,
            expected: $this->expectedOf(array_map(fn (AdvisorGroup $group): string => $group->id, $groups)),
            newGroup: $spec,
        );
    }

    /**
     * @param  array{career: string, code: string, expected: int, seats: int, after: bool}  $context
     */
    private function demandLine(array $context): string
    {
        return "{$context['code']}: {$context['expected']} esperados · {$context['seats']} lugares".($context['after'] ? ' con lo de arriba' : '').' · faltan '.($context['expected'] - $context['seats']);
    }

    /**
     * «Toca a ISIC y a Ana Ruiz, que conserva su horario.»
     *
     * @param  list<string>  $careers
     */
    private function touches(array $careers, ?string $teacher, ?string $teacherNote): string
    {
        $careers = implode(', ', $careers);

        return $teacher === null ? "Toca a {$careers}." : "Toca a {$careers} y a {$teacher}".($teacherNote === null ? '' : ", {$teacherNote}").'.';
    }

    /**
     * La capacidad y los cupos de esos grupos como se leyeron (no como los deja otra sugerencia): lo que
     * ApplyReanalysisSuggestion compara con los grupos bloqueados antes de escribir.
     *
     * @param  list<string>  $groupIds
     * @return array<string, array{capacity: int, quotas: array<string, int>}>
     */
    private function expectedOf(array $groupIds): array
    {
        return array_intersect_key($this->original, array_flip($groupIds));
    }

    /**
     * Toma los lugares de una sugerencia, para que la siguiente ya no los cuente.
     */
    private function take(AdvisorGroup $group, string $careerId, int $seats, int $capacity): void
    {
        $this->quotas[$group->id][$careerId] += $seats;
        $this->capacity[$group->id] = $capacity;
    }

    private function smallestRoom(AdvisorGroup $group): ?AdvisorRoom
    {
        $rooms = array_filter(array_map(fn (AdvisorSession $session): ?AdvisorRoom => $session->room, $group->sessions));
        usort($rooms, fn (AdvisorRoom $a, AdvisorRoom $b): int => $a->capacity <=> $b->capacity);

        return $rooms[0] ?? null;
    }

    /**
     * @param  list<AdvisorGroup>  $groups
     * @return array<string, int>
     */
    private function seatsByCareer(array $groups): array
    {
        $seats = [];

        foreach ($groups as $group) {
            foreach ($this->quotas[$group->id] as $careerId => $quota) {
                $seats[$careerId] = ($seats[$careerId] ?? 0) + $quota;
            }
        }

        return $seats;
    }
}
