<?php

use App\Modules\Students\Enums\SubjectStanding;
use App\Modules\Students\Queries\StudentQuery;
use App\Modules\Students\Queries\StudentRecordQuery;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ficha del alumno (tarea 1.6): sus datos y cifras, su retícula por semestre con el estado de cada materia como
 * lo nombra el SII y su kárdex por periodo. Solo lectura. Carga al alumno con StudentQuery (el id llega de la
 * ruta), autoriza `view` con StudentPolicy y arma la ficha con StudentRecordQuery.
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

        return [
            'record' => $records->forStudent($student),
            'standings' => SubjectStanding::cases(),
        ];
    }
};
