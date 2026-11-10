<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Actions\JustifyGroups;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Staffing\Queries\LoadClosingQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * «Justificaciones» de una carga (HC-15; DESIGN.md, «Cierre de la carga»), aparte de la página para que abrir el drawer
 * de un grupo o justificar no vuelva a pintar todo: los grupos sin docente o sin salón con su justificación, quién y
 * cuándo (LoadClosingQuery::items), filtrados en el servidor por pestaña («Por justificar», «Justificados», «Todos»),
 * búsqueda (clave, materia; sin importar acentos) y «Sin docente · Sin salón», y de 50 en 50. Cambiar de vista vacía la
 * selección, y el lote solo toma los elegidos que siguen en la vista.
 *
 * Con acciones (en «Cierre de la carga»), quien cierra la carga mientras se edita justifica un grupo en su drawer
 * (`staffing::components.closing-justify-drawer`, que se abre en el navegador sin pasar por aquí) o varios a la vez
 * con un motivo (justificación en lote, confirmado el 10 de noviembre de 2026; JustifyGroups, hasta 200). La selección
 * vive en el navegador hasta «Justificar N» y «Seleccionar los N de esta vista» toma los de la pestaña y los filtros,
 * también los que aún no se muestran. Sin acciones (el detalle de «Avance del cierre»), solo se lee.
 *
 * Después de justificar avisa con `closing-justified` para que la página revise otra vez lo que falta. La ven quienes
 * cierran o reabren la carga.
 */
