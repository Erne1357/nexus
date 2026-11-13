<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Core\Models\ModalityProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lo publicado de una modalidad en un periodo (tarea 4.4; M1): la última versión de cada carga que tiene snapshot, junta y
 * ordenada para la consulta pública y los exportes, con el campus de cada grupo; onlyCampus() deja solo los de un campus
 * (el filtro de la consulta). Lee solo el payload (AcademicLoad no ve las tablas vivas de
 * Scheduling) y no dice qué falta por publicar: el público ve lo que cada departamento ya publicó, sin aviso de
 * semestre incompleto, y la fecha de actualización es la de la publicación más reciente de lo que se ve (decisiones del
 * usuario del 10 de noviembre de 2026). Es puro: lo arma PublishedScheduleQuery con los payloads ya normalizados
 * (SnapshotPayload::normalize, MS13) y se guarda en caché tal cual.
 */
final readonly class PublishedSchedule
{
    /** @var list<int> las formas que lee: la actual; las anteriores llegan normalizadas */
    public const array SCHEMA_VERSIONS = [2];

    /**
     * @param  array<string, array{load_id: string, department: array{id: string, code: string, name: string}, version: int, published_at: CarbonImmutable}>  $loads  por departamento
     * @param  list<PublishedGroup>  $groups  por clave de materia y de grupo
     * @param  array<string, mixed>|null  $profile  el perfil de la modalidad de la publicación más reciente
     */
    private function __construct(
        public array $loads,
        private array $groups,
        private ?array $profile,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $payloads  la última versión de cada carga, de la misma modalidad y periodo, normalizada
     *
     * @throws InvalidArgumentException si mezclan modalidades o periodos, o traen una versión de esquema que no se conoce
     */
    public static function fromPayloads(array $payloads): self
    {
        $loads = [];
        $groups = [];
        $latest = null;

        foreach ($payloads as $payload) {
            if (! in_array($payload['schema_version'] ?? null, self::SCHEMA_VERSIONS, true)) {
                throw new InvalidArgumentException('Versión de esquema del snapshot no soportada: '.json_encode($payload['schema_version'] ?? null));
            }

            if ($latest !== null && ($payload['term']['id'] !== $latest['term']['id'] || $payload['modality']['id'] !== $latest['modality']['id'])) {
                throw new InvalidArgumentException('Los snapshots deben ser de la misma modalidad y periodo.');
            }

            $publishedAt = CarbonImmutable::parse($payload['published_at']);
            $loads[$payload['department']['id']] = [
                'load_id' => $payload['load_id'],
                'department' => $payload['department'],
                'version' => $payload['version'],
                'published_at' => $publishedAt,
            ];

            foreach ($payload['groups'] as $group) {
                $groups[] = PublishedGroup::fromPayload($group, $payload);
            }

            if ($latest === null || $publishedAt->greaterThan(CarbonImmutable::parse($latest['published_at']))) {
                $latest = $payload;
            }
        }

        usort($groups, PublishedGroup::compare(...));

        return new self($loads, $groups, $latest['profile'] ?? null);
    }

    /**
     * El payload sin lo que no se muestra fuera del personal (el RFC del docente, la justificación de HC-15 y quién
     * publicó), para guardarlo en caché: lo demás de la consulta no lo necesita.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withoutPrivate(array $payload): array
    {
        unset($payload['published_by']);

        foreach ($payload['groups'] as $index => $group) {
            unset($payload['groups'][$index]['justification']);

            if ($group['teacher'] !== null) {
                unset($payload['groups'][$index]['teacher']['rfc']);
            }
        }

        return $payload;
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /**
     * Lo mismo con solo los grupos que se dan en ese campus (por clave); null deja todos. Las cargas no cambian: un
     * departamento que publicó sigue publicado aunque no tenga grupos en ese campus.
     */
    public function onlyCampus(?string $campusCode): self
    {
        if ($campusCode === null) {
            return $this;
        }

        return new self($this->loads, array_values(array_filter($this->groups, fn (PublishedGroup $group): bool => ($group->campus['code'] ?? null) === $campusCode)), $this->profile);
    }

    /**
     * Los campus donde se dan los grupos publicados, por clave.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    public function campuses(): array
    {
        $campuses = [];

        foreach ($this->groups as $group) {
            if ($group->campus !== null) {
                $campuses[$group->campus['code']] = $group->campus;
            }
        }

        ksort($campuses, SORT_NATURAL);

        return array_values($campuses);
    }

    /**
     * @return list<PublishedGroup>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * El perfil de la modalidad con el que se publicó lo más reciente (días, horario y bloque de la semana; si usa salones
     * y bloques); sin publicaciones, el perfil por omisión.
     */
    public function profile(): ModalityProfile
    {
        return ModalityProfile::fromArray($this->profile ?? []);
    }

    /**
     * Si el departamento ya tiene una versión publicada en la modalidad y periodo.
     */
    public function isPublished(string $departmentId): bool
    {
        return isset($this->loads[$departmentId]);
    }

    /**
     * Las carreras con grupos publicados, cada una con sus retículas y los semestres donde tiene grupos, desde la
     * colocación congelada (dueña o con cupo). Una carrera con cupo que no tiene la materia en ninguna retícula no
     * aporta semestre.
     *
     * @return list<array{career: array{id: string, code: string, name: string}, plans: list<array{plan: array{id: string, code: string}, semesters: list<int>}>}>
     */
    public function careers(): array
    {
        $careers = [];

        foreach ($this->groups as $group) {
            foreach ($group->placements as $placement) {
                if ($placement['study_plan'] === null || $placement['semester'] === null) {
                    continue;
                }

                $career = $placement['career'];
                $plan = $placement['study_plan'];
                $careers[$career['id']]['career'] = $career;
                $careers[$career['id']]['plans'][$plan['id']]['plan'] = $plan;
                $careers[$career['id']]['plans'][$plan['id']]['semesters'][$placement['semester']] = $placement['semester'];
            }
        }

        $careers = array_map(function (array $entry): array {
            $plans = array_map(function (array $plan): array {
                $semesters = array_values($plan['semesters']);
                sort($semesters);

                return ['plan' => $plan['plan'], 'semesters' => $semesters];
            }, $entry['plans']);
            usort($plans, fn (array $a, array $b): int => strnatcmp($a['plan']['code'], $b['plan']['code']));

            return ['career' => $entry['career'], 'plans' => $plans];
        }, $careers);
        usort($careers, fn (array $a, array $b): int => strnatcmp($a['career']['code'], $b['career']['code']));

        return $careers;
    }

    /**
     * Los grupos que cuentan en una carrera, retícula y semestre: de cualquier departamento, propios o con cupo.
     *
     * @return list<PublishedGroup>
     */
    public function semester(string $careerId, string $planId, int $semester): array
    {
        return array_values(array_filter($this->groups, fn (PublishedGroup $group): bool => $group->isPlacedIn($careerId, $planId, $semester)));
    }

    /**
     * Los docentes con grupos publicados, por nombre.
     *
     * @return list<array{id: string, name: string}>
     */
    public function teachers(): array
    {
        $teachers = [];

        foreach ($this->groups as $group) {
            if ($group->teacher !== null) {
                $teachers[$group->teacher['id']] = $group->teacher;
            }
        }

        $teachers = array_values($teachers);
        usort($teachers, fn (array $a, array $b): int => strcmp(self::sortKey($a['name']), self::sortKey($b['name'])));

        return $teachers;
    }

    /**
     * Los grupos de un docente en la modalidad, de todos los departamentos.
     *
     * @return list<PublishedGroup>
     */
    public function teacher(string $teacherId): array
    {
        return array_values(array_filter($this->groups, fn (PublishedGroup $group): bool => ($group->teacher['id'] ?? null) === $teacherId));
    }

    /**
     * Los salones con sesiones publicadas, por edificio y clave (las sesiones virtuales no tienen salón).
     *
     * @return list<array{id: string, code: string, name: string, building: string}>
     */
    public function rooms(): array
    {
        $rooms = [];

        foreach ($this->groups as $group) {
            foreach ($group->sessions as $session) {
                if ($session->room !== null) {
                    $rooms[$session->room['id']] = [
                        'id' => $session->room['id'],
                        'code' => $session->room['code'],
                        'name' => $session->room['name'] ?? $session->room['code'],
                        'building' => $session->room['building']['name'],
                    ];
                }
            }
        }

        $rooms = array_values($rooms);
        usort($rooms, fn (array $a, array $b): int => strcmp(self::sortKey($a['building']), self::sortKey($b['building'])) ?: strnatcmp($a['code'], $b['code']));

        return $rooms;
    }

    /**
     * Los grupos que usan un salón, cada uno solo con sus sesiones en ese salón.
     *
     * @return list<PublishedGroup>
     */
    public function room(string $roomId): array
    {
        $groups = [];

        foreach ($this->groups as $group) {
            $sessions = array_values(array_filter($group->sessions, fn (PublishedSession $session): bool => ($session->room['id'] ?? null) === $roomId));

            if ($sessions !== []) {
                $groups[] = $group->withSessions($sessions);
            }
        }

        return $groups;
    }

    /**
     * Los grupos de la carga de un departamento.
     *
     * @return list<PublishedGroup>
     */
    public function department(string $departmentId): array
    {
        return array_values(array_filter($this->groups, fn (PublishedGroup $group): bool => $group->departmentId === $departmentId));
    }

    /**
     * La publicación más reciente de las cargas de estos grupos: el «Actualizado el» de una vista.
     *
     * @param  list<PublishedGroup>  $groups
     */
    public function updatedAt(array $groups): ?CarbonImmutable
    {
        $latest = null;

        foreach ($groups as $group) {
            if ($latest === null || $group->publishedAt->greaterThan($latest)) {
                $latest = $group->publishedAt;
            }
        }

        return $latest;
    }

    private static function sortKey(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
