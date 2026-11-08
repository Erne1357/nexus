<?php

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomSuggestionQuery;
use App\Modules\Scheduling\Rules\LoadEditability;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * La sección de salón del cajón de la sesión en el editor de horarios (tarea 3.7; maqueta `cajones`, estado
 * `#sesion`): el salón actual con su estado («Asignado», «Préstamo pendiente · HC-10»…) y «Quitar salón»; los salones
 * sugeridos (RoomSuggestionQuery) como radios, los tres mejores a la vista y los demás con «Ver N más», con su clave
 * en Mono, tipo, si cabe el grupo, por qué se sugiere y su estado («Libre» solo si alguno no lo está); los ocupados
 * (HC-02) o apartados por el préstamo de otro grupo, deshabilitados con quién los tiene; los que causarían HC-05,
 * HC-06, HC-10 u HC-11 muestran el choque que dejarían; el buscador de otro salón y «Asignar salón», o «Asignar y pedir
 * préstamo» si el elegido es de otro departamento (AssignSessionRoom). Los errores de la Action salen en un aviso arriba.
 *
 * La elección vive en el navegador (Alpine) hasta «Asignar»: elegir un radio no va al servidor. El buscador va con
 * debounce y filtra en memoria lo que la Query ya leyó en esa petición (una sola lectura por petición).
 *
 * Asigna quien puede GroupPolicy::assignRoom (el jefe del departamento del grupo) mientras el grupo se pueda editar
 * (LoadEditability: sin cancelar y con la carga en propuesta, asignación o ajustes). Si no, se ve igual, de solo
 * lectura, con su motivo: «Lo asigna el jefe de <depto>.» o el de la carga (DESIGN.md, «Nada oculto por permiso»). Cada
 * método recarga la sesión con KeyLookup y vuelve a autorizar. Tras asignar o quitar despacha `session-room-saved`
 * (con `sessionId`) para que el editor se refresque.
 *
 * El editor lo incrusta en el cajón de la sesión con `wire:key="room-{sessionId}-{updated_at}"`, para que se vuelva
 * a montar cuando la sesión se mueve o cambia: este componente no escucha los eventos del editor.
 */
new class extends Component
{
    use Toast;

    /** Cuántos sugeridos se ven de entrada; los demás, con «Ver N más». */
    private const int SHOWN = 3;

    /** La sesión del cajón. El navegador no la puede cambiar. */
    #[Locked]
    public string $sessionId = '';

    public string $search = '';

    /** La sesión (con su grupo y su salón) y las sugerencias, leídas una vez por petición; se sueltan al escribir. */
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
        $allowed = auth()->user()?->can('assignRoom', $session->group) ?? false;
        $reason = $allowed
            ? app(LoadEditability::class)->blockedReasons([$session->group])[$session->group->id]
            : "Lo asigna el jefe de {$data['department']['name']}.";
        $listed = [...$data['rooms'], ...$data['results']];
        $hidden = array_column(array_slice($data['rooms'], self::SHOWN), 'id');

        return [
            'current' => $data['current'],
            'rooms' => $data['rooms'],
            'shown' => self::SHOWN,
            'revealed' => array_intersect($hidden, $data['matched']) !== [],
            'allFree' => array_all($listed, fn (array $row): bool => $row['state']['free']),
            'results' => $data['results'],
            'matched' => $data['matched'],
            'searched' => $searched,
            'nothingFound' => $searched && $data['results'] === [] && $data['matched'] === [],
            'loanRooms' => array_values(array_column(array_filter($listed, fn (array $row): bool => $row['state']['needsLoan'] && ! $row['state']['covered']), 'id')),
            'canAssign' => $reason === null,
            'reason' => $reason,
        ];
    }

    public function assign(string $roomId, AssignSessionRoom $assign): void
    {
        $session = $this->session();
        $this->authorize('assignRoom', $session->group);

        if ($roomId === '') {
            $this->addError('roomId', 'Elige un salón.');

            return;
        }

        $room = KeyLookup::findOrFail(Room::query()->with(['department' => fn ($query) => $query->withTrashed()]), $roomId);
        $result = $assign->handle($session, $room, auth()->user());

        $this->written();
        $this->reset('search');
        $this->success('Salón asignado', e($result->loanRequested ? "{$room->code} · préstamo pedido a {$room->department?->name}" : $room->code));
        $this->dispatch('session-room-saved', sessionId: $session->id);
    }

    public function removeRoom(AssignSessionRoom $assign): void
    {
        $session = $this->session();
        $this->authorize('assignRoom', $session->group);

        $code = $session->room?->code;

        if ($code === null) {
            return;
        }

        $assign->handle($session, null, auth()->user());

        $this->written();
        $this->reset('search');
        $this->success('Salón quitado', e($code));
        $this->dispatch('session-room-saved', sessionId: $session->id);
    }

    private function session(): GroupSession
    {
        return $this->loadedSession ??= KeyLookup::findOrFail(
            GroupSession::query()->with(['group', 'room' => fn ($query) => $query->withTrashed()]),
            $this->sessionId,
        );
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

    /** Después de escribir, lo leído ya no vale. */
    private function written(): void
    {
        $this->loadedSession = null;
        $this->loaded = null;
    }
};
