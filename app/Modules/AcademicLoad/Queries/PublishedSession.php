<?php

namespace App\Modules\AcademicLoad\Queries;

/**
 * Una sesión de un grupo publicado, tal como la congeló el snapshot (payload v1, `groups[].sessions[]`): día, horas en
 * `HH:MM`, tipo con su etiqueta y salón (o null si es virtual o la modalidad no usa salones).
 */
final readonly class PublishedSession
{
    /**
     * @param  array{id: string, code: string, name: ?string, building: array{id: string, code: string, name: string}, owner_department_id: ?string}|null  $room
     */
    public function __construct(
        public int $day,
        public string $startsAt,
        public string $endsAt,
        public string $kind,
        public string $kindLabel,
        public ?array $room,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public static function fromPayload(array $session): self
    {
        return new self($session['day'], $session['starts_at'], $session['ends_at'], $session['kind'], $session['kind_label'], $session['room']);
    }

    /**
     * Si la sesión usa un salón de otro departamento que el del grupo: un préstamo (los exportes del personal lo marcan).
     */
    public function isLoanedTo(string $departmentId): bool
    {
        $owner = $this->room['owner_department_id'] ?? null;

        return $owner !== null && $owner !== $departmentId;
    }
}
