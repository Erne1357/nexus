<?php

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomSuggestionQuery;
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
 * Asigna quien puede GroupPolicy::assignRoom (el jefe del departamento del grupo); quien solo ve el grupo lo ve igual,
 * de solo lectura, con «Lo asigna el jefe de <depto>.» (DESIGN.md, «Nada oculto por permiso»). Cada método recarga la
 * sesión con KeyLookup y vuelve a autorizar. Tras asignar o quitar despacha `session-room-saved` (con `sessionId`)
 * para que el editor se refresque.
 */
new class extends Component
{
    use Toast;

    /** Cuántos resultados da el buscador, aparte de los sugeridos. */
    private const int RESULTS = 8;

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
        $found = trim($this->search) === '' ? [] : app(RoomSuggestionQuery::class)->for($session, self::RESULTS + count($data['rooms']), $this->search)['rooms'];
        $suggested = array_column($data['rooms'], 'id');
        $results = array_slice(array_values(array_filter($found, fn (array $row): bool => ! in_array($row['id'], $suggested, true))), 0, self::RESULTS);
        $selected = collect([...$data['rooms'], ...$results])->firstWhere('id', $this->roomId);
        $canAssign = auth()->user()?->can('assignRoom', $session->group) ?? false;

        return [
            'current' => $data['current'],
            'rooms' => $data['rooms'],
            'results' => $results,
            'searched' => trim($this->search) !== '',
            'nothingFound' => trim($this->search) !== '' && $found === [],
            'selected' => $selected,
            'asksLoan' => $selected !== null && $selected['state']['needsLoan'] && ! $selected['state']['covered'],
            'canAssign' => $canAssign,
            'reason' => $canAssign ? null : "Lo asigna el jefe de {$data['department']['name']}.",
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
        $this->success('Salón quitado', e($current['code']));
        $this->dispatch('session-room-saved', sessionId: $session->id);
    }

    private function session(): GroupSession
    {
        return $this->loadedSession ??= KeyLookup::findOrFail(GroupSession::query()->with('group'), $this->sessionId);
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestions(): array
    {
        return $this->loaded ??= app(RoomSuggestionQuery::class)->for($this->session());
    }

    /** Después de escribir, lo leído ya no vale. */
    private function written(): void
    {
        $this->loadedSession = null;
        $this->loaded = null;
    }
};
