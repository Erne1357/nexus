<?php

use App\Modules\Core\Actions\DisconnectMailAccount;
use App\Modules\Core\Actions\ForgetFailedJob;
use App\Modules\Core\Actions\InviteUser;
use App\Modules\Core\Actions\RetryFailedJob;
use App\Modules\Core\Actions\SendTestMail;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Mail\MicrosoftGraph;
use App\Modules\Core\Mail\MicrosoftSignIn;
use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\FailedJobsQuery;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\MailConnectionQuery;
use App\Modules\Core\Queries\MailLogQuery;
use App\Modules\Core\Queries\PendingInvitationsQuery;
use App\Modules\Core\Queries\QueueSizesQuery;
use App\Modules\Core\Support\MailKinds;
use App\Modules\Core\Support\RedactsSecrets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * «Correos y colas» (CF-S8), solo del administrador (CF-03): tres pestañas. Correos es el registro de lo que salió
 * (sin el cuerpo) con búsqueda, tipo, estado y fechas; Invitaciones, los usuarios invitados que todavía no activan su
 * acceso, con su vencimiento y «Reenviar» (la invitación del admin, InviteUser, CF-07); Colas, cuántos trabajos hay en cada cola y los
 * fallidos, que se reintentan, se descartan o se leen en un drawer. Cada pestaña y cada acción se autoriza con su
 * policy (MailLogPolicy, FailedJobPolicy), también al llamarla directo.
 *
 * Arriba de las pestañas, la tarjeta «Cuenta de envío» (tarea 1.29, ADR-0032, CF-S9): la cuenta de Microsoft 365 con que
 * sale el correo por Microsoft Graph y su estado, con «Conectar con Microsoft» (lleva al inicio de sesión de Microsoft;
 * el regreso lo atiende MicrosoftMailCallback), «Desconectar» con confirmación y «Enviar correo de prueba». Sus acciones
 * son del admin (MailConnectionPolicy::manage).
 */
