<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * El contrato del snapshot de una carga publicada (tarea 4.3): la forma exacta del `payload` de `load_snapshots`, que
 * arma la publicación y leen la consulta publicada (4.4), los exportes (4.5) y el exporte al SII (4.6). Documentado
 * en docs/dominio/modelo-de-datos.md, «Contenido del snapshot».
 *
 * AcademicLoad arma el encabezado (`header`), porque la carga, el periodo, el campus, su perfil y el departamento son
 * suyos o de su izquierda; los grupos los da Scheduling al contestar `LoadSnapshotRequested`. Antes de guardar, la
 * publicación revisa el payload completo con `errors`: cada llave presente, ninguna de más, cada valor de su tipo.
 * No usa el validador de Laravel porque la foto de un departamento lleva cientos de grupos y miles de sesiones.
 *
 * Una forma nueva sube `SCHEMA_VERSION`; las fotos guardadas no se reescriben (HC-18), así que quien lee atiende
 * todas las versiones que existan.
 */
final class SnapshotPayload
{
    public const int SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const array GROUP_STATUSES = ['planned', 'confirmed'];

    /** @var list<string> */
    public const array SESSION_KINDS = ['teoria', 'practica', 'virtual'];

    /**
     * El encabezado de la foto: todo menos `groups`.
     *
     * @return array<string, mixed>
     */
    public static function header(AcademicLoad $load, int $version, User $publisher, CarbonImmutable $publishedAt): array
    {
        $load->loadMissing(['term', 'campus', 'department']);
        $profile = $load->campus->profile;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'load_id' => $load->getKey(),
            'version' => $version,
            'published_at' => $publishedAt->toIso8601String(),
            'published_by' => ['id' => $publisher->getKey(), 'name' => $publisher->name],
            'term' => ['id' => $load->term->getKey(), 'code' => $load->term->code, 'name' => $load->term->name],
            'campus' => ['id' => $load->campus->getKey(), 'code' => $load->campus->code, 'name' => $load->campus->name],
            'profile' => [
                'uses_rooms' => $profile->usesRooms,
                'uses_time_blocks' => $profile->usesTimeBlocks,
                'days' => array_values($profile->days),
                'day_start' => substr($profile->dayStart, 0, 5),
                'day_end' => substr($profile->dayEnd, 0, 5),
                'block_minutes' => $profile->blockMinutes,
            ],
            'department' => ['id' => $load->department->getKey(), 'code' => $load->department->code, 'name' => $load->department->name],
        ];
    }

    /**
     * Los problemas del payload, con la ruta de cada uno («groups.3.sessions.0.starts_at: …»); vacío si cumple.
     *
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    public static function errors(array $payload): array
    {
        $errors = [];
        self::check($payload, ['object' => self::shape()], '', $errors);

        if ($errors !== []) {
            return $errors;
        }

        foreach ($payload['groups'] as $i => $group) {
            $owners = array_keys(array_filter($group['placements'], fn (array $placement): bool => $placement['is_owner']));

            if ($owners !== [0]) {
                $errors[] = "groups.{$i}.placements: la carrera dueña va primero y es la única con is_owner";
            }

            foreach ($group['sessions'] as $j => $session) {
                if ($session['ends_at'] <= $session['starts_at']) {
                    $errors[] = "groups.{$i}.sessions.{$j}: ends_at debe ser posterior a starts_at";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $payload
     *
     * @throws InvalidArgumentException con el primer problema y cuántos más hay
     */
    public static function assertValid(array $payload): void
    {
        $errors = self::errors($payload);

        if ($errors !== []) {
            $more = count($errors) - 1;

            throw new InvalidArgumentException('El snapshot no cumple su forma: '.$errors[0].($more > 0 ? " (y {$more} más)" : ''));
        }
    }

    /**
     * La forma v1. Cada valor es un tipo escalar (`uuid`, `?uuid`, `string`, `?string`, `int`, `int>=0`, `int>=1`,
     * `?int>=1`, `bool`, `time`, `datetime`, `day`) o un arreglo: `object` (llaves exactas), `?object`, `list`,
     * `in` (uno de los valores) o `const`.
     *
     * @return array<string, mixed>
     */
    private static function shape(): array
    {
        $named = ['object' => ['id' => 'uuid', 'code' => 'string', 'name' => 'string']];

        $session = ['object' => [
            'day' => 'day',
            'starts_at' => 'time',
            'ends_at' => 'time',
            'kind' => ['in' => self::SESSION_KINDS],
            'kind_label' => 'string',
            'room' => ['?object' => [
                'id' => 'uuid',
                'code' => 'string',
                'name' => '?string',
                'building' => $named,
                'owner_department_id' => '?uuid',
            ]],
        ]];

        $placement = ['object' => [
            'career' => $named,
            'study_plan' => ['?object' => ['id' => 'uuid', 'code' => 'string']],
            'semester' => '?int>=1',
            'seats' => 'int>=0',
            'is_owner' => 'bool',
        ]];

        $group = ['object' => [
            'id' => 'uuid',
            'code' => 'string',
            'status' => ['in' => self::GROUP_STATUSES],
            'capacity' => 'int>=1',
            'justification' => '?string',
            'subject' => ['object' => [
                'id' => 'uuid',
                'code' => 'string',
                'name' => 'string',
                'credits' => 'int>=0',
                'theory_hours' => 'int>=0',
                'practice_hours' => 'int>=0',
                'department_id' => 'uuid',
            ]],
            'teacher' => ['?object' => ['id' => 'uuid', 'rfc' => 'string', 'name' => 'string']],
            'placements' => ['list' => $placement],
            'sessions' => ['list' => $session],
        ]];

        return [
            'schema_version' => ['const' => self::SCHEMA_VERSION],
            'load_id' => 'uuid',
            'version' => 'int>=1',
            'published_at' => 'datetime',
            'published_by' => ['object' => ['id' => 'uuid', 'name' => 'string']],
            'term' => $named,
            'campus' => $named,
            'profile' => ['object' => [
                'uses_rooms' => 'bool',
                'uses_time_blocks' => 'bool',
                'days' => ['list' => 'day'],
                'day_start' => 'time',
                'day_end' => 'time',
                'block_minutes' => 'int>=1',
            ]],
            'department' => $named,
            'groups' => ['list' => $group],
        ];
    }

    /**
     * @param  string|array<string, mixed>  $type
     * @param  list<string>  $errors
     */
    private static function check(mixed $value, string|array $type, string $path, array &$errors): void
    {
        if (is_string($type)) {
            if (! self::scalar($value, $type)) {
                $errors[] = ($path === '' ? 'payload' : $path).": se esperaba {$type}";
            }

            return;
        }

        $kind = array_key_first($type);
        $spec = $type[$kind];
        $label = $path === '' ? 'payload' : $path;

        if ($kind === 'const' || $kind === 'in') {
            if (! in_array($value, $kind === 'const' ? [$spec] : $spec, true)) {
                $errors[] = "{$label}: valor no admitido";
            }

            return;
        }

        if ($kind === '?object' && $value === null) {
            return;
        }

        if ($kind === 'list') {
            if (! is_array($value) || ! array_is_list($value)) {
                $errors[] = "{$label}: se esperaba una lista";

                return;
            }

            foreach ($value as $index => $item) {
                self::check($item, $spec, ltrim("{$path}.{$index}", '.'), $errors);
            }

            return;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = "{$label}: se esperaba un objeto";

            return;
        }

        foreach (array_diff(array_keys($spec), array_keys($value)) as $missing) {
            $errors[] = ltrim("{$path}.{$missing}", '.').': falta';
        }

        foreach (array_diff(array_keys($value), array_keys($spec)) as $extra) {
            $errors[] = ltrim("{$path}.{$extra}", '.').': no es parte de la forma';
        }

        foreach ($spec as $key => $child) {
            if (array_key_exists($key, $value)) {
                self::check($value[$key], $child, ltrim("{$path}.{$key}", '.'), $errors);
            }
        }
    }

    private static function scalar(mixed $value, string $type): bool
    {
        if (str_starts_with($type, '?')) {
            return $value === null || self::scalar($value, substr($type, 1));
        }

        return match ($type) {
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1,
            'string' => is_string($value) && trim($value) !== '',
            'int' => is_int($value),
            'int>=0' => is_int($value) && $value >= 0,
            'int>=1' => is_int($value) && $value >= 1,
            'bool' => is_bool($value),
            'time' => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1,
            'day' => is_int($value) && $value >= 1 && $value <= 6,
            'datetime' => is_string($value) && DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value) !== false,
            default => false,
        };
    }
}
