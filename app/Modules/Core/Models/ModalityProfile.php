<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Enums\StaffingMode;
use App\Modules\Core\Support\WeekTime;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Perfil de flujo de una modalidad (ADR-0025; MS1, supuesto por confirmar), de solo lectura, construido desde
 * `modalities.settings`. Pantallas, validadores y transiciones consultan este perfil, nunca la clave de la modalidad.
 * Es el perfil que antes llevaba cada campus (CampusProfile) sin `heads_edit_rooms`, que es de la validación de salones
 * y no de la modalidad: si llega en `settings`, se ignora como cualquier clave desconocida.
 *
 * - Una clave que falta toma su valor de DEFAULTS, así una clave nueva no obliga a migrar cada modalidad.
 * - Un valor presente pero inválido lanza InvalidArgumentException: una configuración rota falla
 *   en voz alta en lugar de reemplazarse en silencio.
 * - Las claves que el perfil no conoce se ignoran.
 */
final readonly class ModalityProfile
{
    /**
     * Bloques, horario y días: supuesto 4 de PROJECT.md (bloque de 1 h, 7:00 a 21:00, lunes a viernes).
     * Sin visto bueno obligatorio de Subdirección: ADR-0008 (se activa por configuración). Sin
     * docentes externos: los de presencial son del ITCJ.
     *
     * @var array{uses_rooms: bool, uses_time_blocks: bool, staffing_mode: string, block_minutes: int, day_start: string, day_end: string, days: list<int>, allow_external_teachers: bool, requires_subdireccion_approval: bool}
     */
    public const array DEFAULTS = [
        'uses_rooms' => true,
        'uses_time_blocks' => true,
        'staffing_mode' => 'department',
        'block_minutes' => 60,
        'day_start' => '07:00',
        'day_end' => '21:00',
        'days' => [1, 2, 3, 4, 5],
        'allow_external_teachers' => false,
        'requires_subdireccion_approval' => false,
    ];

    /** Días ISO-8601 (1 = lunes) que admite `group_sessions.day`. */
    private const int FIRST_DAY = 1;

    private const int LAST_DAY = 6;

    /**
     * @param  list<int>  $days
     */
    private function __construct(
        public bool $usesRooms,
        public bool $usesTimeBlocks,
        public StaffingMode $staffingMode,
        public int $blockMinutes,
        public string $dayStart,
        public string $dayEnd,
        public array $days,
        public bool $allowExternalTeachers,
        public bool $requiresSubdireccionApproval,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $settings): self
    {
        $settings += self::DEFAULTS;

        $blockMinutes = self::blockMinutes($settings['block_minutes']);
        $dayStart = self::time($settings, 'day_start');
        $dayEnd = self::time($settings, 'day_end');
        $dayMinutes = self::minutesOf($dayEnd) - self::minutesOf($dayStart);

        if ($dayMinutes <= 0) {
            throw self::invalid('day_end', 'debe ser posterior a day_start');
        }

        if ($dayMinutes % $blockMinutes !== 0) {
            throw self::invalid('block_minutes', 'debe dividir exacto la jornada de day_start a day_end');
        }

        return new self(
            usesRooms: self::bool($settings, 'uses_rooms'),
            usesTimeBlocks: self::bool($settings, 'uses_time_blocks'),
            staffingMode: self::staffingMode($settings['staffing_mode']),
            blockMinutes: $blockMinutes,
            dayStart: $dayStart,
            dayEnd: $dayEnd,
            days: self::days($settings['days']),
            allowExternalTeachers: self::bool($settings, 'allow_external_teachers'),
            requiresSubdireccionApproval: self::bool($settings, 'requires_subdireccion_approval'),
        );
    }

    /**
     * La ventana que cubre a varios perfiles, para lo que es del docente y no de una modalidad: su disponibilidad
     * (MS11, supuesto por confirmar). Los días son la unión ordenada; el inicio, el más temprano; el fin, el más tarde;
     * y el bloque, el más corto de los perfiles si divide exacto la ventana. Si no la divide, el máximo común divisor
     * de todos los bloques y la ventana (supuesto por confirmar), que siempre divide la ventana. Lo demás (salones, bloques fijos, asignación, docentes externos y visto bueno)
     * no importa a la disponibilidad y sale de DEFAULTS. Sin perfiles es el presencial por omisión.
     *
     * @param  iterable<ModalityProfile>  $profiles
     */
    public static function covering(iterable $profiles): self
    {
        $days = [];
        $blocks = [];
        $start = null;
        $end = null;

        foreach ($profiles as $profile) {
            $days = [...$days, ...$profile->days];
            $blocks[] = $profile->blockMinutes;
            $start = $start === null || self::minutesOf($profile->dayStart) < self::minutesOf($start) ? $profile->dayStart : $start;
            $end = $end === null || self::minutesOf($profile->dayEnd) > self::minutesOf($end) ? $profile->dayEnd : $end;
        }

        if ($blocks === []) {
            return self::fromArray([]);
        }

        $window = self::minutesOf($end) - self::minutesOf($start);
        $block = min($blocks);

        if ($window % $block !== 0) {
            $block = array_reduce($blocks, fn (int $carry, int $minutes): int => self::gcd($carry, $minutes), $window);
        }

        return self::fromArray([
            'days' => $days,
            'day_start' => $start,
            'day_end' => $end,
            'block_minutes' => $block,
        ]);
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : self::gcd($b, $a % $b);
    }

    /**
     * El arreglo que se guarda en `modalities.settings`.
     *
     * @return array{uses_rooms: bool, uses_time_blocks: bool, staffing_mode: string, block_minutes: int, day_start: string, day_end: string, days: list<int>, allow_external_teachers: bool, requires_subdireccion_approval: bool}
     */
    public function toArray(): array
    {
        return [
            'uses_rooms' => $this->usesRooms,
            'uses_time_blocks' => $this->usesTimeBlocks,
            'staffing_mode' => $this->staffingMode->value,
            'block_minutes' => $this->blockMinutes,
            'day_start' => $this->dayStart,
            'day_end' => $this->dayEnd,
            'days' => $this->days,
            'allow_external_teachers' => $this->allowExternalTeachers,
            'requires_subdireccion_approval' => $this->requiresSubdireccionApproval,
        ];
    }

    /**
     * El perfil en una línea: salones, bloques, días y jornada, y lo que se aparta del flujo presencial
     * (asignación central, docentes externos y visto bueno de Subdirección).
     */
    public function summary(): string
    {
        return implode(' · ', array_filter([
            $this->usesRooms ? 'Salones' : 'Sin salones',
            $this->usesTimeBlocks ? "bloques de {$this->blockMinutes} min" : 'sin bloques fijos',
            $this->schedule(),
            $this->staffingMode === StaffingMode::Central ? 'asignación central' : null,
            $this->allowExternalTeachers ? 'docentes externos' : null,
            $this->requiresSubdireccionApproval ? 'visto bueno de Subdirección' : null,
        ]));
    }

    /**
     * Sus días y su jornada, como en summary(): «lunes a viernes, 7:00 a 21:00».
     */
    public function schedule(): string
    {
        return self::dayNames($this->days).', '.self::clock($this->dayStart).' a '.self::clock($this->dayEnd);
    }

    /**
     * «lunes a viernes» si los días van seguidos (tres o más); si no, «viernes y sábado» o
     * «lunes, miércoles y viernes».
     *
     * @param  list<int>  $days
     */
    private static function dayNames(array $days): string
    {
        $names = array_map(fn (int $day): string => WeekTime::day($day), $days);
        $count = count($days);

        if ($count >= 3 && $count - 1 === $days[$count - 1] - $days[0]) {
            return $names[0].' a '.$names[$count - 1];
        }

        return Arr::join($names, ', ', ' y ');
    }

    /**
     * «07:00» como «7:00».
     */
    private static function clock(string $time): string
    {
        return WeekTime::clock($time);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function bool(array $settings, string $key): bool
    {
        if (! is_bool($settings[$key])) {
            throw self::invalid($key, 'debe ser booleano');
        }

        return $settings[$key];
    }

    private static function staffingMode(mixed $value): StaffingMode
    {
        if ($value instanceof StaffingMode) {
            return $value;
        }

        return (is_string($value) ? StaffingMode::tryFrom($value) : null)
            ?? throw self::invalid('staffing_mode', 'debe ser '.implode(' o ', array_column(StaffingMode::cases(), 'value')));
    }

    private static function blockMinutes(mixed $value): int
    {
        if (! is_int($value) || $value <= 0) {
            throw self::invalid('block_minutes', 'debe ser un entero positivo');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function time(array $settings, string $key): string
    {
        $value = $settings[$key];

        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) !== 1) {
            throw self::invalid($key, 'debe tener el formato HH:MM de 00:00 a 23:59');
        }

        return $value;
    }

    private static function minutesOf(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /**
     * @return list<int>
     */
    private static function days(mixed $value): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw self::invalid('days', 'debe ser una lista no vacía');
        }

        foreach ($value as $day) {
            if (! is_int($day) || $day < self::FIRST_DAY || $day > self::LAST_DAY) {
                throw self::invalid('days', 'solo admite enteros de '.self::FIRST_DAY.' (lunes) a '.self::LAST_DAY.' (sábado)');
            }
        }

        $days = array_values(array_unique($value));
        sort($days);

        return $days;
    }

    private static function invalid(string $key, string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException("Perfil de modalidad inválido: {$key} {$reason}.");
    }
}
