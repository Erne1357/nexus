<?php

namespace App\Modules\Staffing\Livewire\Concerns;

use App\Modules\AcademicLoad\Actions\Transitions\ApproveLoad;
use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReturnToStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Queries\ClosingBlockers;
use App\Modules\Staffing\Queries\LoadClosing;
use App\Modules\Staffing\Queries\LoadClosingQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Las acciones de una carga en «Cierre de la carga» y en el detalle de «Avance del cierre» (tareas 4.1 y 4.2): cerrar
 * con confirmación y comentario opcional (CloseLoad), reabrir con motivo (ReopenLoad), volver a asignación
 * (ReturnToStaffing) y, solo si el perfil de la modalidad exige el visto bueno, aprobar (ApproveLoad) o regresar con
 * comentarios (que es reabrir). Publicar es aparte: la pantalla monta academic-load::components.publish-load. Cada
 * método vuelve a autorizar con su ability (close, reopen, startStaffing, approve): la petición puede llegar de una
 * pantalla que abrió otra sesión. Lo que vetan las guardas se ve en la pantalla, sin ids de regla, y en un toast.
 *
 * Lo que le falta a la carga sale del validador al momento (LoadClosingQuery::blockers), que tarda con cargas grandes:
 * se calcula al elegir la carga y después de cada acción, y se guarda aquí (`blockers`) para no repetirlo en cada
 * petición. «Revisar de nuevo» lo recalcula.
 *
 * La página que lo usa decide quién ve la carga (authorizeLoad()) y la elige (`loadId`).
 */
trait ClosesLoads
{
    /** La carga a la vista; el navegador no la puede cambiar. */
    #[Locked]
    public ?string $loadId = null;

    /**
     * Lo que le faltaba a la carga al revisarla (las cifras de ClosingBlockers), o null si no aplica.
     *
     * @var array<string, int>|null
     */
    #[Locked]
    public ?array $blockers = null;

    /** Cuándo se revisó, en ISO 8601. */
    #[Locked]
    public ?string $checkedAt = null;

    /**
     * Por qué no pasó la última acción (el veto de las guardas o del diagrama), sin ids de regla.
     *
     * @var list<string>
     */
    #[Locked]
    public array $vetoReasons = [];

    #[Locked]
    public ?string $vetoTitle = null;

    public bool $showClose = false;

    public string $closeComment = '';

    public bool $showReopen = false;

    public string $reopenReason = '';

    public bool $showApprove = false;

    public string $approveComment = '';

    /**
     * Quién ve la carga en esta pantalla; lanza 403 si no.
     */
    abstract protected function authorizeLoad(AcademicLoad $load): void;

    public function askClose(LoadClosingQuery $query): void
    {
        $load = $this->closingLoad($query);
        Gate::authorize('close', $load);

        $this->resetValidation();
        $this->reset('closeComment');
        $this->showClose = true;
    }

    public function close(LoadClosingQuery $query, CloseLoad $action): void
    {
        $load = $this->closingLoad($query);
        Gate::authorize('close', $load);
        $this->validate(['closeComment' => ['nullable', 'string', 'max:500']], attributes: ['closeComment' => 'comentario']);
        $groups = $query->activeGroupCount($load);

        $this->showClose = false;

        if (! $this->run(fn () => $action->handle($load, $this->closingUser(), $this->closeComment), 'No se cerró', $load, $query)) {
            return;
        }

        $this->success('Carga cerrada', e("{$load->department->code} en {$load->modality->name} · {$groups} ".($groups === 1 ? 'grupo confirmado.' : 'grupos confirmados.')));
    }

    public function askReopen(LoadClosingQuery $query): void
    {
        $load = $this->closingLoad($query);
        Gate::authorize('reopen', $load);

        $this->resetValidation();
        $this->reset('reopenReason');
        $this->showReopen = true;
    }

    public function reopen(LoadClosingQuery $query, ReopenLoad $action): void
    {
        $load = $this->closingLoad($query);
        Gate::authorize('reopen', $load);
        $this->validate(
            ['reopenReason' => ['required', 'string', 'max:500']],
            ['reopenReason.required' => 'Escribe el motivo para reabrir la carga.'],
            ['reopenReason' => 'motivo'],
        );

        $this->showReopen = false;

        if (! $this->run(fn () => $action->handle($load, $this->closingUser(), $this->reopenReason), 'No se reabrió', $load, $query)) {
            return;
        }

        $this->warning('Carga reabierta', e("{$load->department->code} en {$load->modality->name} regresó a Ajustes."));
    }

