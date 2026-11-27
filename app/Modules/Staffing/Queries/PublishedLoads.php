<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;

/**
 * Lo publicado de un periodo para las vistas del docente (P8), por modalidad (M1): la última versión de cada carga que
 * tiene snapshot, también si se reabrió. «Mi carga» y la oferta deciden con esto, carga por carga (periodo, modalidad y
 * departamento), si un grupo sale del snapshot o de las tablas vivas; cada grupo publicado lleva su campus. Lo arma
 * PublishedLoadsQuery; es puro.
 */
final readonly class PublishedLoads
{
    /**
     * @param  array<string, array{modality: Modality, schedule: PublishedSchedule}>  $modalities  por id de modalidad, solo las que publicaron algo
     * @param  array<string, Campus>  $campuses  los campus de los grupos publicados, por id
     */
    public function __construct(private array $modalities, private array $campuses = []) {}

    /**
     * Si la carga del departamento en la modalidad tiene una versión publicada.
     */
    public function isPublished(string $modalityId, string $departmentId): bool
    {
        return isset($this->modalities[$modalityId]) && $this->modalities[$modalityId]['schedule']->isPublished($departmentId);
    }

    /**
     * Los ids de las cargas con una versión publicada, para dejar sus grupos fuera de las lecturas de lo vivo.
     *
     * @return list<string>
     */
    public function loadIds(): array
    {
        $ids = [];

        foreach ($this->modalities as $entry) {
            foreach ($entry['schedule']->loads as $load) {
                $ids[] = $load['load_id'];
            }
        }

        return $ids;
    }

    /**
     * Los grupos publicados del docente en todas las modalidades, cada uno con su campus (null en una modalidad sin
     * salones) y el departamento de su carga.
     *
     * @return list<array{campus: ?Campus, department: array{id: string, code: string, name: string}, group: PublishedGroup}>
     */
    public function teacher(string $teacherId): array
    {
        return $this->entries(fn (PublishedSchedule $schedule): array => $schedule->teacher($teacherId));
    }

    /**
     * Los grupos publicados de las cargas de estos departamentos, en todas las modalidades y campus.
     *
     * @param  list<string>  $departmentIds
     * @return list<array{campus: ?Campus, department: array{id: string, code: string, name: string}, group: PublishedGroup}>
     */
    public function departments(array $departmentIds): array
    {
        return $this->entries(fn (PublishedSchedule $schedule): array => array_merge(...array_map($schedule->department(...), $departmentIds)));
    }

    /**
     * @param  callable(PublishedSchedule): list<PublishedGroup>  $groupsOf
     * @return list<array{campus: ?Campus, department: array{id: string, code: string, name: string}, group: PublishedGroup}>
     */
    private function entries(callable $groupsOf): array
    {
        $entries = [];

        foreach ($this->modalities as ['schedule' => $schedule]) {
            foreach ($groupsOf($schedule) as $group) {
                $entries[] = ['campus' => $this->campuses[$group->campus['id'] ?? ''] ?? null, 'department' => $schedule->loads[$group->departmentId]['department'], 'group' => $group];
            }
        }

        return $entries;
    }
}
