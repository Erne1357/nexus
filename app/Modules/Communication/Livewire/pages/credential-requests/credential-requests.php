<?php

use App\Modules\Core\Actions\UpdateSettings;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Students\Actions\DeliverCredentialRequest;
use App\Modules\Students\Actions\MarkCredentialRequestReady;
use App\Modules\Students\Actions\RejectCredentialRequest;
use App\Modules\Students\Actions\ScheduleCredentialRequest;
use App\Modules\Students\Actions\SetStudentPhoto;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Queries\CredentialRequestQuery;
use App\Modules\Students\Rules\CredentialRequestSettings;
use App\Modules\Students\Support\StudentPhotoUrl;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * «Solicitudes de credencial» de Comunicación y Difusión (CO-07 a CO-09, `/comunicacion/solicitudes`): la bandeja con una
 * pestaña por estado y su conteo, búsqueda por número de control o nombre y, en cada renglón, la siguiente acción. Un
 * drawer por solicitud lleva la foto y los datos del alumno, lo que pidió, la cita y las acciones: citar o reprogramar,
 * subir la foto, marcar lista, entregar y rechazar (el motivo, en un modal). «Plazos» abre otro drawer con los días que
 * deben pasar entre dos solicitudes del mismo tipo (0 es sin límite) y los guarda con UpdateSettings.
 *
 * Escriben las Actions de Students y UpdateSettings de Core. Autoriza en mount() y en cada método con
 * CredentialRequestPolicy::manage (`students.credential_request.manage`). Maqueta A de .impeccable/mocks/decision/comunicacion.
 */
