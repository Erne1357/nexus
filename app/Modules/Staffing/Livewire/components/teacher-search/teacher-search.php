<?php

use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Queries\TeacherSearchQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * «Buscar otro docente · nombre o RFC» (HC-21): busca entre los docentes activos de cualquier departamento, desde tres
 * caracteres y con a lo más diez resultados (TeacherSearchQuery), para asignar a uno a un grupo sin llenar el
 * selector con todos. Es independiente de la pantalla donde se pone: recibe el grupo y, al elegir a uno, avisa con
 * `teacher-picked` (groupId, teacherId); quien lo escucha decide cómo asignar (motivo de HC-16, AssignTeacher). Solo
 * busca quien asigna en el grupo (GroupTeacherAssignmentPolicy::create); `assignable` apaga «Elegir» cuando la carga no
 * acepta asignación.
 */
new class extends Component
{
    #[Locked]
    public string $groupId = '';

    #[Locked]
    public bool $assignable = true;

    public string $term = '';

    public function mount(string $groupId, bool $assignable = true): void
    {
        $this->groupId = $groupId;
        $this->assignable = $assignable;
        $this->group();
    }

    public function pick(string $teacherId): void
    {
        $group = $this->group();

        abort_unless($this->assignable, 403);

        $this->dispatch('teacher-picked', groupId: $group->getKey(), teacherId: $teacherId);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(TeacherSearchQuery $query): array
    {
        $term = trim($this->term);

        return [
            'ready' => mb_strlen($term) >= TeacherSearchQuery::MIN_LENGTH,
            'results' => $query->search($this->group(), $term),
        ];
    }

    private function group(): Group
    {
        $group = Group::query()->findOrFail($this->groupId);
        $this->authorize('create', [GroupTeacherAssignment::class, $group]);

        return $group;
    }
};