new class extends Component
{
    public const int PAGE = 50;

    #[Locked]
    public string $loadId = '';

    #[Locked]
    public bool $withActions = true;

    /** pending, done o all; '' es «Por justificar» si queda alguno y si no «Justificados». */
    public string $tab = '';

    public string $search = '';

    /** '' (todos), teacher (sin docente) o room (sin salón). */
    public string $lack = '';

    public int $shown = self::PAGE;

    /** @var list<string> */
    public array $selected = [];

    public string $bulkReason = '';

    public bool $showBulk = false;

    /**
     * Lo que pasó con la última justificación en lote.
     *
     * @var array{justified: int, skipped: list<array{code: string, reason: string}>}|null
     */
    #[Locked]
    public ?array $bulkResult = null;

    public function mount(string $loadId, bool $withActions = true): void
    {
        $this->loadId = $loadId;
        $this->withActions = $withActions;
        $this->load(app(LoadClosingQuery::class));
    }

    /**
     * Otra pestaña, búsqueda o filtro es otra vista: vuelve a la primera página y vacía la selección, para que el lote
     * no justifique grupos que ya no se ven.
     */
    public function updatedTab(): void
    {
        $this->resetView();
    }

    public function updatedSearch(): void
    {
        $this->resetView();
    }

    public function updatedLack(): void
    {
        $this->resetView();
    }

    public function showMore(): void
    {
        $this->shown += self::PAGE;
    }

    /**
     * Un grupo se justificó en el drawer: se vuelve a pintar.
     */
    #[On('closing-justified')]
    public function refreshList(): void {}

    /**
     * Abre el modal del motivo para los elegidos.
     */
    public function askBulk(LoadClosingQuery $query): void
    {
        $this->load($query);
        abort_unless($this->withActions, 403);

        $this->resetValidation();
        $this->validateSelection();
        $this->showBulk = true;
    }

    /**
     * Justifica los elegidos de esta carga con un motivo. Devuelve si se justificó (para cerrar el modal en el navegador).
     */
    public function justifySelected(LoadClosingQuery $query, JustifyGroups $action, Actor $context): bool
    {
        $load = $this->load($query);
        abort_unless($this->withActions, 403);

        $this->resetValidation();
        $this->validateSelection();
        $this->validate(['bulkReason' => ['required', 'string', 'max:500']], ['bulkReason.required' => 'Escribe la justificación.'], ['bulkReason' => 'justificación']);

        // Solo grupos de esta carga y, de ellos, los que siguen en la vista: uno que cambió mientras estaba elegido (se
        // justificó en otro lado, ya tiene docente) no se toca, y se dice. La Action vuelve a preguntar la policy por grupo.
        $own = $query->groupCodesOf($load, $this->selected);
        $visible = array_flip(array_column($this->filtered($query->items($load))['view'], 'id'));
        $outOfView = array_map(
            fn (string $code): array => ['code' => $code, 'reason' => 'Cambió mientras estaba elegido y ya no está en esta vista: no se tocó.'],
            array_values(array_diff_key($own, $visible)),
        );

        try {
            $result = $action->handle(array_map('strval', array_keys(array_intersect_key($own, $visible))), $this->bulkReason, $context->user() ?? abort(403));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key === 'groups' ? 'selected' : 'bulkReason', $messages[0]);
            }

            return false;
        }

        $this->bulkResult = ['justified' => count($result['justified']), 'skipped' => [...$result['skipped'], ...$outOfView]];
        $this->reset('selected', 'bulkReason', 'showBulk');
        $this->dispatch('closing-justified', code: null, removed: false, updated: false)->to('staffing::pages.closing');

        return true;
    }

    public function dismissBulkResult(): void
    {
        $this->bulkResult = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(LoadClosingQuery $query): array
    {
        $load = $this->load($query);
        $items = $query->items($load);
        ['tab' => $tab, 'pending' => $pending, 'inTab' => $inTab, 'view' => $view] = $this->filtered($items);

        return [
            'load' => $load,
            'activeTab' => $tab,
            'counts' => ['pending' => $pending, 'done' => count($items) - $pending, 'all' => count($items)],
            'lackCounts' => collect(['' => '', 'teacher' => 'teacher', 'room' => 'room'])->map(fn (string $lack): int => count(array_filter($inTab, fn (array $item): bool => self::lacks($item, $lack))))->all(),
            'usesRooms' => $load->campus->profile->usesRooms,
            'total' => count($items),
            'rows' => array_slice($view, 0, $this->shown),
            'viewCount' => count($view),
            'viewIds' => array_column(array_slice($view, 0, JustifyGroups::MAX), 'id'),
            'canJustify' => $this->withActions && in_array($load->status, LoadEditability::EDITABLE, true) && Gate::allows('close', $load),
            'max' => JustifyGroups::MAX,
            'prefix' => $this->withActions ? 'page' : 'drawer',
        ];
    }

    /**
     * La pestaña (sin elegir, «Por justificar» si queda alguno), los que faltan de justificar, los de la pestaña con la
     * búsqueda (`inTab`) y, de esos, los del filtro «Sin docente · Sin salón» (`view`): lo que se ve.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{tab: string, pending: int, inTab: list<array<string, mixed>>, view: list<array<string, mixed>>}
     */
    private function filtered(array $items): array
    {
        $pending = count(array_filter($items, fn (array $item): bool => $item['justification'] === null));
        $tab = in_array($this->tab, ['pending', 'done', 'all'], true) ? $this->tab : ($pending > 0 ? 'pending' : 'done');
        $needle = Str::lower(Str::ascii(trim($this->search)));
        $inTab = array_values(array_filter($items, fn (array $item): bool => match ($tab) {
            'pending' => $item['justification'] === null,
            'done' => $item['justification'] !== null,
            default => true,
        } && ($needle === '' || str_contains(Str::lower(Str::ascii($item['code'].' '.$item['subject_code'].' '.$item['subject_name'])), $needle))));

        return [
            'tab' => $tab,
            'pending' => $pending,
            'inTab' => $inTab,
            'view' => array_values(array_filter($inTab, fn (array $item): bool => self::lacks($item, $this->lack))),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function lacks(array $item, string $lack): bool
    {
        return match ($lack) {
            'teacher' => $item['lacks_teacher'],
            'room' => $item['roomless_sessions'] > 0,
            default => true,
        };
    }

    private function resetView(): void
    {
        $this->shown = self::PAGE;
        $this->reset('selected');
    }

    private function validateSelection(): void
    {
        $this->validate(
            ['selected' => ['required', 'array', 'max:'.JustifyGroups::MAX], 'selected.*' => ['string']],
            ['selected.required' => 'Elige los grupos que vas a justificar.', 'selected.max' => 'Justifica hasta '.JustifyGroups::MAX.' grupos por vez.'],
        );
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
