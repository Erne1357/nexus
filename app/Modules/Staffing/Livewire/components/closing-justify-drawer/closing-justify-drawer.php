<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Actions\JustifyGroup;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Staffing\Queries\LoadClosingQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * El drawer de un grupo sin docente o sin salón en «Cierre de la carga» (HC-15; DESIGN.md, «Drawer de
 * justificación»), aparte de la lista para que abrirlo o cerrarlo no vuelva a pintar las justificaciones ni la
 * página: se abre con `open-justification` desde el navegador. Quien cierra la carga, mientras se edita, escribe,
 * cambia o quita la justificación (JustifyGroup); los demás la leen, con el motivo. Al guardar avisa con
 * `closing-justified`: la lista se vuelve a pintar y la página revisa lo que falta y lo dice.
 */
new class extends Component
{
    use Toast;

    #[Locked]
    public string $loadId = '';

    #[Locked]
    public ?string $groupId = null;

    public bool $show = false;

    public string $justification = '';

    public function mount(string $loadId): void
    {
        $this->loadId = $loadId;
        $this->load(app(LoadClosingQuery::class));
    }

    #[On('open-justification')]
    public function open(string $id, LoadClosingQuery $query): void
    {
        $group = $query->groupOrFail($this->load($query), $id);

        $this->resetValidation();
        $this->groupId = $group->getKey();
        $this->justification = (string) $group->close_justification;
        $this->show = true;
    }

    public function save(LoadClosingQuery $query, JustifyGroup $action, Actor $context): void
    {
        $this->validate(
            ['justification' => ['required', 'string', 'max:500']],
            ['justification.required' => 'Escribe la justificación, o quítala.'],
            ['justification' => 'justificación'],
        );

        $this->write($query, $action, $context, $this->justification);
    }

    public function remove(LoadClosingQuery $query, JustifyGroup $action, Actor $context): void
    {
        $this->write($query, $action, $context, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(LoadClosingQuery $query): array
    {
        if (! $this->show || $this->groupId === null) {
            return ['item' => null];
        }

        $load = $this->load($query);
        $editable = in_array($load->status, LoadEditability::EDITABLE, true);
        $canClose = Gate::allows('close', $load);

        return [
            'item' => $query->items($load, [$this->groupId])[0] ?? null,
            'load' => $load,
            'canJustify' => $editable && $canClose,
            'justifyReason' => match (true) {
                ! $editable => 'La carga está en '.mb_strtolower($load->status->label()).': las justificaciones ya no se editan.',
                ! $canClose => 'Justifican la jefatura del departamento y Subdirección.',
                default => null,
            },
        ];
    }

    private function write(LoadClosingQuery $query, JustifyGroup $action, Actor $context, ?string $text): void
    {
        abort_if($this->groupId === null, 404);

        $group = $query->groupOrFail($this->load($query), $this->groupId);
        $this->authorize('justify', $group);
        $had = $group->close_justification !== null;

        try {
            $action->handle($group, $text, $context->user() ?? abort(403));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->addError('justification', $messages[0]);
            }

            $this->error('No se guardó', e(collect($e->errors())->flatten()->first()));

            return;
        }

        $this->show = false;
        $this->dispatch('closing-justified', code: $group->code, removed: $text === null, updated: $had && $text !== null);
    }

    /**
     * La carga, para quien la cierra o la reabre; 403 si no.
     */
    private function load(LoadClosingQuery $query): AcademicLoad
    {
        $load = $query->findOrFail($this->loadId);
        abort_unless(Gate::any(['close', 'reopen'], $load), 403);

        return $load;
    }
};
