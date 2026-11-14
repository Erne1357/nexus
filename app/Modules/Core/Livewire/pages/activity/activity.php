<?php

use App\Modules\Core\Queries\ActivityDetail;
use App\Modules\Core\Queries\ActivityLogQuery;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * «Bitácora» (CF-S5), solo del administrador (CF-03) y de solo lectura (HC-18): la bitácora de la más nueva a la más
 * vieja, con la barra de filtros estándar (texto de la descripción, quién, tipo de registro, evento y fechas), y un
 * drawer con el detalle de la entrada que se toca: lo de antes y lo de después por campo, el puesto con que actuó, las
 * propiedades y el registro afectado. No escribe nada: ni siquiera abrir el detalle deja bitácora. Autoriza con
 * ActivityPolicy en mount(), al renderizar y en cada acción.
 */
new #[Title('Bitácora')] class extends Component
{
    use WithPagination;

    #[Session]
    public string $search = '';

    #[Session]
    public string $who = '';

    #[Session]
    public string $subjectType = '';

    #[Session]
    public string $event = '';

    #[Session]
    public string $from = '';

    #[Session]
    public string $to = '';

    public bool $showDetail = false;

    /** La entrada que se lee en el drawer. El navegador no la puede cambiar. */
    #[Locked]
    public ?int $entryId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Activity::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ActivityLogQuery $log): array
    {
        $this->authorize('viewAny', Activity::class);

        return [
            'entries' => $log->forTable($this->who, $this->subjectType, $this->event, $this->search, $this->date($this->from), $this->date($this->to)),
            'subjectTypes' => $log->subjectTypes(),
            'events' => $log->events(),
            'detail' => $this->showDetail && $this->entryId !== null ? $this->detailOf($log, $this->entryId) : null,
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'who', 'subjectType', 'event', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function openEntry(int $id, ActivityLogQuery $log): void
    {
        $this->authorize('viewAny', Activity::class);

        $this->entryId = $log->detail($id)->activity->getKey();
        $this->showDetail = true;
    }

    private function detailOf(ActivityLogQuery $log, int $id): ActivityDetail
    {
        return $log->detail($id);
    }

    private function date(string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : null;
    }
};
