<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\User;
use Illuminate\Support\Arr;

/**
 * Lo que el cajón «Dar de baja» muestra antes de confirmar (CF-05, CF-09), armado por DeactivationPreviewQuery.
 */
final readonly class DeactivationPreview
{
    /**
     * `assignments` son los que terminarían hoy (`removed` falso) o se quitarían; `vacates`, si su puesto exclusivo se
     * queda sin titular.
     *
     * @param  list<array{summary: string, removed: bool, vacates: bool}>  $assignments
     * @param  list<array{code: string, name: string}>|null  $teacherDepartments  nulo si no es docente
     * @param  string|null  $adminGap  si dejaría algún día sin admin vigente (AZ-S12), el texto con ese día
     * @param  string|null  $blocker  por qué no se puede dar de baja; nulo si sí
     */
    public function __construct(
        public User $person,
        public array $assignments,
        public ?array $teacherDepartments,
        public ?string $adminGap,
        public ?string $blocker,
    ) {}

    public function isTeacher(): bool
    {
        return $this->teacherDepartments !== null;
    }

    public function wouldLeaveNoAdmin(): bool
    {
        return $this->adminGap !== null;
    }

    /**
     * Los puestos exclusivos (AZ-S7) que se quedarían sin titular.
     *
     * @return list<string>
     */
    public function vacatedSeats(): array
    {
        return array_values(array_map(
            fn (array $row): string => $row['summary'],
            array_filter($this->assignments, fn (array $row): bool => $row['vacates']),
        ));
    }

    /**
     * El aviso de CF-09 (confirmado el 14 de noviembre de 2026) si también es docente: sigue en el catálogo de docentes,
     * y su baja como docente es «Quitar de <departamento>» en Docentes; nulo si no es docente.
     */
    public function teacherNotice(): ?string
    {
        if ($this->teacherDepartments === null) {
            return null;
        }

        $actions = array_map(fn (array $department): string => "«Quitar de {$department['code']}»", $this->teacherDepartments);

        return 'Sigue en el catálogo de docentes. Para darlo de baja como docente, usa '
            .($actions === [] ? 'Docentes.' : Arr::join($actions, ', ', ' y ').' en Docentes.');
    }
}
