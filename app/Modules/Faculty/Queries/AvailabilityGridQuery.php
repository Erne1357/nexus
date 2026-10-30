<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Rules\AvailabilityRanges;

/**
 * La cuadrícula día × hora de la disponibilidad de un docente en un periodo, para el editor y la pestaña de la ficha
 * (S12, supuesto por confirmar). Quién la ve lo decide TeacherPolicy antes de llamarla.
 *
 * La disponibilidad es del docente en el periodo, no del campus: los días, el horario y el bloque de la cuadrícula
 * salen del perfil del campus activo o, sin él, del perfil presencial por omisión (profile()). Un bloque toma el tipo
 * de un rango guardado que lo toque aunque sea en parte, y el no disponible gana al preferido; lo guardado fuera de la
 * cuadrícula (otros días u horas) no se ve ni se cuenta. Las horas del resumen cuentan los bloques de la cuadrícula:
 * disponibles son todas menos las no disponibles (las preferidas también están disponibles).
 */
final class AvailabilityGridQuery
{
    private const array SHORT_NAMES = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb'];

    public function __construct(private readonly TeacherBaseHoursQuery $baseHours) {}

    /**
     * El perfil de la cuadrícula: el del campus activo o, sin él, el presencial por omisión (lunes a viernes, de 7:00
     * a 21:00, bloques de 60 minutos). El docente ya no tiene campus por su adscripción (S12, supuesto por confirmar).
     */
    public function profile(?Campus $campus): CampusProfile
    {
        return $campus?->profile ?? CampusProfile::fromArray([]);
    }

    /**
     * El campus de la cuadrícula que eligió el editor al abrirse (el activo entonces), aunque después se desactive;
     * null si no había o ya se borró.
     */
    public function campus(?string $id): ?Campus
    {
        return $id === null ? null : KeyLookup::find(Campus::query(), $id);
    }

    /**
     * Los días (`available`: sus horas disponibles) y los bloques del perfil; en `cells`, por día y bloque, su tipo
     * (null es disponible) y lo que dice la celda: el nombre del tipo en la primera de un rango y sus horas en la
     * segunda (`mono`); `continues` si sigue a otra del mismo tipo. `ranges` da, por día, los rangos unidos de la
     * cuadrícula. El resumen va en horas (medias horas con bloques de 30 minutos), con las horas base del periodo y
     * las plazas que cuentan (S1, supuesto por confirmar: vigentes el primer día).
     *
     * @return array{
     *     days: list<array{day: int, name: string, short: string, available: int|float}>,
     *     blocks: list<array{starts_at: string, ends_at: string, label: string, end_label: string}>,
     *     blockMinutes: int,
     *     schedule: string,
     *     cells: list<list<array{kind: ?AvailabilityKind, text: ?string, mono: bool, continues: bool}>>,
     *     ranges: list<list<array{starts_at: string, ends_at: string, kind: AvailabilityKind, label: string}>>,
     *     summary: array{available: int|float, preferred: int|float, unavailable: int|float, total: int|float, baseHours: int, positions: int},
     * }
     */
    public function for(Teacher $teacher, Term $term, CampusProfile $profile): array
    {
        $rows = TeacherAvailability::query()
            ->where('teacher_id', $teacher->getKey())
            ->where('term_id', $term->getKey())
            ->whereIn('day', $profile->days)
            ->get(['day', 'starts_at', 'ends_at', 'kind'])
            ->groupBy('day');

        $blocks = $this->blocks($profile);
        $days = [];
        $cells = [];
        $ranges = [];
        $counts = ['unavailable' => 0, 'preferred' => 0];

        foreach ($profile->days as $day) {
            $saved = $rows->get($day, collect())->map(fn (TeacherAvailability $row): array => ['starts_at' => substr($row->starts_at, 0, 5), 'ends_at' => substr($row->ends_at, 0, 5), 'kind' => $row->kind])->all();
            $kinds = array_map(fn (array $block): ?AvailabilityKind => AvailabilityRanges::kindOver($saved, $block['starts_at'], $block['ends_at']), $blocks);
            $unavailable = count(array_filter($kinds, fn (?AvailabilityKind $kind): bool => $kind === AvailabilityKind::Unavailable));
            $counts['unavailable'] += $unavailable;
            $counts['preferred'] += count(array_filter($kinds, fn (?AvailabilityKind $kind): bool => $kind === AvailabilityKind::Preferred));

            $days[] = [
                'day' => $day,
                'name' => AvailabilityRanges::DAY_NAMES[$day],
                'short' => self::SHORT_NAMES[$day],
                'available' => self::hours((count($blocks) - $unavailable) * $profile->blockMinutes),
            ];
            $cells[] = $this->cells($kinds, $blocks);
            $ranges[] = array_map(fn (array $range): array => [
                'starts_at' => $range['starts_at'],
                'ends_at' => $range['ends_at'],
                'kind' => $range['kind'],
                'label' => self::label($range['starts_at']).'–'.self::label($range['ends_at']),
            ], $this->dayRanges($day, $kinds, $blocks, $profile->blockMinutes));
        }

        $total = count($blocks) * count($profile->days);

        return [
            'days' => $days,
            'blocks' => $blocks,
            'blockMinutes' => $profile->blockMinutes,
            'schedule' => $profile->schedule(),
            'cells' => $cells,
            'ranges' => $ranges,
            'summary' => [
                'available' => self::hours(($total - $counts['unavailable']) * $profile->blockMinutes),
                'preferred' => self::hours($counts['preferred'] * $profile->blockMinutes),
                'unavailable' => self::hours($counts['unavailable'] * $profile->blockMinutes),
                'total' => self::hours($total * $profile->blockMinutes),
                'baseHours' => $this->baseHours->forTerm($term, [$teacher->getKey()])[$teacher->getKey()] ?? 0,
                'positions' => TeacherPosition::query()->where('teacher_id', $teacher->getKey())->currentOn($term->starts_on)->count(),
            ],
        ];
    }

