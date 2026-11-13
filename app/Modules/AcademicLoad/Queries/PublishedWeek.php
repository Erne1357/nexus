<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Core\Support\WeekTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Lo que las páginas de la consulta pública pintan de unos grupos publicados: sus sesiones para la semana de un docente
 * o un salón, el resumen de horario de un grupo, las horas a la semana y la fecha de «Actualizado el». Es puro: no lee
 * la base ni el payload, solo da forma a PublishedGroup.
 */
final class PublishedWeek
{
    private const array SHORT = [1 => 'L', 2 => 'M', 3 => 'Mi', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

    /**
     * La posición de cada materia en el color de su cadena (`WeekGrid::CHAINS`): por orden de clave, cíclico.
     *
     * @param  list<PublishedGroup>  $groups
     * @return array<string, int> clave de materia → posición (0 en adelante)
     */
    public static function chains(array $groups): array
    {
        $codes = array_values(array_unique(array_map(fn (PublishedGroup $group): string => $group->subject['code'], $groups)));
        sort($codes);

        return array_flip($codes);
    }

    /**
     * La clase Tailwind que fija `--chain` de una materia.
     *
     * @param  array<string, int>  $chains
     */
    public static function chainClass(array $chains, string $subjectCode): string
    {
        return WeekGrid::CHAINS[($chains[$subjectCode] ?? 0) % count(WeekGrid::CHAINS)];
    }

    /**
     * Una sesión por elemento, con lo que pide la semana (WeekGrid::lay()): día y horas, «CLAVE · grupo», la materia, el
     * docente o el salón y el tipo.
     *
     * @param  list<PublishedGroup>  $groups
     * @return list<array{day: int, starts_at: string, ends_at: string, title: string, subject: string, subject_code: string, teacher: ?array{id: string, name: string}, room: ?array{id: string, code: string}, kind: string, group_id: string}>
     */
    public static function sessions(array $groups): array
    {
        $items = [];

        foreach ($groups as $group) {
            foreach ($group->sessions as $session) {
                $items[] = [
                    'day' => $session->day,
                    'starts_at' => $session->startsAt,
                    'ends_at' => $session->endsAt,
                    'title' => $group->label(),
                    'subject' => $group->subject['name'],
                    'subject_code' => $group->subject['code'],
                    'teacher' => $group->teacher,
                    'room' => $session->room === null ? null : ['id' => $session->room['id'], 'code' => $session->room['code']],
                    'kind' => $session->kind,
                    'group_id' => $group->id,
                ];
            }
        }

        return $items;
    }

    /**
     * La semana de unos grupos (docente o salón): la de WeekGrid del perfil, extendida a lo que cae fuera de él, con cada
     * sesión en su renglón y carril, los carriles de cada día y los colores de las materias.
     *
     * @param  list<PublishedGroup>  $groups
     * @return array{grid: WeekGrid, laid: array<int, list<array<string, mixed>>>, lanes: array<int, int>, items: list<array<string, mixed>>, chains: array<string, int>}
     */
    public static function week(array $groups, ModalityProfile $profile): array
    {
        $items = self::sessions($groups);
        $grid = WeekGrid::of($profile)->covering($items);
        $laid = $grid->lay($items);

        return ['grid' => $grid, 'laid' => $laid, 'lanes' => $grid->lanes($laid), 'items' => $items, 'chains' => self::chains($groups)];
    }

    /**
     * Las horas a la semana de unos grupos: la suma de sus sesiones, enteras si caben, si no con un decimal.
     *
     * @param  list<PublishedGroup>  $groups
     */
    public static function weeklyHours(array $groups): int|float
    {
        $minutes = 0;

        foreach ($groups as $group) {
            foreach ($group->sessions as $session) {
                $minutes += max(0, WeekTime::minutes($session->endsAt) - WeekTime::minutes($session->startsAt));
            }
        }

        return WeekGrid::hours($minutes);
    }

    /**
     * El horario de un grupo en pocas líneas: las sesiones iguales (mismas horas, salón y tipo) se juntan, «L a V» si los
     * días son seguidos.
     *
     * @return list<array{days: string, hours: string, room: ?array{id: string, code: string}, kind: string}>
     */
    public static function summary(PublishedGroup $group): array
    {
        $merged = [];

        foreach ($group->sessions as $session) {
            $key = implode('|', [$session->startsAt, $session->endsAt, $session->room['id'] ?? '', $session->kind]);
            $merged[$key]['session'] = $session;
            $merged[$key]['days'][] = $session->day;
        }

        return array_values(array_map(function (array $entry): array {
            $days = $entry['days'];
            sort($days);
            $consecutive = count($days) >= 3 && $days === range($days[0], $days[array_key_last($days)]);
            $letters = $consecutive
                ? (self::SHORT[$days[0]] ?? $days[0]).' a '.(self::SHORT[$days[array_key_last($days)]] ?? '')
                : implode(' ', array_map(fn (int $day): string => self::SHORT[$day] ?? (string) $day, $days));
            $session = $entry['session'];

            return [
                'days' => $letters,
                'hours' => WeekGrid::range($session->startsAt, $session->endsAt),
                'room' => $session->room === null ? null : ['id' => $session->room['id'], 'code' => $session->room['code']],
                'kind' => $session->kind,
            ];
        }, $merged));
    }

    /**
     * «12 de diciembre de 2026», en español y en la zona de la aplicación; vacío sin fecha.
     */
    public static function updatedOn(?CarbonImmutable $at): string
    {
        return $at === null ? '' : $at->timezone(config('app.timezone'))->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }

    /**
     * Quita acentos y mayúsculas, para buscar «rios» y encontrar «Ríos».
     */
    public static function fold(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }
}
