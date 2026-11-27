<?php

namespace App\Modules\Solver\Engines;

use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RunMetrics;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SessionKind;
use App\Modules\Solver\Contract\SoftScore;
use App\Modules\Solver\Contract\SoftWeights;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Contract\UnscheduledGroup;
use Closure;

/**
 * El motor de prueba (tarea 6.3): sin buscar nada, pone cada grupo movible en sesiones de una hora, una por día en los
 * primeros días del campus (4 h: lunes a jueves; 5 h: lunes a viernes; 6 h: con sábado si la rejilla lo tiene, como el SII;
 * decisión del usuario, 17 de noviembre de 2026), todas a la misma hora. La hora de inicio rota por grupo desde el inicio
 * de la jornada para que no caigan todos a las 07:00. Va en su primer salón candidato o en el primer salón de su campus.
 * Un grupo con más horas que días queda sin programar. No revisa reglas duras, así que nunca dice `feasible`:
 * devuelve `partial` y lo avisa en el log. Sirve para que la corrida, la pantalla y la aceptación (6.8 a 6.10) se
 * construyan y se prueben antes que la heurística, y como motor siempre disponible en las pruebas.
 */
final class FixedEngine implements SolverEngine
{
    public const string KEY = 'fixed';

    public const string VERSION = '1.0';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Motor de prueba';
    }

    public function supportedVersions(): array
    {
        return [SolverInput::VERSION];
    }

    public function checksHardRules(): bool
    {
        return false;
    }

    public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput
    {
        $started = hrtime(true);
        $campuses = [];

        foreach ($input->campuses as $campus) {
            $campuses[$campus->code] = $campus;
        }

        $sessions = [];
        $unscheduled = [];
        $movable = array_values(array_filter($input->groups, fn ($group): bool => $group->movable));

        foreach ($movable as $index => $group) {
            $grid = $campuses[$group->campusCode];
            $dayStart = self::minutes($grid->dayStart);
            $hoursInDay = intdiv(self::minutes($grid->dayEnd) - $dayStart, 60);

            if ($hoursInDay < 1 || $group->weeklyHours > count($grid->days)) {
                $unscheduled[] = new UnscheduledGroup($group->id, [$hoursInDay < 1
                    ? 'La jornada de su campus no tiene horas.'
                    : "El grupo tiene {$group->weeklyHours} horas a la semana y su campus solo tiene ".count($grid->days).' días; el motor de prueba pone una hora por día.']);

                continue;
            }

            $start = $dayStart + ($index % $hoursInDay) * 60;
            $roomId = $grid->usesRooms ? ($group->candidateRoomIds[0] ?? $this->firstRoomOf($input, $group->campusCode)) : null;

            for ($hour = 0; $hour < $group->weeklyHours; $hour++) {
                $sessions[] = new ProposedSession(
                    groupId: $group->id,
                    day: $grid->days[$hour],
                    startsAt: self::clock($start),
                    endsAt: self::clock($start + 60),
                    roomId: $roomId,
                    kind: $grid->usesRooms ? ($hour < $group->weeklyHours - $group->practiceHours ? SessionKind::Theory : SessionKind::Practice) : SessionKind::Virtual,
                    changed: true,
                );
            }

            if ($progress !== null) {
                $progress((int) floor(($index + 1) * 100 / count($movable)), null);
            }
        }

        return new SolverOutput(
            version: SolverInput::VERSION,
            runId: $input->runId,
            status: RunStatus::Partial,
            sessions: $sessions,
            teacherSuggestions: [],
            unscheduled: $unscheduled,
            hardViolations: [],
            softScore: new SoftScore(0, array_fill_keys(SoftWeights::RULES, 0)),
            relaxedRules: [],
            metrics: new RunMetrics(
                elapsedSeconds: round((hrtime(true) - $started) / 1e9, 3),
                engine: self::KEY,
                engineVersion: self::VERSION,
            ),
            log: ['Motor de prueba: no revisa reglas duras ni calcula el puntaje.'],
        );
    }

    private function firstRoomOf(SolverInput $input, string $campusCode): ?string
    {
        foreach ($input->rooms as $room) {
            if ($room->campusCode === $campusCode) {
                return $room->id;
            }
        }

        return null;
    }

    private static function minutes(string $clock): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $clock));

        return $hours * 60 + $minutes;
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
