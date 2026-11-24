<?php

namespace App\Modules\Staffing\Rules;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * SEL-06. Empata el nombre del CSV con un docente por nombre normalizado (sin acentos, en mayúsculas y con los
 * espacios colapsados). Con 0 o 2 o más coincidencias no hay empate (`teacher_id` nulo) y se listan los candidatos
 * para que se resuelva a mano. Puro.
 */
final readonly class TeacherNameMatcher
{
    public static function normalize(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($name))));
    }

    /**
     * @param  Collection<int, mixed>  $teachers  objetos o arreglos con `id` y `name`
     * @return array{teacher_id: ?string, candidates: list<string>}
     */
    public static function match(string $name, Collection $teachers): array
    {
        $target = self::normalize($name);
        $candidates = $teachers
            ->filter(fn (mixed $teacher): bool => self::normalize((string) data_get($teacher, 'name')) === $target)
            ->map(fn (mixed $teacher): string => (string) data_get($teacher, 'id'))
            ->values()
            ->all();

        return ['teacher_id' => count($candidates) === 1 ? $candidates[0] : null, 'candidates' => $candidates];
    }
}