    /**
     * El semestre anterior a `$term`, sin contar verano (código del SII terminado en 2): el punto de partida de
     * «Copiar del periodo anterior» (confirmado el 30 de octubre de 2026). Null si no hay.
     */
    public function previousTerm(Term $term): ?Term
    {
        return Term::query()
            ->where('starts_on', '<', $term->starts_on)
            ->where('code', 'not like', '____2')
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * Si el docente tiene disponibilidad capturada (sin borrar) en el periodo que se vea en la cuadrícula del perfil: en
     * uno de sus días y que toque su horario. Lo de otros días u horas no cuenta, porque copiarlo no traería nada.
     */
    public function hasAvailability(Teacher $teacher, Term $term, CampusProfile $profile): bool
    {
        return TeacherAvailability::query()
            ->where('teacher_id', $teacher->getKey())
            ->where('term_id', $term->getKey())
            ->whereIn('day', $profile->days)
            ->where('starts_at', '<', $profile->dayEnd)
            ->where('ends_at', '>', $profile->dayStart)
            ->exists();
    }

    /**
     * «7:00» de «07:00».
     */
    public static function label(string $time): string
    {
        return (int) substr($time, 0, 2).substr($time, 2, 3);
    }

    /**
     * Minutos en horas: enteras si caben, si no con un decimal.
     */
    public static function hours(int $minutes): int|float
    {
        return $minutes % 60 === 0 ? intdiv($minutes, 60) : round($minutes / 60, 1);
    }

    /**
     * @return list<array{starts_at: string, ends_at: string, label: string, end_label: string}>
     */
    private function blocks(CampusProfile $profile): array
    {
        return array_map(
            fn (array $block): array => [...$block, 'label' => self::label($block['starts_at']), 'end_label' => self::label($block['ends_at'])],
            AvailabilityRanges::blocks($profile->dayStart, $profile->dayEnd, $profile->blockMinutes),
        );
    }

    /**
     * Lo que dice cada celda de un día: el nombre del tipo en la primera de un rango y sus horas en la segunda.
     *
     * @param  list<?AvailabilityKind>  $kinds
     * @param  list<array{starts_at: string, ends_at: string, label: string, end_label: string}>  $blocks
     * @return list<array{kind: ?AvailabilityKind, text: ?string, mono: bool, continues: bool}>
     */
    private function cells(array $kinds, array $blocks): array
    {
        $cells = [];
        $runStart = 0;

        foreach ($kinds as $index => $kind) {
            $continues = $kind !== null && $index > 0 && $kinds[$index - 1] === $kind;
            $runStart = $continues ? $runStart : $index;
            $runEnd = $index;

            while ($kind !== null && $runEnd + 1 < count($kinds) && $kinds[$runEnd + 1] === $kind) {
                $runEnd++;
            }

            $cells[] = [
                'kind' => $kind,
                'text' => match (true) {
                    $kind === null => null,
                    ! $continues => $kind->label(),
                    $index === $runStart + 1 => $blocks[$runStart]['label'].'–'.$blocks[$runEnd]['end_label'],
                    default => null,
                },
                'mono' => $continues && $index === $runStart + 1,
                'continues' => $continues,
            ];
        }

        return $cells;
    }

    /**
     * Los rangos unidos de un día de la cuadrícula.
     *
     * @param  list<?AvailabilityKind>  $kinds
     * @param  list<array{starts_at: string}>  $blocks
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>
     */
    private function dayRanges(int $day, array $kinds, array $blocks, int $blockMinutes): array
    {
        $cells = [];

        foreach ($kinds as $index => $kind) {
            if ($kind !== null) {
                $cells[] = ['day' => $day, 'starts_at' => $blocks[$index]['starts_at'], 'kind' => $kind];
            }
        }

        return AvailabilityRanges::merge($cells, $blockMinutes);
    }
}
