<?php

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomSuggestionQuery;
use App\Modules\Scheduling\Rules\SessionRules;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * La sección de salón del cajón de la sesión en el editor de horarios (tarea 3.7; maqueta `cajones`, estado
 * `#sesion`): el salón actual con su estado («Asignado», «Préstamo pendiente · HC-10»…) y «Quitar salón»; los salones
 * sugeridos (RoomSuggestionQuery) como radios, con su clave en Mono, tipo, capacidad, motivos y estado; los ocupados
 * (HC-02) deshabilitados con quién los ocupa; los que causarían HC-05, HC-06, HC-10 u HC-11 muestran el choque que
 * dejarían; el buscador de otro salón y «Asignar salón», o «Asignar y pedir préstamo» si el elegido es de otro
 * departamento (AssignSessionRoom). Los errores de la Action salen en un aviso arriba.
 *
 * Asigna quien puede GroupPolicy::assignRoom (el jefe del departamento del grupo) mientras el grupo se pueda editar
 * (SessionRules::ensureEditable: sin cancelar y con la carga en propuesta, asignación o ajustes). Si no, se ve igual,
 * de solo lectura, con su motivo: «Lo asigna el jefe de <depto>.» o el de la carga (DESIGN.md, «Nada oculto por
 * permiso»). Cada método recarga la sesión con KeyLookup y vuelve a autorizar. Las sugerencias y la búsqueda salen de
 * una sola lectura de RoomSuggestionQuery por petición. Tras asignar o quitar despacha `session-room-saved` (con
 * `sessionId`) para que el editor se refresque.
 *
 * El editor lo incrusta en el cajón de la sesión con `wire:key="room-{sessionId}-{updated_at}"`, para que se vuelva
 * a montar cuando la sesión se mueve o cambia: este componente no escucha los eventos del editor.
 */
new class extends Component
{
    use Toast;

    /** La sesión del cajón. El navegador no la puede cambiar. */
    #[Locked]
    public string $sessionId = '';

    /** El salón elegido en los radios. */
    public string $roomId = '';

    public string $search = '';

    /** La sesión y las sugerencias, leídas una vez por petición; se sueltan después de escribir. */
    protected ?GroupSession $loadedSession = null;

    /** @var array<string, mixed>|null */
    protected ?array $loaded = null;

    public function mount(string $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->authorize('view', $this->session()->group);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $session = $this->session();
        $this->authorize('view', $session->group);

        $data = $this->suggestions();
        $searched = trim($this->search) !== '';
        $selected = collect([...$data['rooms'], ...$data['results']])->firstWhere('id', $this->roomId);
        $allowed = auth()->user()?->can('assignRoom', $session->group) ?? false;
        $reason = $allowed ? $this->editBlockedReason($session) : "Lo asigna el jefe de {$data['department']['name']}.";

        return [
            'current' => $data['current'],
            'rooms' => $data['rooms'],
            'results' => $data['results'],
            'searched' => $searched,
            'nothingFound' => $searched && $data['results'] === [],
            'selected' => $selected,
            'asksLoan' => $selected !== null && $selected['state']['needsLoan'] && ! $selected['state']['covered'],
            'canAssign' => $reason === null,
            'reason' => $reason,
        ];
    }

    public function assign(AssignSessionRoom $assign): void
    {
        $session = $this->session();
        $this->authorize('assignRoom', $session->group);

        $this->validate(['roomId' => 'required'], ['roomId.required' => 'Elige un salón.']);

        $room = KeyLookup::findOrFail(Room::query()->with(['department' => fn ($query) => $query->withTrashed()]), $this->roomId);
        $result = $assign->handle($session, $room, auth()->user());

        $this->written();
        $this->reset('roomId', 'search');
        $this->success('Salón asignado', e($result->loanRequested ? "{$room->code} · préstamo pedido a {$room->department?->name}" : $room->code));
        $this->dispatch('session-room-saved', sessionId: $session->id);
    }

    public function removeRoom(AssignSessionRoom $assign): void
    {
        $session = $this->session();
        $this->authorize('assignRoom', $session->group);

        $current = $this->suggestions()['current'];

        if ($current === null) {
            return;
        }

        $assign->handle($session, null, auth()->user());

        $this->written();
        $this->reset('roomId', 'search');
        $this->success('Salón quitado', e($current['code']));
        $this->dispatch('session-room-saved', sessionId: $session->id);
    }

    private function session(): GroupSession
    {
        return $this->loadedSession ??= KeyLookup::findOrFail(GroupSession::query()->with('group'), $this->sessionId);
    }

    /**
     * Las sugerencias y, con búsqueda, sus resultados, de una sola lectura.
     *
     * @return array<string, mixed>
     */
    private function suggestions(): array
    {
        return $this->loaded ??= app(RoomSuggestionQuery::class)->for($this->session(), search: $this->search);
    }

    /**
     * Por qué no se edita el grupo de la sesión (cancelado, o la carga fuera de propuesta, asignación o ajustes), con
     * el mensaje de SessionRules; null si se edita.
     */
    private function editBlockedReason(GroupSession $session): ?string
    {
        try {
            app(SessionRules::class)->ensureEditable($session->group);
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }

        return null;
    }

    /** Después de escribir, lo leído ya no vale. */
    private function written(): void
    {
        $this->loadedSession = null;
        $this->loaded = null;
    }
};
