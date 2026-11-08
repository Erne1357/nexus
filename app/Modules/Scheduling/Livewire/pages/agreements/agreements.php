<?php

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Actions\Agreements\AcceptRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\CancelRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectSharedGroup;
use App\Modules\Scheduling\Actions\Quotas\AcceptSharedGroup;
use App\Modules\Scheduling\Actions\Quotas\WithdrawGroupQuotaProposal;
use App\Modules\Scheduling\Queries\AgreementsInboxQuery;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Acuerdos entre departamentos (tareas 3.6 y 3.7, reglas-de-negocio.md, "Acuerdos entre departamentos"; maqueta B
 * «Bandeja de dos paneles», decidida por el usuario el 7 de noviembre de 2026): los préstamos de salón y los grupos
 * compartidos del periodo y campus activos de la credencial. A la izquierda la lista agrupada («Por decidir»,
 * «Enviados», «Decididos») con búsqueda y filtro de tipo; a la derecha el detalle, con la semana del salón en un
 * préstamo, y las acciones al pie.
 *
 * `?acuerdo=<uuid>` abre uno (con el que llega el aviso) y `?tipo=` y `?buscar=` filtran. Ve la pantalla quien ve
 * acuerdos (InterdepartmentalAgreementPolicy::viewAny) y solo los de su alcance; decide el jefe del departamento al que
 * va dirigido (`decide`) y cancela el que lo pidió (`cancel`). Cada método vuelve a autorizar: ver, que el acuerdo sea
 * del alcance (404 si no) y la ability. Todo lo que se pinta sale de AgreementsInboxQuery; las escrituras, de
 * AcceptRoomLoan, RejectRoomLoan, CancelRoomLoan, AcceptSharedGroup, RejectSharedGroup y WithdrawGroupQuotaProposal.
 *
 * Al aceptar o rechazar se abre el siguiente por decidir de la lista (o el primero, si era el último); si ya no queda
 * ninguno, el panel se queda en el decidido. El jefe acepta varios a la vez con `acceptMany` (confirmado el 8 de
 * noviembre de 2026): cada uno pasa por su Action y su transacción, con su bitácora y su aviso, y uno que falla no
 * deshace los demás. Rechazar es uno por uno porque pide motivo. La selección vive en el navegador (`wire:model` sin
 * `.live`) y viaja con `acceptMany`. La bandeja se lee una vez por petición.
 */
