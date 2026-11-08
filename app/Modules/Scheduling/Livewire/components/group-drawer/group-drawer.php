<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Queries\ScheduleEditorQuery;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * El cajón de un grupo del editor de horarios (maqueta `cajones.html`, `#grupo`; DESIGN.md, "Editor de horarios"): su
 * materia, quién imparte, capacidad, docente de solo lectura, sus sesiones (cada una abre su cajón) y «Cupos por
 * carrera» (`scheduling::components.group-quotas`, que avisa con `quotas-saved` y este cajón se vuelve a pintar).
 * Abrirlo (`open-group`) no vuelve a pintar la semana. Pide ver el grupo (ScheduleEditorQuery::sees).
 */
new class extends Component
{
    #[Locked]
    public string $termId = '';

    #[Locked]
    public string $campusId = '';

    public bool $show = false;

    #[Locked]
    public ?string $groupId = null;

    public function mount(string $termId, string $campusId): void
    {
        $this->termId = $termId;
        $this->campusId = $campusId;
    }

    #[On('open-group')]
    public function open(string $id, ScheduleEditorQuery $editor, Actor $context): void
    {
        $term = $editor->term($this->termId);
        $campus = $editor->campus($this->campusId);
        $group = $editor->groupOrFail($term, $campus, $id);
        abort_unless($editor->sees($context, $term, $campus, $group), 403);

        $this->groupId = $group->id;
        $this->show = true;
    }

    /**
     * Los cupos cambiaron: el cajón se vuelve a pintar.
     */
    #[On('quotas-saved')]
    public function quotasSaved(): void {}

    /**
     * @return array<string, mixed>
     */
    public function with(ScheduleEditorQuery $editor): array
    {
        if (! $this->show || $this->groupId === null) {
            return ['panel' => null];
        }

        $campus = $editor->campus($this->campusId);

        return ['panel' => $editor->groupPanel($editor->groupOrFail($editor->term($this->termId), $campus, $this->groupId), $campus)];
    }
};