new #[Title('Correos y colas')] class extends Component
{
    use Toast, WithPagination;

    #[Session]
    public string $tab = 'mail';

    #[Session]
    public string $search = '';

    #[Session]
    public string $kind = '';

    #[Session]
    public string $status = '';

    #[Session]
    public string $from = '';

    #[Session]
    public string $to = '';

    public bool $showError = false;

    /** El trabajo fallido cuyo error se lee en el drawer. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $errorUuid = null;

    /**
     * El aviso del regreso de Microsoft al conectar la cuenta de envío (sesión flash de MicrosoftMailCallback).
     *
     * @var array{tone: string, title: string, text: string}|null
     */
    #[Locked]
    public ?array $notice = null;

    public function mount(): void
    {
        abort_if($this->tabs() === [], 403);

        $this->tab = $this->tabOrFirst($this->tab);

        $notice = session(MicrosoftSignIn::NOTICE_KEY);
        $this->notice = is_array($notice) && in_array($notice['tone'] ?? null, ['success', 'error'], true) ? $notice : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(MailLogQuery $mail, PendingInvitationsQuery $invitations, FailedJobsQuery $failed, QueueSizesQuery $sizes, MailConnectionQuery $connections): array
    {
        $tab = $this->tabOrFirst($this->tab);
        $data = [
            'tabs' => $this->tabs(),
            'activeTab' => $tab,
            'sending' => Gate::allows('viewAny', MailLog::class) ? $connections->card() : null,
            'canManageSending' => Gate::allows('manage', MailConnection::class),
        ];

        return $data + match ($tab) {
            'mail' => [
                'mailLog' => $mail->forTable($this->search, $this->kind, MailStatus::tryFrom($this->status), $this->date($this->from), $this->date($this->to)),
                'kinds' => array_map(fn (string $kind): array => ['id' => $kind, 'name' => MailKinds::label($kind)], $mail->kinds()),
                'statuses' => array_map(fn (MailStatus $status): array => ['id' => $status->value, 'name' => $status->label()], MailStatus::cases()),
            ],
            'invitations' => ['pending' => $invitations->all()],
            default => [
                'sizes' => $sizes->all(),
                'failedJobs' => $failed->forTable(),
                'errorJob' => $this->showError && $this->errorUuid !== null ? $this->errorJobOrClose($failed) : null,
            ],
        };
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'kind', 'status', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function showTab(string $tab): void
    {
        $this->tab = $this->tabOrFirst($tab);
        $this->resetPage();
    }

    public function resend(string $id, InviteUser $invite): void
    {
        $this->authorize('viewAny', MailLog::class);
        $this->authorize('resendInvitation', MailLog::class);

        $user = KeyLookup::findOrFail(User::query(), $id);

        try {
            $this->success('Invitación reenviada', e($invite->handle($user, auth()->user())));
        } catch (ValidationException $exception) {
            $this->error('No se reenvió la invitación', e(collect($exception->errors())->flatten()->first()));
        } catch (RuntimeException $exception) {
            $this->error('No se reenvió la invitación', e($exception->getMessage()));
        }
    }

    /**
     * «Conectar con Microsoft»: guarda el `state` y el verificador de PKCE en la sesión y lleva al inicio de sesión de
     * Microsoft. Mal configurado (faltan variables o MICROSOFT_GRAPH_TENANT_ID es uno común) no sale y dice qué falta.
     */
    public function connectMicrosoft(MicrosoftGraph $graph, MicrosoftSignIn $signIn): void
    {
        $this->authorizeSending();

        if (($problems = $graph->configurationProblems()) !== []) {
            $this->error('La app de Azure no está configurada', e(implode(' ', $problems).' Corrige el .env y reinicia Nexus.'));

            return;
        }

        $this->redirect($signIn->begin(session()->driver(), (string) auth()->id()));
    }

    public function disconnectAccount(DisconnectMailAccount $disconnect): void
    {
        $this->authorizeSending();

        $account = $disconnect->handle(auth()->user());
        $this->notice = null;

        if ($account === null) {
            $this->warning('No había una cuenta de envío conectada');

            return;
        }

        $this->success('Cuenta de envío desconectada', e("Nexus ya no manda correo como {$account}."));
    }

    public function sendTestMail(MailConnectionQuery $connections, SendTestMail $send): void
    {
        $this->authorizeSending();

        $connection = $connections->current();

        if ($connection === null || $connection->expired_at !== null) {
            $this->warning('Primero conecta la cuenta de envío', 'Usa «Conectar con Microsoft».');

            return;
        }

        try {
            $to = $send->handle(auth()->user(), $connection);
            $this->success('Correo de prueba enviado', e("Revisa la bandeja de {$to}."));
        } catch (TransportExceptionInterface $exception) {
            $this->error('No salió el correo de prueba', e(RedactsSecrets::redact($exception->getMessage())), timeout: 10000);
        } catch (ValidationException $exception) {
            $this->error('No salió el correo de prueba', e((string) collect($exception->errors())->flatten()->first()), timeout: 10000);
        }
    }

    public function retry(string $uuid, FailedJobsQuery $failed, RetryFailedJob $retry): void
    {
        $this->authorize('viewAny', FailedJob::class);
        if (($job = $failed->firstByUuid($uuid)) === null) {
            $this->gone();

            return;
        }

        $this->authorize('retry', $job);

        $retry->handle($job, auth()->user());
        $this->success('Trabajo reenviado a la cola', e($job->job_name));
    }

    public function forget(string $uuid, FailedJobsQuery $failed, ForgetFailedJob $forget): void
    {
        $this->authorize('viewAny', FailedJob::class);
        if (($job = $failed->firstByUuid($uuid)) === null) {
            $this->gone();

            return;
        }

        $this->authorize('forget', $job);

        $forget->handle($job, auth()->user());
        $this->success('Trabajo descartado', e($job->job_name));
    }

    public function openError(string $uuid, FailedJobsQuery $failed): void
    {
        $this->authorize('viewAny', FailedJob::class);

        if (($job = $failed->firstByUuid($uuid)) === null) {
            $this->gone();

            return;
        }

        $this->errorUuid = $job->uuid;
        $this->showError = true;
    }

    private function errorJobOrClose(FailedJobsQuery $failed): ?FailedJob
    {
        $job = $failed->firstByUuid($this->errorUuid);

        if ($job === null) {
            $this->showError = false;
        }

        return $job;
    }

    private function authorizeSending(): void
    {
        $this->authorize('viewAny', MailLog::class);
        $this->authorize('manage', MailConnection::class);
    }

    private function gone(): void
    {
        $this->showError = false;
        $this->warning('Ese trabajo ya no está en los fallidos', 'Alguien más lo reintentó o lo descartó.');
    }

    /**
     * Las pestañas que el usuario puede ver.
     *
     * @return array<string, string>
     */
    private function tabs(): array
    {
        $tabs = [];

        if (Gate::allows('viewAny', MailLog::class)) {
            $tabs = ['mail' => 'Correos', 'invitations' => 'Invitaciones'];
        }

        if (Gate::allows('viewAny', FailedJob::class)) {
            $tabs['queues'] = 'Colas';
        }

        return $tabs;
    }

    private function tabOrFirst(string $tab): string
    {
        $tabs = $this->tabs();
        abort_if($tabs === [], 403);

        return array_key_exists($tab, $tabs) ? $tab : array_key_first($tabs);
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }
};
