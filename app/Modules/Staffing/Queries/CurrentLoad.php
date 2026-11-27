<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Support\Str;

/**
 * Todo lo que muestra «Carga actual» de un departamento en un periodo y modalidad (CurrentLoadQuery), agrupado por
 * materia, con las cuentas de los filtros rápidos y el filtrado por estado, carrera y búsqueda.
 */
final readonly class CurrentLoad
{
    public const array STATES = ['todos', 'sin_docente', 'sin_salon', 'choque', 'sin_espacio'];

    /**
     * @param  list<CurrentLoadSubject>  $subjects  por semestre más bajo de la materia, nombre y clave
     */
    public function __construct(
        public Term $term,
        public Modality $modality,
        public ?Campus $campus,
        public Department $department,
        public ?AcademicLoad $load,
        public array $subjects,
    ) {}

    public function total(): int
    {
        return $this->count('todos');
    }

    public function withTeacher(): int
    {
        return $this->total() - $this->count('sin_docente');
    }

    /**
     * Cuántos grupos entran en un filtro rápido, sin los otros filtros.
     */
    public function count(string $state): int
    {
        $n = 0;

        foreach ($this->subjects as $subject) {
            foreach ($subject->groups as $row) {
                $n += self::matchesState($subject, $row, $state) ? 1 : 0;
            }
        }

        return $n;
    }

    /**
     * Las materias con los grupos que cumplen el filtro rápido, la carrera dueña (clave; '' todas) y la búsqueda
     * (grupo, materia o docente, sin importar acentos ni mayúsculas). Una materia sin grupos a la vista se omite.
     *
     * @return list<CurrentLoadSubject>
     */
    public function filtered(string $state, string $career, string $search): array
    {
        $needle = self::fold(trim($search));
        $out = [];

        foreach ($this->subjects as $subject) {
            $groups = array_values(array_filter($subject->groups, function (CurrentLoadGroup $row) use ($subject, $state, $career, $needle): bool {
                $group = $row->group;

                return self::matchesState($subject, $row, $state)
                    && ($career === '' || $group->ownerCareer?->code === $career)
                    && ($needle === '' || str_contains(self::fold("{$group->code} {$subject->subject->name} {$subject->subject->code} {$group->teacher?->name}"), $needle));
            }));

            if ($groups !== []) {
                $out[] = new CurrentLoadSubject($subject->subject, $groups);
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> clave de carrera → nombre, de los grupos de la carga
     */
    public function careers(): array
    {
        $careers = [];

        foreach ($this->subjects as $subject) {
            foreach ($subject->groups as $row) {
                if ($row->group->ownerCareer !== null) {
                    $careers[$row->group->ownerCareer->code] = $row->group->ownerCareer->code;
                }
            }
        }

        ksort($careers);

        return $careers;
    }

    private static function matchesState(CurrentLoadSubject $subject, CurrentLoadGroup $row, string $state): bool
    {
        return match ($state) {
            'sin_docente' => ! $row->hasTeacher(),
            'sin_salon' => $row->withoutRoom,
            'choque' => $row->conflicts !== [],
            'sin_espacio' => $subject->subject->required_space === null,
            default => true,
        };
    }

    private static function fold(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }
}