    public function returnToStaffing(LoadClosingQuery $query, ReturnToStaffing $action): void
    {
        $load = $this->closingLoad($query);
        Gate::authorize('startStaffing', $load);

        if (! $this->run(fn () => $action->handle($load, $this->closingUser()), 'No volvió a asignación', $load, $query)) {
            return;
        }

        $this->success('La carga volvió a Asignación', e("{$load->department->code} en {$load->modality->name}: ya se puede cerrar otra vez."));
    }

    public function askApprove(LoadClosingQuery $query): void
    {
        $load = $this->closingLoad($query);
        Gate::authorize('approve', $load);

        $this->resetValidation();
        $this->reset('approveComment');
        $this->showApprove = true;
    }

    public function approve(LoadClosingQuery $query, ApproveLoad $action): void
    {
        $load = $this->closingLoad($query);
        Gate::authorize('approve', $load);
        $this->validate(['approveComment' => ['nullable', 'string', 'max:500']], attributes: ['approveComment' => 'comentario']);

        $this->showApprove = false;

        if (! $this->run(fn () => $action->handle($load, $this->closingUser(), $this->approveComment), 'No se aprobó', $load, $query)) {
            return;
        }

        $this->success('Visto bueno', e("{$load->department->code} en {$load->modality->name} queda lista para publicar."));
    }

    /**
     * Vuelve a revisar lo que le falta a la carga con el validador.
     */
    public function recheck(LoadClosingQuery $query): void
    {
        $this->checkBlockers($this->closingLoad($query), $query);
    }

    /**
     * La carga cambió fuera de estas acciones (se publicó): olvida el último veto y vuelve a revisar lo que le falta, que
     * de una publicada ya no se calcula.
     */
    protected function refreshLoad(LoadClosingQuery $query): void
    {
        $load = $this->closingLoad($query);
        $this->reset('vetoReasons', 'vetoTitle');
        $this->checkBlockers($load, $query);
    }

    /**
     * Lleva a la pantalla donde se arregla un faltante, con la credencial en el periodo de la carga y, si esta pantalla
     * filtra un campus (`?campus=`), la otra en ese campus; si no, la otra elige el suyo (esas pantallas todavía trabajan
     * por campus; la carga es por modalidad, M1). `$screen`: conflicts, editor, assign, change-requests o agreements.
     */
    public function goTo(string $screen, LoadClosingQuery $query, SetActiveContext $context): void
    {
        $load = $this->closingLoad($query);
        $route = match ($screen) {
            'conflicts' => ['scheduling.conflicts', ['vista' => 'departamentos']],
            'editor' => ['scheduling.editor', []],
            'assign' => ['staffing.assign', ['depto' => $load->department->code]],
            'change-requests' => ['staffing.change-requests', ['depto' => $load->department->code]],
            'agreements' => ['scheduling.agreements', []],
            default => abort(404),
        };

        $context->handle($load->term);
        $campus = property_exists($this, 'campus') ? (string) $this->campus : '';

        if ($campus !== '' && app(Actor::class)->campuses()->contains('code', $campus)) {
            $route[1]['campus'] = $campus;
        }

        $this->redirectRoute($route[0], $route[1], navigate: true);
    }