new #[Title('Acuerdos')] class extends Component
{
    use Toast;

    /** Cuántos acuerdos se aceptan juntos a lo más (confirmado el 8 de noviembre de 2026). */
    private const int MAX_BULK = 50;

    /** Id del acuerdo abierto; '' abre el primero por decidir (o enviado). */
    #[Url(as: 'acuerdo')]
    public string $agreementId = '';

    /** 'room_loan', 'shared_group' o '' (todos). */
    #[Url(as: 'tipo')]
    public string $kind = '';

    #[Url(as: 'buscar')]
    public string $search = '';

    /** El periodo y el campus con los que entró. El navegador no los puede cambiar. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $campusId = null;

    #[Locked]
    public ?string $message = null;

    /** En pantallas angostas, la lista y el detalle van por separado. */
    #[Locked]
    public bool $mobileDetail = false;

    /** Si el panel pide el motivo del rechazo. */
    public bool $rejecting = false;

    public string $comment = '';

    /** @var list<string> Los acuerdos por decidir que el jefe eligió para aceptar juntos. */
    public array $selected = [];

    /** @var array{accepted: int, failed: list<array{label: string, message: string}>}|null Lo que pasó al aceptar varios. */
    #[Locked]
    public ?array $bulkResult = null;

    /** Lo resuelto en esta petición (periodo, campus y acuerdos por id); se suelta al escribir. */
    protected ?Term $loadedTerm = null;

    protected ?Campus $loadedCampus = null;

    /** @var array<string, InterdepartmentalAgreement|null> */
    protected array $found = [];

    /** @var array{decide: list<array<string, mixed>>, sent: list<array<string, mixed>>, done: list<array<string, mixed>>}|null La bandeja de esta petición. */
    protected ?array $inbox = null;

    public function mount(Actor $context, AgreementsInboxQuery $query): void
    {
        abort_unless(Gate::allows('viewAny', InterdepartmentalAgreement::class), 403);

        $this->resolve($context);
        $this->updatedKind();

        if ($this->agreementId !== '' && $this->termId !== null) {
            $this->mobileDetail = true;
            $this->find($this->agreementId, $context, $query);
        }
    }

    public function updatedKind(): void
    {
        $this->kind = AgreementKind::tryFrom($this->kind)?->value ?? '';
        $this->selected = [];
        $this->bulkResult = null;
        $this->inbox = null;
    }

    public function updatedSearch(): void
    {
        $this->selected = [];
        $this->bulkResult = null;
        $this->inbox = null;
    }

    /**
     * Abre un acuerdo de la lista; en pantallas angostas, su detalle.
     */
    public function pick(string $id, Actor $context, AgreementsInboxQuery $query): void
    {
        $this->authorizeView();

        $agreement = $this->find($id, $context, $query);

        $this->agreementId = $agreement->getKey();
        $this->mobileDetail = true;
        $this->rejecting = false;
        $this->comment = '';
        $this->resetErrorBag();
    }

    public function back(): void
    {
        $this->mobileDetail = false;
        $this->rejecting = false;
    }

    public function accept(string $id, Actor $context, AgreementsInboxQuery $query, AcceptRoomLoan $loan, AcceptSharedGroup $shared): void
    {
        $this->authorizeView();
        $agreement = $this->find($id, $context, $query);
        $this->authorize('decide', $agreement);

        $row = $query->row($agreement);

        try {
            $agreement->kind === AgreementKind::RoomLoan ? $loan->handle($agreement, auth()->user()) : $shared->handle($agreement, auth()->user());
        } catch (ValidationException $e) {
            $this->report($e, 'No se aceptó');

            return;
        }

        $this->afterDecision([$id]);
        $this->openNextPending($row, $context, $query);
        $this->success('Aceptaste '.$this->summary($row), e($row['from']['name']));
    }

    /**
     * Abre el campo del motivo en el panel.
     */
    public function confirmReject(string $id, Actor $context, AgreementsInboxQuery $query): void
    {
        $this->authorizeView();
        $this->authorize('decide', $this->find($id, $context, $query));

        $this->agreementId = $id;
        $this->comment = '';
        $this->resetErrorBag();
        $this->rejecting = true;
    }

    public function cancelReject(): void
    {
        $this->rejecting = false;
        $this->comment = '';
        $this->resetErrorBag();
    }

    public function reject(string $id, Actor $context, AgreementsInboxQuery $query, RejectRoomLoan $loan, RejectSharedGroup $shared): void
    {
        $this->authorizeView();
        $agreement = $this->find($id, $context, $query);
        $this->authorize('decide', $agreement);

        $this->validate(
            ['comment' => ['required', 'string', 'max:'.AgreementRules::MAX_COMMENT_LENGTH]],
            ['comment.required' => 'Escribe por qué rechazas el acuerdo.', 'comment.max' => 'El motivo lleva hasta '.AgreementRules::MAX_COMMENT_LENGTH.' caracteres.'],
        );

        $row = $query->row($agreement);

        try {
            $cleared = $agreement->kind === AgreementKind::RoomLoan
                ? $loan->handle($agreement, $this->comment, auth()->user())
                : $shared->handle($agreement, $this->comment, auth()->user());
        } catch (ValidationException $e) {
            $this->report($e, 'No se rechazó');

            return;
        }

        $this->afterDecision([$id]);
        $this->openNextPending($row, $context, $query);
        $this->warning('Rechazaste '.$this->summary($row), is_int($cleared) && $cleared > 0 ? 'La sesión se quedó sin salón.' : e($row['from']['name']));
    }

    public function cancel(string $id, Actor $context, AgreementsInboxQuery $query, WithdrawGroupQuotaProposal $withdraw, CancelRoomLoan $cancelLoan): void
    {
        $this->authorizeView();
        $agreement = $this->find($id, $context, $query);
        $this->authorize('cancel', $agreement);

        if ($agreement->status !== AgreementStatus::Proposed) {
            $this->error('No se canceló', 'Este acuerdo ya se decidió.');

            return;
        }

        $row = $query->row($agreement);

        try {
            $cleared = $agreement->kind === AgreementKind::RoomLoan
                ? $cancelLoan->handle($agreement, auth()->user())
                : $withdraw->handle($agreement, auth()->user());
        } catch (ValidationException $e) {
            $this->report($e, 'No se canceló');

            return;
        }

        $this->afterDecision([$id]);
        $this->mobileDetail = false;
        $this->info('Cancelaste '.$this->summary($row), is_int($cleared) && $cleared > 0 ? 'La sesión se quedó sin salón.' : e($row['to']['name']));
    }

    /**
     * Acepta los acuerdos elegidos (hasta MAX_BULK), uno por uno, cada uno con su Action en su propia transacción:
     * lleva su bitácora y su aviso como si se aceptara solo, y uno que falla no deshace los demás. Uno que el actor ya
     * no ve o ya no decide (otro departamento, otro campus, un id que no es de un acuerdo) no se acepta ni se nombra:
     * va a los que no se aceptaron como «Ya no está disponible.». Los demás se aceptan en el orden de la lista (del
     * pedido más antiguo al más reciente, y por id), no en el que se eligieron, para que dos que compiten por los mismos
     * lugares (HC-07) se resuelvan siempre igual. Lo que rechaza la regla se dice con su mensaje; un error de la base o
     * cualquier otro se reporta y se dice sin detalle. Los que fallan y siguen por decidir se quedan elegidos.
     */
    public function acceptMany(Actor $context, AgreementsInboxQuery $query, AcceptRoomLoan $loan, AcceptSharedGroup $shared): void
    {
        $this->authorizeView();
        abort_if($this->termId === null, 404);
        $this->validate(
            ['selected' => ['required', 'array', 'max:'.self::MAX_BULK], 'selected.*' => ['string']],
            ['selected.required' => 'Elige los acuerdos que vas a aceptar.', 'selected.max' => 'Acepta hasta '.self::MAX_BULK.' a la vez.'],
        );

        $open = $this->agreementId === '' ? null : $this->findOrNull($this->agreementId, $context, $query);
        $openRow = $open === null ? null : $query->row($open);
        $accepted = [];
        $failed = [];
        $retry = [];
        $candidates = [];

        foreach (array_values(array_unique($this->selected)) as $id) {
            $agreement = $this->findOrNull($id, $context, $query);

            if ($agreement === null || Gate::denies('decide', $agreement)) {
                $failed[] = ['label' => 'Un acuerdo', 'message' => 'Ya no está disponible.'];

                continue;
            }

            $candidates[] = $agreement;
        }

        $inOrder = collect($candidates)->sortBy([
            fn (InterdepartmentalAgreement $a, InterdepartmentalAgreement $b): int => $a->created_at?->format('Y-m-d H:i:s.u') <=> $b->created_at?->format('Y-m-d H:i:s.u'),
            fn (InterdepartmentalAgreement $a, InterdepartmentalAgreement $b): int => strcmp($a->getKey(), $b->getKey()),
        ]);

        foreach ($inOrder as $agreement) {
            try {
                $agreement->kind === AgreementKind::RoomLoan ? $loan->handle($agreement, auth()->user()) : $shared->handle($agreement, auth()->user());
                $accepted[] = $agreement->getKey();

                continue;
            } catch (ValidationException $e) {
                $message = (string) collect($e->errors())->flatten()->first();
            } catch (QueryException|DeadlockException $e) {
                report($e);
                $message = 'No se pudo aceptar por un error de la base de datos; intenta de nuevo.';
            } catch (Throwable $e) {
                report($e);
                $message = 'No se pudo aceptar por un error inesperado; intenta de nuevo.';
            }

            try {
                $fresh = $agreement->fresh() ?? $agreement;
                $failed[] = ['label' => $this->label($query->row($fresh)), 'message' => $message];

                if ($fresh->status === AgreementStatus::Proposed) {
                    $retry[] = $agreement->getKey();
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['label' => 'Un acuerdo', 'message' => $message];
            }
        }

        $this->afterDecision($accepted);
        $this->selected = $retry;
        $this->bulkResult = ['accepted' => count($accepted), 'failed' => $failed];

        if ($openRow !== null && in_array($openRow['id'], $accepted, true)) {
            $this->openNextPending($openRow, $context, $query);
        }

        $failed === []
            ? $this->success('Aceptados '.count($accepted))
            : $this->warning('Aceptados '.count($accepted), count($failed) === 1 ? 'Uno no se aceptó: el motivo está arriba de la lista.' : count($failed).' no se aceptaron: los motivos están arriba de la lista.');
    }

    public function dismissBulkResult(): void
    {
        $this->bulkResult = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, AgreementsInboxQuery $query): array
    {
        if ($this->termId === null) {
            return ['groups' => null];
        }

        $groups = $this->inbox($context, $query);
        $blocked = $query->blocked($groups['decide']);
        $selected = $this->selectedRow($context, $query, $groups);
        $agreement = $selected === null ? null : $this->findOrNull($selected['id'], $context, $query);
        $detail = $agreement === null ? null : $query->detail($agreement);

        return [
            'groups' => $groups,
            'term' => $this->term(),
            'campus' => $this->campus(),
            'labels' => ['decide' => 'Por decidir', 'sent' => 'Enviados', 'done' => 'Decididos'],
            'selectedId' => $detail['id'] ?? null,
            'detail' => $detail,
            'canDecide' => $agreement !== null && Gate::allows('decide', $agreement),
            'canCancel' => $agreement !== null && Gate::allows('cancel', $agreement),
            'acceptBlock' => $detail === null ? null : $this->acceptBlock($detail),
            'maxComment' => AgreementRules::MAX_COMMENT_LENGTH,
            'filtered' => $this->kind !== '' || trim($this->search) !== '',
            'blocked' => $blocked,
            'selectable' => $this->selectable($groups['decide'], $blocked),
            'maxBulk' => self::MAX_BULK,
        ];
    }

    /**
     * Por qué no se puede aceptar un préstamo propuesto: el bloque pedido empalma con lo que el salón ya tiene (HC-02).
     *
     * @param  array<string, mixed>  $detail
     */
    private function acceptBlock(array $detail): ?string
    {
        $clash = $detail['clash'] ?? null;

        if ($detail['status'] !== AgreementStatus::Proposed || $clash === null) {
            return null;
        }

        $day = Minutes::dayName($clash['day']);

        return "{$detail['room']} ya está ocupado el {$day} de {$clash['starts_at']} a {$clash['ends_at']} por {$clash['label']} ({$clash['rule']}).";
    }

    /**
     * El renglón que se abre: el elegido, o el primero por decidir o enviado.
     *
     * @param  array{decide: list<array<string, mixed>>, sent: list<array<string, mixed>>, done: list<array<string, mixed>>}  $groups
     * @return array<string, mixed>|null
     */
    private function selectedRow(Actor $context, AgreementsInboxQuery $query, array $groups): ?array
    {
        if ($this->agreementId !== '') {
            $agreement = $this->findOrNull($this->agreementId, $context, $query);

            return $agreement === null ? null : ['id' => $agreement->getKey()];
        }

        return $groups['decide'][0] ?? $groups['sent'][0] ?? null;
    }

    /**
     * Los por decidir de una lista que se pueden aceptar: todos menos los préstamos cuyo bloque empalma.
     *
     * @param  list<array<string, mixed>>  $decide
     * @param  array<string, mixed>  $blocked  Lo que da AgreementsInboxQuery::blocked() para esa lista.
     * @return list<string>
     */
    private function selectable(array $decide, array $blocked): array
    {
        return array_values(array_diff(array_column($decide, 'id'), array_keys($blocked)));
    }

    /**
     * La bandeja con el filtro y la búsqueda de ahora, leída una vez por petición; se suelta al decidir.
     *
     * @return array{decide: list<array<string, mixed>>, sent: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    private function inbox(Actor $context, AgreementsInboxQuery $query): array
    {
        return $this->inbox ??= $query->inbox($context, $this->term(), $this->campus(), AgreementKind::tryFrom($this->kind), $this->search);
    }

    /**
     * Después de aceptar o rechazar, abre el siguiente por decidir de la lista (el que seguía al decidido; si era el
     * último, el primero) y lo anuncia al navegador para que lo muestre. Si ya no queda ninguno, se queda en el decidido
     * y, en pantallas angostas, vuelve a la lista.
     *
     * @param  array<string, mixed>  $decided  El renglón del decidido, leído antes de decidir.
     */
    private function openNextPending(array $decided, Actor $context, AgreementsInboxQuery $query): void
    {
        $pending = $this->inbox($context, $query)['decide'];
        $after = fn (array $row): bool => [$row['sortKey'], $row['id']] > [$decided['sortKey'], $decided['id']];
        $next = collect($pending)->first($after) ?? $pending[0] ?? null;

        if ($next === null) {
            $this->agreementId = $decided['id'];
            $this->mobileDetail = false;

            return;
        }

        $this->agreementId = $next['id'];
        $this->dispatch('agreement-shown');
    }

    /**
     * Cómo se nombra un acuerdo en un aviso o en la lista de los que no se aceptaron, sin escapar.
     *
     * @param  array<string, mixed>  $row
     */
    private function label(array $row): string
    {
        return $row['kind'] === AgreementKind::RoomLoan
            ? "{$row['room']} · {$row['day_short']} {$row['hours']} · ".($row['group']['label'] ?? 'Grupo')
            : ($row['group']['label'] ?? 'Grupo')." · {$row['seats']} ".($row['seats'] === 1 ? 'lugar' : 'lugares')." de {$row['career']['code']}";
    }

    /**
     * Ya escapado: MaryUI pinta los toasts como HTML.
     *
     * @param  array<string, mixed>  $detail
     */
    private function summary(array $detail): string
    {
        return e($detail['kind'] === AgreementKind::RoomLoan
            ? "{$detail['room']} · el ".mb_strtolower(Minutes::dayName((int) $detail['day']))
            : "{$detail['career']['code']} en {$detail['group']['label']}");
    }

    /**
     * @param  list<string>  $decided  Los que se acaban de decidir, que salen de la selección.
     */
    private function afterDecision(array $decided): void
    {
        $this->found = [];
        $this->inbox = null;
        $this->bulkResult = null;
        $this->rejecting = false;
        $this->comment = '';
        $this->selected = array_values(array_diff($this->selected, $decided));
        $this->resetErrorBag();
    }

    private function authorizeView(): void
    {
        abort_unless(Gate::allows('viewAny', InterdepartmentalAgreement::class), 403);
    }

    private function find(string $id, Actor $context, AgreementsInboxQuery $query): InterdepartmentalAgreement
    {
        abort_if($this->termId === null, 404);

        return $this->findOrNull($id, $context, $query) ?? abort(404);
    }

    private function findOrNull(string $id, Actor $context, AgreementsInboxQuery $query): ?InterdepartmentalAgreement
    {
        if (array_key_exists($id, $this->found)) {
            return $this->found[$id];
        }

        try {
            return $this->found[$id] = $query->findOrFail($context, $this->term(), $this->campus(), $id);
        } catch (ModelNotFoundException) {
            return $this->found[$id] = null;
        }
    }

    private function term(): Term
    {
        return $this->loadedTerm ??= KeyLookup::findOrFail(Term::query(), $this->termId);
    }

    private function campus(): Campus
    {
        return $this->loadedCampus ??= KeyLookup::findOrFail(Campus::query(), $this->campusId);
    }

    /**
     * Resuelve con la credencial el periodo y el campus, o deja por qué no hay pantalla que mostrar.
     */
    private function resolve(Actor $context): void
    {
        $campus = $context->activeCampus();

        if ($campus === null) {
            $this->message = 'Elige un campus en tu credencial para ver los acuerdos.';

            return;
        }

        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $this->termId = $term->getKey();
        $this->campusId = $campus->getKey();
        $this->message = null;
    }

    private function report(ValidationException $e, string $title): void
    {
        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }

        $this->error($title, e(collect($e->errors())->flatten()->first()));
    }
};
