<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Actions\ApplyReanalysisSuggestion;
use App\Modules\Scheduling\Actions\ApplyReanalysisSuggestions;
use App\Modules\Scheduling\Actions\OpenReanalysisGroup;
use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\NewGroupPlacementQuery;
use App\Modules\Scheduling\Queries\ReanalysisBlock;
use App\Modules\Scheduling\Queries\ReanalysisBoardQuery;
use App\Modules\Scheduling\Queries\ReanalysisLoadsQuery;
use App\Modules\Scheduling\Queries\ReanalysisSuggestions;
use App\Modules\Scheduling\Queries\ReanalysisSuggestionsQuery;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use App\Modules\Scheduling\Rules\Reanalysis\SuggestionBatch;
use App\Modules\Scheduling\Rules\Reanalysis\SuggestionBatchResult;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
 *
 * «Abrir un grupo» (tarea 8.3b) pide su horario y su salón al motor solo cuando el jefe lo busca en su tarjeta
 * (`findPlacement()`, NewGroupPlacementQuery: 0.7–0.9 s con la demo masiva, así que va en la petición y no en cola) y lo
 * guarda en `placements` con la firma de la sugerencia: nunca se calcula al pintar, y si la sugerencia cambia (se aplicó
 * otra), el lugar ya no se usa y se vuelve a buscar. «Editar» elige otro salón libre de la lista cuando todas sus sesiones
 * van en uno (NewGroupPlacementQuery::freeRooms()); «Aplicar» lo crea con OpenReanalysisGroup, que revisa con los candados
 * puestos que siga libre.
 *
 * «Aplicar las N elegidas» (seguimiento de la 8.3a): al abrir el bloque vienen marcadas las sugerencias que se pueden
 * aplicar (confirmado el 18 de noviembre de 2026, como en la maqueta B; si dos chocan, la primera), quien ajusta desmarca o
 * marca (`chosen`, `wire:model.live`: cada cambio vuelve a autorizar y a pintar) y las aplica juntas con
 * ApplyReanalysisSuggestions, una por una («Se aplican las que pasan», confirmado el 18 de noviembre de 2026), después de
 * la confirmación de Nexus. No se eligen (supuestos por confirmar) las que no se pueden aplicar (la de solo aviso, la
 * bloqueada), «Abrir un grupo» sin su lugar buscado (buscarlo cuesta casi un segundo y el lote no lo busca), la que se
 * está editando (se aplica con sus lugares o su salón, con su «Aplicar») ni la que choca con una ya elegida
 * (SuggestionBatch, con el lugar buscado del grupo nuevo); cada una dice por qué. Al pintar, las elegidas que ya no valen
 * salen de `chosen`.
 *
 * La regla de marcar vuelve a correr (`preselect`; incidencia F-06 del ensayo 8.11) cada vez que el bloque se vuelve a
 * calcular después de aplicar (una sugerencia, el lote o un grupo nuevo) y cuando «Abrir un grupo» encuentra su lugar, sin
 * tocar lo que el jefe ya decidió en esta visita (supuesto por confirmar): las que desmarcó a mano o descartó
 * (`unchecked`) no se vuelven a marcar hasta que él las marque o vuelva a abrir el bloque, ni la que se desmarcó porque
 * cambió (también la que el lote no aplicó por eso, que conserva su aviso). Desmarcar a mano es lo que llega del navegador:
 * la casilla que estaba en `chosen` antes del cambio (updatingChosen()) y ya no viene; lo que quita el servidor al pintar
 * (ya no se puede elegir, choca o cambió) no cuenta.
 *
 * Lo que el jefe vio de cada elegida queda en `seen` (SuggestionBatch::signature() y su título). Si al volver a pintar la
 * sugerencia ya no es la misma, se desmarca y dice «Cambió desde que la elegiste»; si cambió entre el último pintado y
 * «Aplicar», no se aplica y el toast lo dice (supuesto por confirmar).
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
     * Las sugerencias descartadas en esta pantalla, por materia, campus y llave (discardKey()).
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

    /**
     * El lugar que encontró el motor para cada «Abrir un grupo», por discardKey(): la firma de la sugerencia con la que
     * se buscó (signature()) y el lugar (NewGroupPlacement::toArray()).
     *
     * @var array<string, array{signature: string, placement: array<string, mixed>}>
     */
    #[Locked]
    public array $placements = [];

    /**
     * Los salones a los que se puede cambiar el grupo nuevo que se edita, por id.
     *
     * @var array<string, array{code: string, capacity: int}>
     */
    #[Locked]
    public array $roomOptions = [];

    /** El salón elegido al editar «Abrir un grupo». */
    public ?string $editRoomId = null;

    /**
     * Las sugerencias elegidas para aplicar juntas, por llave, en el orden en que se eligieron.
     *
     * @var list<string>
     */
    public array $chosen = [];

    /**
     * Lo que el jefe vio de cada elegida al elegirla, por llave: su firma (SuggestionBatch::signature()) y su título.
     *
     * @var array<string, array{signature: string, title: string}>
     */
    #[Locked]
    public array $seen = [];

    /**
     * Las que se desmarcaron porque cambiaron desde que se eligieron, por llave, con el título que tenían.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $changedSinceChosen = [];

    /**
     * Si al pintar se marcan, además de las elegidas, las que se pueden aplicar y el jefe no desmarcó: al abrir el bloque,
     * al volver a calcularlo después de aplicar y cuando «Abrir un grupo» encuentra su lugar.
     */
    #[Locked]
    public bool $preselect = false;

    /**
     * Las que el jefe desmarcó a mano o descartó en esta visita del bloque, por llave: no se vuelven a marcar solas hasta que
     * las marque o vuelva a abrir el bloque (supuesto por confirmar: por llave, aunque la sugerencia cambie).
     *
     * @var list<string>
     */
    #[Locked]
    public array $unchecked = [];

    /**
     * Lo que estaba elegido antes del cambio de casillas que llega del navegador (updatingChosen()); no viaja.
     *
     * @var list<mixed>
     */
    private array $chosenBefore = [];

    /** Las sugerencias ya calculadas en esta petición (no viajan al navegador); aplicar las borra. */
    private ?ReanalysisSuggestions $advice = null;

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
        $this->chosen = [];
        $this->seen = [];
        $this->changedSinceChosen = [];
        $this->unchecked = [];
        $this->preselect = true;
        $this->placements = array_filter($this->placements, fn (string $key): bool => str_starts_with($key, $this->discardKey('')), ARRAY_FILTER_USE_KEY);
        $this->show = true;
    }

    /**
     * Aplica una sugerencia, con los lugares editados si es la que se edita. «Abrir un grupo» se aplica con el lugar que
     * encontró el motor (y el salón editado); si no se pudo, ese lugar se olvida para buscarlo otra vez.
     */
    public function apply(string $key, Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query, ApplyReanalysisSuggestion $action, OpenReanalysisGroup $open): void
    {
        $suggestion = null;

        try {
            $load = $this->authorizeAdjust($context, $loads);
            $suggestion = $this->suggestion($key, $load, $context, $loads, $boards, $query);

            if ($suggestion->kind === ReanalysisSuggestionKind::OpenGroup) {
                $this->openGroup($load, $suggestion, $context, $open);

                return;
            }

            $seats = $this->editing === $key ? $this->editedSeats($suggestion) : null;
            $action->handle($load, $suggestion, $context->user(), $seats);
        } catch (ValidationException $exception) {
            if ($exception->validator->errors()->hasAny(['editSeats', 'editRoomId'])) {
                throw $exception;
            }

            if ($suggestion?->kind === ReanalysisSuggestionKind::OpenGroup) {
                unset($this->placements[$this->discardKey($key)]);
                $this->cancelEdit();
            }

            $this->error('No se aplicó la sugerencia', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        $this->advice = null;
        $this->editing = null;
        $this->editSeats = null;
        $this->preselect = true;
        $this->success('Sugerencia aplicada', e("{$suggestion->title}. Entra en la versión ".($load->current_version + 1).' al publicar.'));
        $this->dispatch('reanalysis-adjusted');
    }

    /**
     * Guarda lo elegido antes del cambio de casillas que llega del navegador, para saber cuáles desmarcó el jefe.
     */
    public function updatingChosen(): void
    {
        $this->chosenBefore = $this->chosen;
    }

    /**
     * Elegir o dejar de elegir es de quien ajusta la carga: vuelve a autorizar. Fuera de re-análisis no queda ninguna
     * elegida (la nota de arriba dice por qué); lo que no se puede elegir lo quita with(). La que estaba elegida y ya no
     * viene se desmarcó a mano y va a `unchecked`; la que se marca a mano sale de ahí.
     */
    public function updatedChosen(Actor $context, ReanalysisLoadsQuery $loads): void
    {
        try {
            $this->authorizeAdjust($context, $loads);
        } catch (ValidationException) {
            $this->chosen = [];

            return;
        }

        $chosen = array_values(array_filter($this->chosen, is_string(...)));
        $before = array_values(array_filter($this->chosenBefore, is_string(...)));
        $this->unchecked = array_values(array_unique([...array_diff($this->unchecked, $chosen), ...array_diff($before, $chosen)]));
    }

    /**
     * Aplica juntas las elegidas con ApplyReanalysisSuggestions, cada una como la calcula ahora el servidor (nunca la del
     * navegador); «Abrir un grupo», con el lugar que encontró el motor, que después se olvida (se aplique o no). Una elegida
     * que ya no se sugiere cuenta como no aplicada. Si el lote no se puede (la carga fuera de re-análisis, dos que chocan),
     * no aplica ninguna y lo dice; si no, el toast dice cuántas se aplicaron y por qué no las demás, y la página recibe
     * `reanalysis-adjusted` si se aplicó alguna.
     */
    public function applyChosen(Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query, ApplyReanalysisSuggestions $batch): void
    {
        $found = [];
        $changed = [];
        $gone = 0;

        try {
            $load = $this->authorizeAdjust($context, $loads);
            $block = $this->block($load, $context, $loads, $boards);
            $advice = $block === null ? null : $this->suggestions($load, $block, $query);

            foreach (array_values(array_unique(array_filter($this->chosen, fn (mixed $key): bool => is_string($key) && $key !== $this->editing))) as $key) {
                $suggestion = $this->shown($key, $advice);

                if ($suggestion === null) {
                    $gone++;
                } elseif ($suggestion->blockedReason === null && ($this->seen[$key]['signature'] ?? null) !== SuggestionBatch::signature($suggestion)) {
                    $changed[] = ['suggestion' => $suggestion, 'reason' => $this->changedReason($this->seen[$key]['title'] ?? null, $suggestion)];
                } else {
                    $found[] = $suggestion;
                }
            }

            if ($found === [] && $changed === [] && $gone === 0) {
                throw ValidationException::withMessages(['suggestions' => 'Elige al menos una sugerencia.']);
            }

            $result = $found === [] ? new SuggestionBatchResult([], [], []) : $batch->handle($load, $found, $this->placementsOf($found), $context->user());
            $result = new SuggestionBatchResult($result->applied, [...$changed, ...$result->failed], $result->opened);
        } catch (ValidationException $exception) {
            $this->error('No se aplicaron las sugerencias', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        foreach ($found as $suggestion) {
            unset($this->placements[$this->discardKey($suggestion->key)]);
        }

        $this->changedSinceChosen = [];

        foreach ($changed as ['suggestion' => $suggestion]) {
            $this->changedSinceChosen[$suggestion->key] = $this->seen[$suggestion->key]['title'] ?? $suggestion->title;
        }

        $this->chosen = [];
        $this->seen = [];
        $this->advice = null;
        $this->preselect = true;
        $this->batchToast($result, $gone, $load);

        if ($result->applied !== []) {
            $this->dispatch('reanalysis-adjusted');
        }
    }

    /**
     * Busca con el motor el horario y el salón del grupo nuevo de «Abrir un grupo» y los guarda en la pantalla: se calcula
     * aquí, una vez, nunca al pintar. Pide lo mismo que aplicarla (ajustar la carga y GroupPolicy sobre el grupo que va a
     * nacer), porque buscar cuesta casi un segundo. Al abrir otro bloque se olvidan los lugares de los demás.
     */
    public function findPlacement(string $key, Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query, NewGroupPlacementQuery $placements): void
    {
        try {
            $load = $this->authorizeAdjust($context, $loads);
            $suggestion = $this->suggestion($key, $load, $context, $loads, $boards, $query);
        } catch (ValidationException $exception) {
            $this->error('No se buscó el lugar', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        abort_unless($suggestion->kind === ReanalysisSuggestionKind::OpenGroup && $suggestion->newGroup !== null, 403);
        $draft = OpenReanalysisGroup::draft($load, $suggestion->newGroup);

        foreach (OpenReanalysisGroup::ABILITIES as $ability) {
            Gate::authorize($ability, $draft);
        }

        $placement = $placements->place($load, $suggestion->newGroup);

        if ($placement === null) {
            $this->error('No se buscó el lugar', 'El motor de horarios no está disponible: abre el grupo desde Horarios.');

            return;
        }

        if ($this->editing === $key) {
            $this->cancelEdit();
        }

        $this->placements[$this->discardKey($key)] = ['signature' => $this->signature($suggestion), 'placement' => $placement->toArray()];
        $this->preselect = true;
    }

    /**
     * Abre la edición de los lugares que suma una sugerencia o, en «Abrir un grupo», del salón del grupo nuevo: los libres
     * a todas sus horas (si todas sus sesiones van en un salón).
     */
    public function edit(string $key, Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query, NewGroupPlacementQuery $placements): void
    {
        try {
            $load = $this->authorizeAdjust($context, $loads);
            $suggestion = $this->suggestion($key, $load, $context, $loads, $boards, $query);
        } catch (ValidationException $exception) {
            $this->error('No se puede editar', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        if ($suggestion->kind === ReanalysisSuggestionKind::OpenGroup) {
            $placement = $this->placementOf($suggestion);

            if ($placement === null) {
                $this->error('No se puede editar', 'Las sugerencias cambiaron desde que buscaste el lugar: vuelve a buscar horario y salón.');

                return;
            }

            abort_unless($placement->isEditable(), 403);
            $rooms = $placements->freeRooms($load, $suggestion->newGroup, $placement);

            if ($rooms === []) {
                $this->info('Sin otro salón', 'No hay otro salón libre a todas sus horas que le sirva al grupo nuevo.');

                return;
            }

            $this->resetValidation();
            $this->editing = $key;
            $this->editRoomId = $rooms[0]->id;
            $this->roomOptions = [];

            foreach ($rooms as $room) {
                $this->roomOptions[$room->id] = ['code' => $room->code, 'capacity' => $room->capacity];
            }

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
        $this->editRoomId = null;
        $this->roomOptions = [];
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

        unset($this->placements[$this->discardKey($key)]);
        $this->discarded = array_values(array_unique([...$this->discarded, $this->discardKey($key)]));
        $this->unchecked = array_values(array_unique([...$this->unchecked, $key]));
    }

    /**
     * Vuelve a mostrar las sugerencias descartadas de la materia en el campus.
     */
    public function restoreDiscarded(Actor $context, ReanalysisLoadsQuery $loads): void
    {
        try {
            $this->authorizeAdjust($context, $loads);
        } catch (ValidationException $exception) {
            $this->error('No se puede', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        $this->discarded = array_values(array_filter($this->discarded, fn (string $discarded): bool => ! str_starts_with($discarded, $this->discardKey(''))));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query): array
    {
        $empty = ['block' => null, 'load' => null, 'suggestions' => [], 'advice' => null, 'adjustReason' => null, 'blocked' => [], 'discardedCount' => 0, 'placements' => [], 'choice' => null];

        if (! $this->show || $this->subjectId === null) {
            return $empty;
        }

        $load = $this->authorizeLoad($context, $loads);
        $block = $this->block($load, $context, $loads, $boards);

        if ($block === null) {
            return [...$empty, 'load' => $load];
        }

        $advice = $this->suggestions($load, $block, $query);
        $visible = array_values(array_filter($advice->suggestions, fn (ReanalysisSuggestion $suggestion): bool => ! in_array($this->discardKey($suggestion->key), $this->discarded, true)));
        $adjustReason = $this->adjustReason($load);
        $blocked = $this->blockedReasons($visible, $advice, $load);
        $choice = $adjustReason === null ? $this->choice($visible, $advice, $blocked) : null;
        $this->chosen = $choice['chosen'] ?? [];
        $this->seen = array_intersect_key($this->seen, array_flip($this->chosen));

        return [
            'block' => $block,
            'load' => $load,
            'suggestions' => $visible,
            'advice' => $advice,
            'adjustReason' => $adjustReason,
            'blocked' => $blocked,
            'choice' => $choice,
            'discardedCount' => count($advice->suggestions) - count($visible),
            'placements' => array_filter(array_combine(
                array_map(fn (ReanalysisSuggestion $suggestion): string => $suggestion->key, $visible),
                array_map($this->placementOf(...), $visible),
            )),
        ];
    }

    /**
     * Lo que se elige para aplicar junto, para quien ajusta: por qué no se puede elegir cada una que no (`reasons`); cuántas
     * se pueden (`choosable`); las elegidas que siguen valiendo, en el orden en que se eligieron (`chosen`: al abrir el
     * bloque, todas las que se pueden; sale la que ya no se puede elegir, la que choca con una anterior y la que cambió
     * desde que se eligió, que va a `changed` con el título que tenía); por qué choca con una elegida cada una de las demás
     * (`clashes`); y la confirmación del lote (`confirm`: tono `error` si cierra un grupo).
     *
     * @param  list<ReanalysisSuggestion>  $visible
     * @param  array<string, string>  $blocked
     * @return array{reasons: array<string, string>, choosable: int, chosen: list<string>, changed: array<string, string>, clashes: array<string, string>, confirm: array{title: string, message: string, button: string, tone: string}|null}
     */
    private function choice(array $visible, ReanalysisSuggestions $advice, array $blocked): array
    {
        $reasons = [];
        $choosable = [];

        foreach ($visible as $suggestion) {
            $reason = $this->unchoosableReason($suggestion, $blocked);

            if ($reason === null) {
                $choosable[$suggestion->key] = $suggestion;
            } else {
                $reasons[$suggestion->key] = $reason;
            }
        }

        $placements = $this->placementsOf(array_values($choosable));
        $chosenKeys = array_values(array_filter($this->chosen, is_string(...)));
        $candidates = $this->preselect
            ? [...$chosenKeys, ...array_diff(array_keys($choosable), $chosenKeys, $this->unchecked, array_keys($this->changedSinceChosen))]
            : $chosenKeys;
        $this->preselect = false;
        $chosen = [];

        foreach ($candidates as $key) {
            $suggestion = $choosable[$key] ?? null;

            if ($suggestion === null) {
                continue;
            }

            if (isset($this->seen[$key]) && $this->seen[$key]['signature'] !== SuggestionBatch::signature($suggestion)) {
                $this->changedSinceChosen[$key] = $this->seen[$key]['title'];
                unset($this->seen[$key]);

                continue;
            }

            if (array_all($chosen, fn (ReanalysisSuggestion $other): bool => SuggestionBatch::clash($suggestion, $other, $placements) === null)) {
                $chosen[$key] = $suggestion;
                $this->seen[$key] ??= ['signature' => SuggestionBatch::signature($suggestion), 'title' => $suggestion->title];
                unset($this->changedSinceChosen[$key]);
            }
        }

        $clashes = [];

        foreach (array_diff_key($choosable, $chosen) as $key => $suggestion) {
            $other = array_find($chosen, fn (ReanalysisSuggestion $other): bool => SuggestionBatch::clash($suggestion, $other, $placements) !== null);

            if ($other !== null) {
                $clashes[$key] = "Choca con «{$other->title}», que ya elegiste: ".SuggestionBatch::clash($suggestion, $other, $placements).'. Aplícala después, con las sugerencias de ahora.';
            }
        }

        $changed = [];

        foreach (array_intersect_key($this->changedSinceChosen, $choosable) as $key => $title) {
            $changed[$key] = $this->changedReason($title, $choosable[$key]);
        }

        $count = count($chosen);

        return [
            'reasons' => $reasons,
            'choosable' => count($choosable),
            'chosen' => array_keys($chosen),
            'changed' => $changed,
            'clashes' => $clashes,
            'confirm' => $count === 0 ? null : [
                'title' => $count === 1 ? '¿Aplicar la sugerencia elegida?' : "¿Aplicar las {$count} sugerencias elegidas?",
                'message' => SuggestionBatch::summary(array_values($chosen), $advice->groups),
                'button' => $count === 1 ? 'Aplicar' : "Aplicar las {$count}",
                'tone' => array_any($chosen, fn (ReanalysisSuggestion $suggestion): bool => $suggestion->kind === ReanalysisSuggestionKind::CloseGroup) ? 'error' : 'primary',
            ],
        ];
    }

    /**
     * «Cambió desde que la elegiste», con el título que tenía si ya no es el mismo.
     */
    private function changedReason(?string $title, ReanalysisSuggestion $suggestion): string
    {
        return 'Cambió desde que la elegiste'.($title !== null && $title !== $suggestion->title ? " (era «{$title}»)" : '').'; revísala y vuelve a elegirla.';
    }

    /**
     * Por qué quien ajusta no puede elegir esa sugerencia para el lote, o null si sí.
     *
     * @param  array<string, string>  $blocked
     */
    private function unchoosableReason(ReanalysisSuggestion $suggestion, array $blocked): ?string
    {
        $opens = $suggestion->kind === ReanalysisSuggestionKind::OpenGroup;
        $placement = $opens ? $this->placementOf($suggestion) : null;

        return match (true) {
            ! $suggestion->isApplicable() => 'No se aplica desde aquí: ábrelo en Horarios.',
            isset($blocked[$suggestion->key]) => $blocked[$suggestion->key],
            $this->editing === $suggestion->key => 'La estás editando: aplícala con su «Aplicar».',
            $opens && $placement === null => 'Busca primero su horario y salón para elegirla.',
            $opens && ! $placement->isPlaced() => 'El motor no le encontró lugar: no hay qué aplicar.',
            default => null,
        };
    }

    /**
     * El lugar que encontró el motor para cada «Abrir un grupo» elegido, por llave, si se buscó con la sugerencia como está
     * ahora y lo encontró.
     *
     * @param  list<ReanalysisSuggestion>  $suggestions
     * @return array<string, NewGroupPlacement>
     */
    private function placementsOf(array $suggestions): array
    {
        $placements = [];

        foreach ($suggestions as $suggestion) {
            $placement = $suggestion->kind === ReanalysisSuggestionKind::OpenGroup ? $this->placementOf($suggestion) : null;

            if ($placement?->isPlaced()) {
                $placements[$suggestion->key] = $placement;
            }
        }

        return $placements;
    }

    /**
     * El toast del lote: todas aplicadas (en qué versión entran y, si se abrió un grupo, que falta su docente), unas sí y
     * otras no (`warning`, cuál no y por qué) o ninguna (`error`).
     */
    private function batchToast(SuggestionBatchResult $result, int $gone, AcademicLoad $load): void
    {
        $applied = count($result->applied);
        $missed = count($result->failed) + $gone;
        $version = $load->current_version + 1;
        $reasons = array_map(fn (array $failure): string => "«{$failure['suggestion']->title}»: {$failure['reason']}", $result->failed);

        if ($gone > 0) {
            array_unshift($reasons, ($gone === 1 ? 'Una ya no se sugiere' : "{$gone} ya no se sugieren").': la demanda o los grupos cambiaron.');
        }

        $teachers = [];

        foreach ($result->applied as $suggestion) {
            $group = $result->opened[$suggestion->key] ?? null;

            if ($group !== null && $suggestion->newGroup !== null) {
                $teachers[] = "El grupo {$group->code} de {$suggestion->newGroup->subjectCode} para {$suggestion->newGroup->careerCode} no tiene docente: asígnalo en Asignación docente.";
            }
        }

        if ($missed === 0) {
            $this->success(
                $applied === 1 ? 'Se aplicó la sugerencia' : "Se aplicaron las {$applied} sugerencias",
                e(implode(' ', [($applied === 1 ? 'Entra' : 'Entran')." en la versión {$version} al publicar.", ...$teachers])),
            );
        } elseif ($applied > 0) {
            $this->warning(
                ($applied === 1 ? 'Se aplicó 1' : "Se aplicaron {$applied}")."; {$missed} no",
                e(implode(' ', [...$reasons, "Lo aplicado entra en la versión {$version} al publicar.", ...$teachers])),
                timeout: 8000,
            );
        } else {
            $this->error('No se aplicó ninguna', e(implode(' ', $reasons)), timeout: 8000);
        }
    }

    /**
     * Las sugerencias de la materia en el campus (tarea 8.3a), con los grupos de ahora: siempre del servidor, una vez por
     * petición (editar o descartar no las calcula dos veces; aplicar las vuelve a calcular para pintar lo nuevo).
     */
    private function suggestions(AcademicLoad $load, ReanalysisBlock $block, ReanalysisSuggestionsQuery $query): ReanalysisSuggestions
    {
        return $this->advice ??= $query->for($load, $block);
    }

    /**
     * La sugerencia de esa llave, calculada otra vez en el servidor: nunca la que trae el navegador.
     *
     * @throws ValidationException
     */
    private function suggestion(string $key, AcademicLoad $load, Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards, ReanalysisSuggestionsQuery $query): ReanalysisSuggestion
    {
        $block = $this->block($load, $context, $loads, $boards);
        $suggestion = $this->shown($key, $block === null ? null : $this->suggestions($load, $block, $query));

        if ($suggestion === null) {
            throw ValidationException::withMessages(['suggestion' => 'Esa sugerencia ya no aplica: la demanda o los grupos cambiaron. Revisa las de ahora.']);
        }

        return $suggestion;
    }

    /**
     * La sugerencia de esa llave entre las de ahora, si no se descartó en la pantalla.
     */
    private function shown(string $key, ?ReanalysisSuggestions $advice): ?ReanalysisSuggestion
    {
        return in_array($this->discardKey($key), $this->discarded, true) ? null : $advice?->find($key);
    }

    /**
     * La llave de una sugerencia descartada, con la materia y el campus del bloque: «Hace falta abrir un grupo» tiene la
     * misma llave en todos.
     */
    private function discardKey(string $key): string
    {
        return "{$this->subjectId}|{$this->campusId}|{$key}";
    }

    /**
     * El lugar que encontró el motor para «Abrir un grupo», si se buscó con la sugerencia como está ahora.
     */
    private function placementOf(ReanalysisSuggestion $suggestion): ?NewGroupPlacement
    {
        $stored = $suggestion->newGroup === null ? null : ($this->placements[$this->discardKey($suggestion->key)] ?? null);

        return $stored !== null && $stored['signature'] === $this->signature($suggestion) ? NewGroupPlacement::fromArray($stored['placement']) : null;
    }

    /**
     * Lo que hace única a una sugerencia de abrir un grupo: el grupo nuevo y los grupos del bloque como se leyeron.
     */
    private function signature(ReanalysisSuggestion $suggestion): string
    {
        return md5(serialize([$suggestion->newGroup, $suggestion->expected]));
    }

    /**
     * Crea el grupo nuevo de «Abrir un grupo» con el lugar del motor y, si se editó, el salón elegido de la lista.
     *
     * @throws ValidationException
     */
    private function openGroup(AcademicLoad $load, ReanalysisSuggestion $suggestion, Actor $context, OpenReanalysisGroup $open): void
    {
        $placement = $this->placementOf($suggestion)
            ?? throw ValidationException::withMessages(['suggestion' => 'Busca primero el horario y el salón del grupo nuevo.']);

        if ($this->editing === $suggestion->key) {
            $this->validate(
                ['editRoomId' => ['required', 'string', Rule::in(array_keys($this->roomOptions))]],
                ['editRoomId.required' => 'Elige un salón.', 'editRoomId.in' => 'Elige un salón de la lista.'],
            );
            $placement = $placement->withRoom((string) $this->editRoomId, $this->roomOptions[$this->editRoomId]);
        }

        $group = $open->handle($load, $suggestion, $placement, $context->user());

        unset($this->placements[$this->discardKey($suggestion->key)]);
        $this->advice = null;
        $this->cancelEdit();
        $this->preselect = true;
        $this->success('Grupo abierto', e("El grupo {$group->code} de {$suggestion->newGroup->subjectCode} para {$suggestion->newGroup->careerCode}: {$placement->scheduleLabel()}. Asígnale docente en Asignación docente. Entra en la versión ".($load->current_version + 1).' al publicar.'));
        $this->dispatch('reanalysis-adjusted');
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
     * Por qué quien ajusta no puede aplicar una sugerencia en particular, por llave: lo que dice el asesor (cerrar un grupo
     * con docente) o GroupPolicy sobre cada grupo que toca; las que sí puede no aparecen.
     *
     * @param  list<ReanalysisSuggestion>  $suggestions
     * @return array<string, string>
     */
    private function blockedReasons(array $suggestions, ReanalysisSuggestions $advice, AcademicLoad $load): array
    {
        $blocked = [];

        foreach ($suggestions as $suggestion) {
            if ($suggestion->blockedReason !== null) {
                $blocked[$suggestion->key] = $suggestion->blockedReason;

                continue;
            }

            if ($suggestion->newGroup !== null) {
                $draft = OpenReanalysisGroup::draft($load, $suggestion->newGroup);

                if (! array_all(OpenReanalysisGroup::ABILITIES, fn (string $ability): bool => Gate::allows($ability, $draft))) {
                    $blocked[$suggestion->key] = "No puedes abrir grupos de {$suggestion->newGroup->subjectCode} en {$suggestion->newGroup->campusCode}: lo hace la jefatura de su departamento.";
                }

                continue;
            }

            foreach (ApplyReanalysisSuggestion::abilities($suggestion) as $groupId => $needed) {
                $group = $advice->models[$groupId] ?? null;

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