    /**
     * Las acciones de la carga en su estado, en el orden en que se muestran: la que no aplica en ese estado no está; la
     * que el rol o lo que falta no permite va con su motivo (`reason`), que la pantalla escribe junto a los botones salvo
     * si ya lo dice la línea del estado (`explained`, «Faltan N para cerrar»). Publicar no está aquí: es la publicación
     * con su línea de versiones (academic-load::components.publish-load), que la pantalla monta aparte y dice su motivo.
     *
     * @return list<array{key: string, label: string, icon: string, primary: bool, reason: ?string, explained: bool}>
     */
    protected function loadActions(LoadClosing $closing): array
    {
        $load = $closing->load;
        $status = $load->status;
        $approval = $load->modality->profile->requiresSubdireccionApproval;
        $actions = [];

        if (in_array($status, [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published], true)) {
            $returning = $status === LoadStatus::Closed && $approval;
            $actions[] = [
                'key' => 'reopen',
                'label' => $returning ? 'Regresar con comentarios' : 'Reabrir carga',
                'icon' => $returning ? 'o-arrow-uturn-left' : 'o-lock-open',
                'primary' => false,
                'reason' => Gate::allows('reopen', $load) ? null : ($returning ? 'Solo Subdirección regresa la carga con comentarios.' : 'Solo Subdirección reabre la carga.'),
                'explained' => false,
            ];
        }

        if ($status === LoadStatus::Adjusting) {
            $conflicted = $closing->blockers->conflicted ?? 0;
            $actions[] = [
                'key' => 'return',
                'label' => 'Volver a asignación',
                'icon' => 'o-arrow-uturn-left',
                'primary' => true,
                'reason' => match (true) {
                    ! Gate::allows('startStaffing', $load) => 'Vuelve a asignación la jefatura del departamento.',
                    $conflicted > 0 => $conflicted === 1 ? 'Un grupo tiene choques duros: corrígelo antes de volver a asignación.' : "{$conflicted} grupos tienen choques duros: corrígelos antes de volver a asignación.",
                    $closing->approvedRequests > 0 => $closing->approvedRequests === 1 ? 'Una solicitud de cambio aprobada sigue sin aplicar.' : "{$closing->approvedRequests} solicitudes de cambio aprobadas siguen sin aplicar.",
                    default => null,
                },
                'explained' => false,
            ];
        }

        if (in_array($status, [LoadStatus::Proposed, LoadStatus::Staffing], true)) {
            $missing = $closing->missing();
            $actions[] = [
                'key' => 'close',
                'label' => 'Cerrar carga',
                'icon' => 'o-lock-closed',
                'primary' => true,
                'reason' => match (true) {
                    ! Gate::allows('close', $load) => 'Cierran la jefatura del departamento y Subdirección.',
                    $status === LoadStatus::Proposed => 'La carga sigue en Propuesta: primero pásala a asignación docente.',
                    $missing > 0 => $closing->missingLabel().' para cerrar.',
                    default => null,
                },
                'explained' => Gate::allows('close', $load) && $status === LoadStatus::Staffing && $missing > 0,
            ];
        }

        if ($status === LoadStatus::Closed && $approval) {
            $actions[] = [
                'key' => 'approve',
                'label' => 'Dar visto bueno',
                'icon' => 'o-check-badge',
                'primary' => true,
                'reason' => Gate::allows('approve', $load) ? null : 'Da el visto bueno Subdirección.',
                'explained' => false,
            ];
        }

        return $actions;
    }

    /**
     * Elige la carga y revisa lo que le falta.
     */
    protected function showLoad(?AcademicLoad $load, LoadClosingQuery $query): void
    {
        $this->reset('vetoReasons', 'vetoTitle', 'showClose', 'showReopen', 'showApprove');
        $this->loadId = $load?->getKey();

        if ($load === null) {
            $this->blockers = $this->checkedAt = null;

            return;
        }

        $this->checkBlockers($load, $query);
    }

    protected function checkBlockers(AcademicLoad $load, LoadClosingQuery $query): void
    {
        $blockers = $query->blockers($load);
        $this->blockers = $blockers === null ? null : get_object_vars($blockers);
        $this->checkedAt = now()->toIso8601String();
    }

    protected function cachedBlockers(): ?ClosingBlockers
    {
        return $this->blockers === null ? null : new ClosingBlockers(...$this->blockers);
    }

    /**
     * La carga a la vista, con la policy de la pantalla.
     */
    protected function closingLoad(LoadClosingQuery $query): AcademicLoad
    {
        abort_if($this->loadId === null, 404);

        $load = $query->findOrFail($this->loadId);
        $this->authorizeLoad($load);

        return $load;
    }

    /**
     * Corre una transición; si la vetan, deja sus motivos a la vista y los dice en un toast. Después, revisa otra vez lo
     * que le falta a la carga.
     */
    private function run(callable $transition, string $title, AcademicLoad $load, LoadClosingQuery $query): bool
    {
        $this->reset('vetoReasons', 'vetoTitle');

        try {
            $transition();
        } catch (ValidationException $e) {
            $this->vetoTitle = "{$title}: la carga sigue en {$load->fresh()->status->label()}.";
            $this->vetoReasons = collect($e->errors())->flatten()->map(fn ($reason): string => self::withoutRuleIds((string) $reason))->values()->all();
            $this->error($title, e($this->vetoReasons[0] ?? ''));
            $this->checkBlockers($load->fresh(), $query);

            return false;
        }

        $this->checkBlockers($load->fresh(), $query);

        return true;
    }

    private function closingUser(): User
    {
        return app(Actor::class)->user() ?? abort(403);
    }

    /**
     * Un motivo sin el id de regla («HC-15: 3 grupos…», «… (HC-15).»): en pantalla va en palabras.
     */
    private static function withoutRuleIds(string $reason): string
    {
        return (string) preg_replace(['/^(?:HC|SC)-\d+:\s*/u', '/\s*\((?:HC|SC)-\d+\)/u'], '', $reason);
    }
}
