<?php

use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\LoadVersionsQuery;
use App\Modules\AcademicLoad\Queries\PublicationPreviewQuery;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Publicar la carga de un departamento y ver sus versiones (tarea 4.3; DESIGN.md «Publicación», variante B elegida por
 * el usuario el 10 de noviembre de 2026): la línea de versiones, con un punto por publicación (fecha y grupos, la
 * vigente en verde) y la siguiente punteada como el botón de publicar. Tocar un punto abre lo que cambió contra la
 * anterior; publicar abre la confirmación con lo que se congelaría (PublicationPreviewQuery), los grupos sin docente y
 * un comentario opcional. Un veto (HC-15 al publicar) se muestra en la confirmación y no publica.
 *
 * Lo montan las pantallas del Riel C (cierre del departamento y tablero de Subdirección) con
 * `<livewire:academic-load::components.publish-load :load="$load" wire:key="publish-{$load->id}" />`; al publicar
 * despacha `load-published` (con `loadId` y `version`) para que la pantalla se refresque.
 *
 * Lo ve quien puede ver la carga (`view`). Publica quien tiene `publish` (Subdirección, o el jefe del departamento si
 * el campus no exige visto bueno) con la carga cerrada o aprobada; si no, el último punto dice por qué no se puede
 * (DESIGN.md, «Nada oculto por permiso»). Cada método recarga la carga con KeyLookup y vuelve a autorizar.
 */
new class extends Component
{
    use Toast;

    /** La carga. El navegador no la puede cambiar. */
    #[Locked]
    public string $loadId = '';

    /** La versión abierta en la línea; null si ninguna. */
    public ?int $openVersion = null;

    public bool $confirming = false;

    public string $comment = '';

    /** @var list<string> los motivos del último veto */
    public array $vetoes = [];

    protected ?AcademicLoad $loaded = null;

    public function mount(AcademicLoad $load): void
    {
        $this->loadId = $load->id;
        $this->authorize('view', $this->load());
    }

    /**
     * @return array<string, mixed>
     */
    public function with(LoadVersionsQuery $versions, PublicationPreviewQuery $preview): array
    {
        $load = $this->load();
        $this->authorize('view', $load);

        $list = $versions->versions($load);

        return [
            'load' => $load,
            'versions' => $list,
            'current' => $load->current_version,
            'nextVersion' => $load->current_version + 1,
            'blocked' => $this->blockedReason($load),
            'opened' => $this->openVersion === null ? null : collect($list)->firstWhere('version', $this->openVersion),
            'changes' => $this->openVersion === null ? null : $versions->changes($load, $this->openVersion),
            'preview' => $this->confirming ? $preview->preview($load) : null,
        ];
    }

    public function toggleVersion(int $version): void
    {
        $this->authorize('view', $this->load());
        $this->openVersion = $this->openVersion === $version ? null : $version;
    }

    public function confirm(): void
    {
        $load = $this->load();
        $this->authorize('publish', $load);

        if ($this->blockedReason($load) !== null) {
            return;
        }

        $this->reset('comment', 'vetoes');
        $this->confirming = true;
    }

    public function cancel(): void
    {
        $this->reset('confirming', 'comment', 'vetoes');
    }

    public function publish(PublishLoad $publish): void
    {
        $load = $this->load();
        $this->authorize('publish', $load);
        $this->validate(['comment' => ['nullable', 'string', 'max:500']], attributes: ['comment' => 'comentario']);

        try {
            $snapshot = $publish->handle($load, auth()->user(), $this->comment);
        } catch (ValidationException $vetoed) {
            $this->vetoes = array_values($vetoed->errors()['status'] ?? $vetoed->validator->errors()->all());

            return;
        }

        $this->loaded = null;
        $this->reset('confirming', 'comment', 'vetoes');
        $this->openVersion = $snapshot->version;
        $this->success("Versión {$snapshot->version} publicada", e("{$load->department->name} · {$load->campus->code}"));
        $this->dispatch('load-published', loadId: $load->id, version: $snapshot->version);
    }

    /**
     * Por qué no se puede publicar ahora, o null si se puede.
     */
    private function blockedReason(AcademicLoad $load): ?string
    {
        $profile = $load->campus->profile;

        return match (true) {
            ! (auth()->user()?->can('publish', $load) ?? false) => $profile->requiresSubdireccionApproval
                ? 'En este campus publica Subdirección, después de su visto bueno.'
                : 'Publica Subdirección o el jefe del departamento.',
            $load->status === LoadStatus::Published => 'Ya está publicada. Para cambiarla, Subdirección la reabre.',
            $load->status === LoadStatus::Closed && $profile->requiresSubdireccionApproval => 'Primero va el visto bueno de Subdirección.',
            ! $load->status->canTransitionTo(LoadStatus::Published, $profile) => 'Se publica cuando la carga está cerrada.',
            default => null,
        };
    }

    private function load(): AcademicLoad
    {
        return $this->loaded ??= KeyLookup::findOrFail(AcademicLoad::query()->with(['campus', 'department', 'term']), $this->loadId);
    }
};
