<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Models\ModalityProfile;

/**
 * Lo que le falta a la carga de un departamento para cerrarse o publicarse (HC-15), contado por grupo activo con la
 * regla de GroupClosingFlags (tally()). `justified` no bloquea: son los grupos sin docente o sin salón que pasan con
 * justificación. `groups` son los grupos distintos que bloquean: uno sin docente ni salón cuenta en dos cifras y ahí
 * una vez.
 */
final readonly class ClosingBlockers
{
    public function __construct(
        public int $conflicted = 0,
        public int $unscheduled = 0,
        public int $withoutTeacher = 0,
        public int $withoutRoom = 0,
        public int $inactiveTeacher = 0,
        public int $justified = 0,
        public int $groups = 0,
    ) {}

    /**
     * Las cifras de unos grupos con la regla por grupo de GroupClosingFlags. `$conflicted` son los ids con choque duro
     * según el validador (el veto y la pantalla); sin ellos, cuenta los vigentes de `schedule_conflicts` (el tablero).
     *
     * @param  iterable<GroupClosingFlags>  $groups
     * @param  list<string>|null  $conflicted
     */
    public static function tally(iterable $groups, ModalityProfile $profile, ?array $conflicted = null): self
    {
        $hard = $conflicted === null ? null : array_flip($conflicted);
        $counts = ['conflicted' => 0, 'unscheduled' => 0, 'withoutTeacher' => 0, 'withoutRoom' => 0, 'inactiveTeacher' => 0, 'justified' => 0, 'groups' => 0];

        foreach ($groups as $group) {
            $isConflicted = $hard === null ? $group->storedHardConflict : isset($hard[$group->id]);

            $counts['conflicted'] += (int) $isConflicted;
            $counts['unscheduled'] += (int) $group->unscheduled($profile);
            $counts['withoutTeacher'] += (int) $group->withoutTeacher();
            $counts['withoutRoom'] += (int) $group->withoutRoom($profile);
            $counts['inactiveTeacher'] += (int) $group->inactiveTeacher;
            $counts['justified'] += (int) $group->passesJustified($profile);
            $counts['groups'] += (int) $group->blocks($profile, $isConflicted);
        }

        return new self(...$counts);
    }

    public function isClear(): bool
    {
        return $this->reasons() === [];
    }

    /**
     * Un motivo por cada cosa que falta, en el orden en que se corrige.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_filter([
            self::reason($this->conflicted, 'grupo tiene choques duros: corrígelo en el editor de horarios.', 'grupos tienen choques duros: corrígelos en el editor de horarios.'),
            self::reason($this->unscheduled, 'grupo no tiene horario.', 'grupos no tienen horario.'),
            self::reason($this->withoutTeacher, 'grupo no tiene docente ni justificación.', 'grupos no tienen docente ni justificación.'),
            self::reason($this->withoutRoom, 'grupo tiene sesiones presenciales sin salón ni justificación.', 'grupos tienen sesiones presenciales sin salón ni justificación.'),
            self::reason($this->inactiveTeacher, 'grupo tiene un docente dado de baja: asígnale otro.', 'grupos tienen un docente dado de baja: asígnales otro.'),
        ]));
    }

    private static function reason(int $count, string $one, string $many): ?string
    {
        return match (true) {
            $count === 0 => null,
            $count === 1 => "HC-15: 1 {$one}",
            default => "HC-15: {$count} {$many}",
        };
    }
}