new #[Title('Solicitudes de credencial')] class extends Component
{
    use Toast, WithFileUploads, WithPagination;

    /** El estado de la pestaña: solicitada, citada, lista, entregada o rechazada. */
    #[Session]
    public string $tab = 'solicitada';

    #[Session]
    public string $search = '';

    public bool $showRequest = false;

    /** La solicitud del drawer. El navegador no la puede cambiar. */
    #[Locked]
    public ?string $requestId = null;

    public string $scheduleDate = '';

    public string $scheduleTime = '';

    public string $place = '';

    public $photo = null;

    public bool $showReject = false;

    public string $reason = '';

    public bool $showSettings = false;

    public int|string $repositionDays = 0;

    public int|string $photoDays = 0;

    public function mount(): void
    {
        $this->authorizeManage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(CredentialRequestQuery $query): array
    {
        $this->authorizeManage();

        $status = $this->status();
        $requests = $query->tray($status, $this->search);
        $current = $this->showRequest && $this->requestId !== null ? $query->findOrFail($this->requestId) : null;

        return [
            'requests' => $requests,
            'counts' => $query->counts(),
            'tabs' => array_values(array_filter(CredentialRequestStatus::cases(), fn (CredentialRequestStatus $case): bool => $case !== CredentialRequestStatus::Cancelada)),
            'current' => $current,
            'currentPhoto' => $current === null ? null : StudentPhotoUrl::for($current->student),
            'preview' => $this->showRequest && $this->photo !== null && $this->photo->isPreviewable() ? $this->photo->temporaryUrl() : null,
        ];
    }

    public function updated(string $property): void
    {
        $this->authorizeManage();

        if (in_array($property, ['tab', 'search'], true)) {
            $this->resetPage();
        } elseif ($property === 'showRequest' && ! $this->showRequest) {
            $this->forgetRequest();
        } elseif ($property === 'showSettings' && ! $this->showSettings) {
            $this->showSettings = false;
            $this->resetValidation();
        }
    }

    public function openRequest(string $id, CredentialRequestQuery $query): void
    {
        $this->authorizeManage();

        $this->forgetRequest();
        $request = $query->findOrFail($id);
        $this->requestId = $request->id;
        $this->place = (string) $request->place;
        $this->scheduleDate = $request->scheduled_at?->setTimezone('America/Ciudad_Juarez')->format('Y-m-d') ?? '';
        $this->scheduleTime = $request->scheduled_at?->setTimezone('America/Ciudad_Juarez')->format('H:i') ?? '';
        $this->showRequest = true;
    }

    public function startReject(string $id, CredentialRequestQuery $query): void
    {
        $this->openRequest($id, $query);
        $this->openReject();
    }

    public function closeRequest(): void
    {
        $this->authorizeManage();

        $this->forgetRequest();
    }

    public function schedule(ScheduleCredentialRequest $schedule): void
    {
        $this->authorizeManage();

        $this->validate([
            'scheduleDate' => ['required', 'date_format:Y-m-d'],
            'scheduleTime' => ['required', 'date_format:H:i'],
            'place' => ['required', 'string', 'max:200'],
        ], [
            'scheduleDate.required' => 'Elige la fecha de la cita.',
            'scheduleDate.date_format' => 'La fecha no es válida.',
            'scheduleTime.required' => 'Elige la hora de la cita.',
            'scheduleTime.date_format' => 'La hora no es válida.',
            'place.required' => 'Escribe el lugar de la cita.',
        ]);

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$this->scheduleDate} {$this->scheduleTime}", 'America/Ciudad_Juarez');

        $this->run(fn (CredentialRequest $request) => $schedule->handle($request, $at, $this->place, auth()->user()), 'Cita guardada');
    }

    public function savePhoto(SetStudentPhoto $setStudentPhoto): void
    {
        $this->authorizeManage();

        $this->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240']], [
            'photo.required' => 'Elige la foto.',
            'photo.mimes' => 'Solo jpg, png o webp.',
            'photo.max' => 'La foto pesa más de 10 MB.',
            'photo.uploaded' => 'No se pudo subir la foto. Revisa que pese menos de 10 MB.',
        ]);

        $request = $this->current();

        if (! $request->status->isOpen()) {
            $this->addError('request', 'La solicitud ya está cerrada: no se le sube foto.');

            return;
        }

        try {
            $setStudentPhoto->handle($request->student, $this->photo, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('photo', collect($e->errors())->flatten()->first());

            return;
        }

        $this->resetErrorBag('request');
        $this->reset('photo');
        $this->success('Foto guardada', e($request->student->name));
    }

    public function markReady(MarkCredentialRequestReady $ready): void
    {
        $this->authorizeManage();

        $this->run(fn (CredentialRequest $request) => $ready->handle($request, auth()->user()), 'Marcada como lista para recoger');
    }

    public function deliver(DeliverCredentialRequest $deliver): void
    {
        $this->authorizeManage();

        $this->run(fn (CredentialRequest $request) => $deliver->handle($request, auth()->user()), 'Entregada');
    }

    public function openReject(): void
    {
        $this->authorizeManage();

        $this->current();
        $this->reset('reason');
        $this->resetValidation();
        $this->showReject = true;
    }

    public function closeReject(): void
    {
        $this->authorizeManage();

        $this->reset('showReject', 'reason');
        $this->resetValidation();
    }

    public function reject(RejectCredentialRequest $reject): void
    {
        $this->authorizeManage();

        $this->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Escribe el motivo del rechazo.']);

        $this->run(fn (CredentialRequest $request) => $reject->handle($request, $this->reason, auth()->user()), 'Solicitud rechazada');
        $this->reset('showReject', 'reason');
    }

    public function openSettings(): void
    {
        $this->authorizeManage();

        $settings = CredentialRequestSettings::fromSettings();
        $this->repositionDays = $settings->repositionDays;
        $this->photoDays = $settings->photoDays;
        $this->resetValidation();
        $this->showSettings = true;
    }

    public function closeSettings(): void
    {
        $this->authorizeManage();

        $this->reset('showSettings');
        $this->resetValidation();
    }

    public function saveSettings(UpdateSettings $update, SettingRegistry $registry): void
    {
        $this->authorizeManage();

        $this->validate([
            'repositionDays' => ['required', 'integer', 'min:0', 'max:3650'],
            'photoDays' => ['required', 'integer', 'min:0', 'max:3650'],
        ], [
            'repositionDays.*' => 'Escribe un número de días entre 0 y 3650.',
            'photoDays.*' => 'Escribe un número de días entre 0 y 3650.',
        ]);

        $update->handle([
            $registry->get(CredentialRequestSettings::REPOSITION_DAYS_SETTING),
            $registry->get(CredentialRequestSettings::PHOTO_DAYS_SETTING),
        ], [
            CredentialRequestSettings::REPOSITION_DAYS_SETTING => (int) $this->repositionDays,
            CredentialRequestSettings::PHOTO_DAYS_SETTING => (int) $this->photoDays,
        ]);

        $this->success('Plazos guardados');
        $this->closeSettings();
    }

    private function status(): CredentialRequestStatus
    {
        $status = CredentialRequestStatus::tryFrom($this->tab);

        return $status === null || $status === CredentialRequestStatus::Cancelada ? CredentialRequestStatus::Solicitada : $status;
    }

    private function current(): CredentialRequest
    {
        return app(CredentialRequestQuery::class)->findOrFail((string) $this->requestId);
    }

    /**
     * Corre una Action sobre la solicitud del drawer; si una regla la rechaza, deja el mensaje en el drawer.
     *
     * @param  Closure(CredentialRequest): mixed  $action
     */
    private function run(Closure $action, string $done): void
    {
        $request = $this->current();

        try {
            $action($request);
        } catch (ValidationException $e) {
            $this->addError('request', collect($e->errors())->flatten()->first());

            return;
        }

        $this->success($done, e($request->student->name));
        $this->resetErrorBag('request');
    }

    private function forgetRequest(): void
    {
        $this->resetValidation();
        $this->reset('showRequest', 'requestId', 'photo', 'place', 'scheduleDate', 'scheduleTime', 'showReject', 'reason');
    }

    /**
     * CO-07: quien atiende la bandeja; 403 si no.
     */
    private function authorizeManage(): void
    {
        $this->authorize('manage', CredentialRequest::class);
    }
};
