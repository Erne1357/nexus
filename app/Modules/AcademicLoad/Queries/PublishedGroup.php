<?php

namespace App\Modules\AcademicLoad\Queries;

use Carbon\CarbonImmutable;

/**
 * Un grupo de una carga publicada, tal como lo congeló su snapshot (payload v1, `groups[]`), con la versión y la fecha
 * de la publicación de su carga. El docente va sin RFC: el RFC es para el exporte al SII y la consulta pública no lo
 * muestra. La justificación de HC-15 tampoco viaja: el público ve «Por asignar» sin el motivo (decisión del usuario,
 * 10 de noviembre de 2026).
 */
final readonly class PublishedGroup
{
    /**
     * @param  array{id: string, code: string, name: string, credits: int, theory_hours: int, practice_hours: int, department_id: string}  $subject
     * @param  array{id: string, name: string}|null  $teacher
     * @param  list<array{career: array{id: string, code: string, name: string}, study_plan: array{id: string, code: string}|null, semester: int|null, seats: int, is_owner: bool}>  $placements
     * @param  list<PublishedSession>  $sessions
     */
    public function __construct(
        public string $id,
        public string $code,
        public int $capacity,
        public array $subject,
        public ?array $teacher,
        public array $placements,
        public array $sessions,
        public string $departmentId,
        public string $loadId,
        public int $version,
        public CarbonImmutable $publishedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $payload  el snapshot completo, para la carga, la versión y la fecha
     */
    public static function fromPayload(array $group, array $payload): self
    {
        return new self(
            id: $group['id'],
            code: $group['code'],
            capacity: $group['capacity'],
            subject: $group['subject'],
            teacher: $group['teacher'] === null ? null : ['id' => $group['teacher']['id'], 'name' => $group['teacher']['name']],
            placements: $group['placements'],
            sessions: array_map(PublishedSession::fromPayload(...), $group['sessions']),
            departmentId: $payload['department']['id'],
            loadId: $payload['load_id'],
            version: $payload['version'],
            publishedAt: CarbonImmutable::parse($payload['published_at']),
        );
    }

    /**
     * El orden de los grupos en toda vista de lo publicado: por clave de materia y luego por clave de grupo, con los
     * números en su orden natural (D2 antes que D10).
     */
    public static function compare(self $a, self $b): int
    {
        return strcmp($a->subject['code'], $b->subject['code']) ?: strnatcmp($a->code, $b->code);
    }

    /**
     * «ACF-0904 · B»: la clave de la materia y la del grupo, como en toda la aplicación.
     */
    public function label(): string
    {
        return $this->subject['code'].' · '.$this->code;
    }

    /**
     * El mismo grupo solo con estas sesiones (la vista por salón pinta solo las de ese salón).
     *
     * @param  list<PublishedSession>  $sessions
     */
    public function withSessions(array $sessions): self
    {
        return new self($this->id, $this->code, $this->capacity, $this->subject, $this->teacher, $this->placements, $sessions, $this->departmentId, $this->loadId, $this->version, $this->publishedAt);
    }

    /**
     * Si el grupo cuenta en esa carrera, retícula y semestre (la dueña o una con cupo).
     */
    public function isPlacedIn(string $careerId, string $planId, int $semester): bool
    {
        foreach ($this->placements as $placement) {
            if ($placement['career']['id'] === $careerId && ($placement['study_plan']['id'] ?? null) === $planId && $placement['semester'] === $semester) {
                return true;
            }
        }

        return false;
    }
}
