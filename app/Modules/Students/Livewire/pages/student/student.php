<?php

use App\Modules\Core\Support\SemesterGrid;
use App\Modules\Students\Enums\SubjectStanding;
use App\Modules\Students\Queries\GridCell;
use App\Modules\Students\Queries\StudentQuery;
use App\Modules\Students\Queries\StudentRecordQuery;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ficha del alumno (tarea 1.6): sus datos y cifras, su retícula por semestre con el estado de cada materia como
 * lo nombra el SII y su kárdex por periodo. Solo lectura. Carga al alumno con StudentQuery (el id llega de la
 * ruta), autoriza `view` con StudentPolicy, arma la ficha con StudentRecordQuery y acomoda su retícula en la
 * cuadrícula de Core (SemesterGrid), con el tronco común arriba y una banda por especialidad.
 */
new #[Title('Ficha del alumno')] class extends Component
{
    /** El alumno de la ficha. El navegador no lo puede cambiar. */
    #[Locked]
    public string $studentId;

    /** 'reticula' o 'kardex'; va en la URL para volver a la misma pestaña, y otro valor muestra la retícula. */
    #[Url]
    public string $tab = 'reticula';

    public function mount(string $student, StudentQuery $students): void
    {
        $model = $students->findOrFail($student);
        $this->authorize('view', $model);
        $this->studentId = $model->id;
        $this->updatedTab();
    }

    /**
     * Una pestaña que no existe (un enlace viejo o mal escrito) muestra la retícula.
     */
    public function updatedTab(): void
    {
        $this->tab = $this->tab === 'kardex' ? 'kardex' : 'reticula';
    }

    /**
     * @return array<string, mixed>
     */
    public function with(StudentQuery $students, StudentRecordQuery $records): array
    {
        $student = $students->findOrFail($this->studentId);
        $this->authorize('view', $student);

        $record = $records->forStudent($student);

        return [
            'record' => $record,
            'layout' => SemesterGrid::place(
                array_map(fn (array $cells): array => collect($cells)->mapWithKeys(fn (GridCell $cell): array => [$cell->code => $cell->specialtyId])->all(), $record->semesters),
                array_column($record->specialties, 'id'),
            ),
            'specialtyNames' => array_column($record->specialties, 'name', 'id'),
            'standings' => SubjectStanding::cases(),
        ];
    }
};
