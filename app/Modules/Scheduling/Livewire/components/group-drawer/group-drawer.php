<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Actions\MoveGroupToCampus;
use App\Modules\Scheduling\Queries\ScheduleEditorQuery;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * El cajón de un grupo del editor de horarios (maqueta `cajones.html`, `#grupo`; DESIGN.md, "Editor de horarios"): su
 * materia, quién imparte, capacidad, docente de solo lectura, sus sesiones (cada una abre su cajón) y «Cupos por
 * carrera» (`scheduling::components.group-quotas`, que avisa con `quotas-saved` y este cajón se vuelve a pintar).
 * Abrirlo (`open-group`) no vuelve a pintar la semana. Pide ver el grupo (ScheduleEditorQuery::sees).
 *
 * «Campus» (M8, MS9; mínimo, la pantalla final es de U2): quien gestiona el grupo aquí y en otro campus que ofrece su
 * carrera (GroupPolicy::moveToCampus) lo cambia de campus con confirmación (MoveGroupToCampus). El grupo deja el campus
 * de la página: el cajón se cierra y la semana se vuelve a pintar (`schedule-changed`). Si de antemano se sabe que no se
 * puede (un acuerdo de grupo compartido vivo, MS25), el botón va deshabilitado con el motivo escrito al lado.
 */
new class extends Component
{
    use Toast;

    #[Locked]
    public string $termId = '';

    #[Locked]
    public string $campusId = '';

    public bool $show = false;

    #[Locked]
    public ?string $groupId = null;

    /** La clave del campus al que se cambia el grupo. */
    public string $targetCampus = '';

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
        $this->targetCampus = '';
        $this->show = true;
    }

    /**
     * Cambia el grupo al campus elegido (M8): pide gestionarlo aquí y allá; la Action exige la carga abierta, sin
     * salones en sus sesiones y que el campus ofrezca su carrera (MS9).
     */
    public function moveToCampus(MoveGroupToCampus $move, ScheduleEditorQuery $editor, Actor $context): void
    {
        abort_if($this->groupId === null, 404);

        $term = $editor->term($this->termId);
        $campus = $editor->campus($this->campusId);
        $group = $editor->groupOrFail($term, $campus, $this->groupId);
        $target = $editor->campusOptions($context, $group)->firstWhere('code', $this->targetCampus);
        $this->authorize('moveToCampus', [$group, $target ?? abort(403)]);

        try {
            $move->handle($group, $target);
        } catch (ValidationException $exception) {
            $this->addError('campus', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $editor->forget();
        $this->show = false;
        $this->groupId = null;
        $this->dispatch('schedule-changed');
        $this->success('Grupo cambiado de campus', e("Ahora es de {$target->name}."));
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
        $group = $editor->groupOrFail($editor->term($this->termId), $campus, $this->groupId);
        $campuses = $editor->campusOptions(app(Actor::class), $group);

        return [
            'panel' => $editor->groupPanel($group),
            'campusOptions' => $campuses->map(fn ($option): array => ['id' => $option->code, 'name' => $option->name])->values()->all(),
            'currentCampus' => $campus,
            'moveBlocked' => $campuses->isEmpty() ? null : $editor->campusMoveBlockedReason($group),
        ];
    }
};
