<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Actions\ApplyReanalysisSuggestion;
use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\ReanalysisBlock;
use App\Modules\Scheduling\Queries\ReanalysisBoardQuery;
use App\Modules\Scheduling\Queries\ReanalysisLoadsQuery;
use App\Modules\Scheduling\Queries\ReanalysisSuggestions;
use App\Modules\Scheduling\Queries\ReanalysisSuggestionsQuery;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * El drawer de sugerencias de una materia en un campus de «Re-análisis de la carga» (tarea 8.2, pantalla; tarea 8.3a, Riel
 * B). Aparte de la página para que abrirlo no vuelva a pintar los bloques: se abre con `open-reanalysis-suggestions`
 * (`subjectId`, `campusId`, nulo sin campus) desde el botón «Sugerencias» de cada bloque.
 *
 * Muestra la demanda por carrera de la materia en el campus (el bloque, con las carreras que el usuario ve), con los
 * lugares de la versión vigente y los de ahora; las sugerencias del asesor (ReanalysisSuggestionsQuery: ampliar un cupo,
 * cambiar de salón, cerrar un grupo y el aviso de abrir uno, que llega con el motor) con su evidencia y su efecto; y los
 * grupos de ahora.
 *
 * Lo abre quien ve toda la carga (`view` de AcademicLoadPolicy: el jefe, el jefe de proyecto docente, Subdirección y el
 * admin); el coordinador no (RA-07: la página no le da «Sugerencias»). Cada apertura vuelve a autorizar. Aplicar,
 * editar y descartar piden además ajustar la carga (`authorizeAdjust()`: `startReanalysis`, el jefe de su departamento,
 * RA-07, y la carga en re-análisis); la Action vuelve a autorizar y revisa cada grupo con GroupPolicy. Quien la ve sin
 * ajustarla ve las sugerencias con sus botones deshabilitados y el motivo.
 *
 * Descartar es solo de esta pantalla (supuesto por confirmar): la sugerencia se esconde mientras la página siga abierta y
 * vuelve al recargar; no se guarda. Al aplicar, avisa a la página con `reanalysis-adjusted` para que pinte lo ya ajustado.
 */
