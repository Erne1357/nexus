<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\Core\Models\Campus;

/**
 * Lo publicado de un periodo para las vistas del docente (P8), por campus: la última versión de cada carga que tiene
 * snapshot, también si se reabrió. «Mi carga» y la oferta deciden con esto, carga por carga (periodo, campus y
 * departamento), si un grupo sale del snapshot o de las tablas vivas. Lo arma PublishedLoadsQuery; es puro.
 */
final readonly class PublishedLoads
{
    /**
     * @param  array<string, array{campus: Campus, schedule: PublishedSchedule}>  $campuses  por id de campus, solo los que publicaron algo
     */
    public function __construct(private array $campuses) {}

    /**
     * Si la carga del departamento en el campus tiene una versión publicada.
     */
    public function isPublished(string $campusId, string $departmentId): bool
    {
        return isset($this->campuses[$campusId]) && $this->campuses[$campusId]['schedule']->isPublished($departmentId);
    }

    /**
     * Los ids de las cargas con una versión publicada, para dejar sus grupos fuera de las lecturas de lo vivo.
     *
     * @return list<string>
     */
    public function loadIds(): array
    {
        $ids = [];

        foreach ($this->campuses as $entry) {
            foreach ($entry['schedule']->loads as $load) {
                $ids[] = $load['load_id'];
            }
        }

        return $ids;
    }

    /**
     * Los grupos publicados del docente en todos los campus, cada uno con su campus y el departamento de su carga.
     *
     * @return list<array{campus: Campus, department: array{id: string, code: string, name: string}, group: PublishedGroup}>
     */
    public function teacher(string $teacherId): array
    {
        return $this->entries(fn (PublishedSchedule $schedule): array => $schedule->teacher($teacherId));
    }

    /**
     * Los grupos publicados de las cargas de estos departamentos, en todos los campus.
     *
     * @param  list<string>  $departmentIds
     * @return list<array{campus: Campus, department: array{id: string, code: string, name: string}, group: PublishedGroup}>
     */
    public function departments(array $departmentIds): array
    {
        return $this->entries(fn (PublishedSchedule $schedule): array => array_merge(...array_map($schedule->department(...), $departmentIds)));
    }

    /**
     * @param  callable(PublishedSchedule): list<PublishedGroup>  $groupsOf
     * @return list<array{campus: Campus, department: array{id: string, code: string, name: string}, group: PublishedGroup}>
     */
    private function entries(callable $groupsOf): array
    {
        $entries = [];

        foreach ($this->campuses as ['campus' => $campus, 'schedule' => $schedule]) {
            foreach ($groupsOf($schedule) as $group) {
                $entries[] = ['campus' => $campus, 'department' => $schedule->loads[$group->departmentId]['department'], 'group' => $group];
            }
        }

        return $entries;
    }
}
