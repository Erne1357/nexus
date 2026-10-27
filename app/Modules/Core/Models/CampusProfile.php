<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Enums\StaffingMode;
use InvalidArgumentException;

/**
 * Perfil de flujo de un campus (ADR-0009), de solo lectura, construido desde `campuses.settings`.
 * Pantallas, validadores y transiciones consultan este perfil, nunca el código del campus.
 *
 * - Una clave que falta toma su valor de DEFAULTS, así una clave nueva no obliga a migrar cada campus.
 * - Un valor presente pero inválido lanza InvalidArgumentException: una configuración rota falla
 *   en voz alta en lugar de reemplazarse en silencio.
 * - Las claves que el perfil no conoce se ignoran.
 */
final readonly class CampusProfile
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
     * El arreglo que se guarda en `campuses.settings`.
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
        return new InvalidArgumentException("Perfil de campus inválido: {$key} {$reason}.");
    }
}
