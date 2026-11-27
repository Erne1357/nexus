<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

use App\Modules\Scheduling\Validation\Minutes;

/**
 * Dónde cabe el grupo nuevo de una sugerencia del re-análisis (tarea 8.3b), como lo contesta quien atiende
 * NewGroupPlacementRequested (Solver, con la heurística y todo lo demás fijo): sus sesiones con su salón, el puntaje blando
 * que agrega y por regla (solo cuenta lo que toca al grupo nuevo), las líneas de evidencia del motor y cuánto tardó. Sin
 * sesiones no cupo: `reasons` dice por qué, con la regla al frente. `rooms` trae la clave y la capacidad de cada salón,
 * que Scheduling pone al recibirla (NewGroupPlacementQuery).
 *
 * Viaja en el estado del drawer (toArray/fromArray), así que se calcula una vez al abrir la tarjeta y nunca en cada
 * render; al aplicar, OpenReanalysisGroup revisa con los candados puestos que siga libre.
 */
final readonly class NewGroupPlacement
{
    /**
     * @param  list<array{day: int, startsAt: string, endsAt: string, kind: string, roomId: ?string}>  $sessions
     * @param  array<string, int>  $scoreByRule
     * @param  list<string>  $reasons
     * @param  list<string>  $evidence
     * @param  array<string, array{code: string, capacity: int}>  $rooms
     */
    public function __construct(
        public array $sessions,
        public int $score = 0,
        public array $scoreByRule = [],
        public array $reasons = [],
        public array $evidence = [],
        public float $seconds = 0.0,
        public array $rooms = [],
    ) {}

    public function isPlaced(): bool
    {
        return $this->sessions !== [];
    }

    /**
     * Con la clave y la capacidad de sus salones.
     *
     * @param  array<string, array{code: string, capacity: int}>  $rooms
     */
    public function withRooms(array $rooms): self
    {
        return new self($this->sessions, $this->score, $this->scoreByRule, $this->reasons, $this->evidence, $this->seconds, $rooms);
    }

    /**
     * Todas sus sesiones en otro salón (la edición de «Abrir un grupo», solo con un salón en todas).
     *
     * @param  array{code: string, capacity: int}  $label
     */
    public function withRoom(string $roomId, array $label): self
    {
        $sessions = array_map(fn (array $session): array => [...$session, 'roomId' => $roomId], $this->sessions);

        return new self($sessions, $this->score, $this->scoreByRule, $this->reasons, $this->evidence, $this->seconds, [$roomId => $label]);
    }

    /**
     * La clave del salón de una sesión, o null sin salón.
     */
    public function roomCode(?string $roomId): ?string
    {
        return $roomId === null ? null : ($this->rooms[$roomId]['code'] ?? null);
    }

    /**
     * Si se le puede cambiar el salón al editarlo: todas sus sesiones van en un mismo salón (lo simple; con varios, se deja
     * como lo propuso el motor).
     */
    public function isEditable(): bool
    {
        return $this->isPlaced() && count($this->roomIds()) === 1 && ! in_array(null, array_column($this->sessions, 'roomId'), true);
    }

    /**
     * Los salones que usa, sin repetir, en el orden de sus sesiones.
     *
     * @return list<string>
     */
    public function roomIds(): array
    {
        return array_values(array_unique(array_filter(array_column($this->sessions, 'roomId'))));
    }

    /**
     * «Lun, Mar, Mié y Jue 7:00–8:00», o una por día si las horas cambian («Lun 7:00–8:00 · Mar 9:00–11:00»).
     */
    public function scheduleLabel(): string
    {
        $hours = fn (array $session): string => Minutes::labelOf($session['startsAt']).'–'.Minutes::labelOf($session['endsAt']);
        $times = array_values(array_unique(array_map($hours, $this->sessions)));
        $days = array_map(fn (array $session): string => Minutes::dayLabel($session['day'], short: true), $this->sessions);

        if (count($times) === 1) {
            $last = array_pop($days);

            return ($days === [] ? $last : implode(', ', $days).' y '.$last).' '.$times[0];
        }

        return implode(' · ', array_map(fn (array $session): string => Minutes::dayLabel($session['day'], short: true).' '.$hours($session), $this->sessions));
    }

    /**
     * @return array{sessions: list<array{day: int, startsAt: string, endsAt: string, kind: string, roomId: ?string}>, score: int, scoreByRule: array<string, int>, reasons: list<string>, evidence: list<string>, seconds: float, rooms: array<string, array{code: string, capacity: int}>}
     */
    public function toArray(): array
    {
        return [
            'sessions' => $this->sessions,
            'score' => $this->score,
            'scoreByRule' => $this->scoreByRule,
            'reasons' => $this->reasons,
            'evidence' => $this->evidence,
            'seconds' => $this->seconds,
            'rooms' => $this->rooms,
        ];
    }

    /**
     * @param  array{sessions?: list<array{day: int, startsAt: string, endsAt: string, kind: string, roomId: ?string}>, score?: int, scoreByRule?: array<string, int>, reasons?: list<string>, evidence?: list<string>, seconds?: float, rooms?: array<string, array{code: string, capacity: int}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_values(array_map(fn (array $session): array => [
                'day' => (int) $session['day'],
                'startsAt' => (string) $session['startsAt'],
                'endsAt' => (string) $session['endsAt'],
                'kind' => (string) $session['kind'],
                'roomId' => isset($session['roomId']) ? (string) $session['roomId'] : null,
            ], $data['sessions'] ?? [])),
            (int) ($data['score'] ?? 0),
            array_map(intval(...), $data['scoreByRule'] ?? []),
            array_values(array_map(strval(...), $data['reasons'] ?? [])),
            array_values(array_map(strval(...), $data['evidence'] ?? [])),
            (float) ($data['seconds'] ?? 0.0),
            array_map(fn (array $room): array => ['code' => (string) $room['code'], 'capacity' => (int) $room['capacity']], $data['rooms'] ?? []),
        );
    }
}