new class extends Component
{
    use Toast;

    #[Locked]
    public string $loadId = '';

    #[Locked]
    public ?string $subjectId = null;

    #[Locked]
    public ?string $campusId = null;

    public bool $show = false;

    /**
     * Las sugerencias descartadas en esta pantalla, por llave.
     *
     * @var list<string>
     */
    #[Locked]
    public array $discarded = [];

    /** La sugerencia cuyos lugares se están editando. */
    #[Locked]
    public ?string $editing = null;

    /** Los lugares que se suman al aplicar la sugerencia que se edita. */
    public ?int $editSeats = null;

    public function mount(string $loadId): void
    {
        $this->loadId = $loadId;
    }

    #[On('open-reanalysis-suggestions')]
    public function open(string $subjectId, ?string $campusId, Actor $context, ReanalysisLoadsQuery $loads): void
    {
        $this->authorizeLoad($context, $loads);

        $this->subjectId = $subjectId;
        $this->campusId = $campusId;
        $this->editing = null;
        $this->show = true;
    }

    /**
     * Aplica una sugerencia, con los lugares editados si es la que se edita.
     */
    public function apply(string $key, Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query, ApplyReanalysisSuggestion $action): void
    {
        try {
            $load = $this->authorizeAdjust($context, $loads);
            $suggestion = $this->suggestion($key, $load, $context, $loads, $boards, $query);
            $seats = $this->editing === $key ? $this->editedSeats($suggestion) : null;
            $action->handle($load, $suggestion, $context->user(), $seats);
        } catch (ValidationException $exception) {
            if ($exception->validator->errors()->has('editSeats')) {
                throw $exception;
            }

            $this->error('No se aplicó la sugerencia', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        $this->editing = null;
        $this->editSeats = null;
        $this->success('Sugerencia aplicada', e("{$suggestion->title}. Entra en la versión ".($load->current_version + 1).' al publicar.'));
        $this->dispatch('reanalysis-adjusted');
    }

    /**
     * Abre la edición de los lugares que suma una sugerencia.
     */
    public function edit(string $key, Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query): void
    {
        try {
            $load = $this->authorizeAdjust($context, $loads);
            $suggestion = $this->suggestion($key, $load, $context, $loads, $boards, $query);
        } catch (ValidationException $exception) {
            $this->error('No se puede editar', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        abort_unless($suggestion->isEditable(), 403);

        $this->resetValidation();
        $this->editing = $key;
        $this->editSeats = $suggestion->seats;
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->editing = null;
        $this->editSeats = null;
    }

    /**
     * Esconde una sugerencia mientras la página siga abierta (no se guarda).
     */
    public function discard(string $key, Actor $context, ReanalysisLoadsQuery $loads): void
    {
        try {
            $this->authorizeAdjust($context, $loads);
        } catch (ValidationException $exception) {
            $this->error('No se descartó', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        if ($this->editing === $key) {
            $this->cancelEdit();
        }

        $this->discarded = array_values(array_unique([...$this->discarded, $key]));
    }

    /**
     * Vuelve a mostrar las sugerencias descartadas.
     */
    public function restoreDiscarded(Actor $context, ReanalysisLoadsQuery $loads): void
    {
        try {
            $this->authorizeAdjust($context, $loads);
        } catch (ValidationException $exception) {
            $this->error('No se puede', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        $this->discarded = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query): array
    {
        $empty = ['block' => null, 'load' => null, 'suggestions' => [], 'advice' => null, 'adjustReason' => null, 'blocked' => [], 'discardedCount' => 0];

        if (! $this->show || $this->subjectId === null) {
            return $empty;
        }

        $load = $this->authorizeLoad($context, $loads);
        $block = $this->block($load, $context, $loads, $boards);

        if ($block === null) {
            return [...$empty, 'load' => $load];
        }

        $advice = $this->suggestions($load, $block, $query);
        $visible = array_values(array_filter($advice->suggestions, fn (ReanalysisSuggestion $suggestion): bool => ! in_array($suggestion->key, $this->discarded, true)));

        return [
            'block' => $block,
            'load' => $load,
            'suggestions' => $visible,
            'advice' => $advice,
            'adjustReason' => $this->adjustReason($load),
            'blocked' => $this->blockedReasons($visible, $advice),
            'discardedCount' => count($advice->suggestions) - count($visible),
        ];
    }

    /**
     * Las sugerencias de la materia en el campus (tarea 8.3a), con los grupos de ahora: siempre del servidor.
     */
    private function suggestions(AcademicLoad $load, ReanalysisBlock $block, ReanalysisSuggestionsQuery $query): ReanalysisSuggestions
    {
        return $query->for($load, $block);
    }

    /**
     * La sugerencia de esa llave, calculada otra vez en el servidor: nunca la que trae el navegador.
     *
     * @throws ValidationException
     */
    private function suggestion(string $key, AcademicLoad $load, Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query): ReanalysisSuggestion
    {
        $block = $this->block($load, $context, $loads, $boards);
        $suggestion = $block === null ? null : $this->suggestions($load, $block, $query)->find($key);

        if ($suggestion === null || in_array($key, $this->discarded, true)) {
            throw ValidationException::withMessages(['suggestion' => 'Esa sugerencia ya no aplica: la demanda o los grupos cambiaron. Revisa las de ahora.']);
        }

        return $suggestion;
    }

    private function block(AcademicLoad $load, Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards): ?ReanalysisBlock
    {
        return $boards->block($load, $loads->careerScopeOf($context, $load), (string) $this->subjectId, $this->campusId);
    }

    /**
     * Los lugares editados, de 1 al máximo de la sugerencia.
     *
     * @throws ValidationException
     */
    private function editedSeats(ReanalysisSuggestion $suggestion): int
    {
        $this->validate(
            ['editSeats' => ['required', 'integer', 'min:1', 'max:'.$suggestion->maxSeats]],
            [
                'editSeats.required' => 'Escribe cuántos lugares sumar.',
                'editSeats.integer' => 'Los lugares son un número entero.',
                'editSeats.min' => 'Suma al menos 1 lugar.',
                'editSeats.max' => "Caben hasta {$suggestion->maxSeats} lugares.",
            ],
        );

        return (int) $this->editSeats;
    }

    /**
     * Por qué quien mira no ajusta la carga, o null si la ajusta.
     */
    private function adjustReason(AcademicLoad $load): ?string
    {
        return match (true) {
            ! Gate::allows('startReanalysis', $load) => "Solo consulta: los ajustes los hace la jefatura de {$load->department->name}.",
            $load->status !== LoadStatus::Reanalysis => 'La carga ya no está en re-análisis: las sugerencias ya no se aplican.',
            default => null,
        };
    }

    /**
     * Por qué quien ajusta no puede aplicar una sugerencia en particular (GroupPolicy sobre cada grupo que toca), por
     * llave; las que sí puede no aparecen.
     *
     * @param  list<ReanalysisSuggestion>  $suggestions
     * @return array<string, string>
     */
    private function blockedReasons(array $suggestions, ReanalysisSuggestions $advice): array
    {
        $blocked = [];

        foreach ($suggestions as $suggestion) {
            $abilities = match ($suggestion->kind) {
                ReanalysisSuggestionKind::ExpandQuota => ['manageQuotas', 'manage'],
                ReanalysisSuggestionKind::ChangeRoom => ['assignRoom', 'manageQuotas', 'manage'],
                ReanalysisSuggestionKind::CloseGroup => ['cancel', 'manageQuotas'],
                ReanalysisSuggestionKind::OpenGroup => [],
            };

            foreach ($suggestion->groupIds() as $index => $groupId) {
                $group = $advice->models[$groupId] ?? null;
                $needed = $suggestion->kind === ReanalysisSuggestionKind::CloseGroup && $index > 0 ? ['manageQuotas'] : $abilities;

                if ($group instanceof Group && ! array_all($needed, fn (string $ability): bool => Gate::allows($ability, $group))) {
                    $blocked[$suggestion->key] = "No puedes cambiar el grupo {$group->code}: lo ajusta la jefatura de su departamento.";

                    break;
                }
            }
        }

        return $blocked;
    }

    private function authorizeLoad(Actor $context, ReanalysisLoadsQuery $loads): AcademicLoad
    {
        $load = $loads->findOrFail($this->loadId);
        abort_unless($loads->careerScopeOf($context, $load) === null, 403);

        return $load;
    }

    /**
     * Quien ajusta la carga (RA-07): la ve completa, inicia su re-análisis (`startReanalysis`: el jefe de su departamento) y
     * la carga sigue en re-análisis. Sin el permiso, 403; fuera de re-análisis, el error de validación con el motivo.
     *
     * @throws ValidationException
     */
    private function authorizeAdjust(Actor $context, ReanalysisLoadsQuery $loads): AcademicLoad
    {
        $load = $this->authorizeLoad($context, $loads);
        Gate::authorize('startReanalysis', $load);

        if ($load->status !== LoadStatus::Reanalysis) {
            throw ValidationException::withMessages(['suggestion' => 'La carga ya no está en re-análisis: las sugerencias ya no se aplican.']);
        }

        return $load;
    }
};
