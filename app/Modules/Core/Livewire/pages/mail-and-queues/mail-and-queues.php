<?php

use App\Modules\Core\Actions\ForgetFailedJob;
use App\Modules\Core\Actions\RetryFailedJob;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\FailedJobsQuery;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\MailLogQuery;
use App\Modules\Core\Queries\PendingInvitationsQuery;
use App\Modules\Core\Queries\QueueSizesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * «Correos y colas» (CF-S8), solo del administrador (CF-03): tres pestañas. Correos es el registro de lo que salió
 * (sin el cuerpo) con búsqueda, tipo, estado y fechas; Invitaciones, los usuarios invitados que todavía no activan su
 * acceso, con su vencimiento y «Reenviar» (SendAccessInvitation); Colas, cuántos trabajos hay en cada cola y los
 * fallidos, que se reintentan, se descartan o se leen en un drawer. Cada pestaña y cada acción se autoriza con su
 * policy (MailLogPolicy, FailedJobPolicy), también al llamarla directo.
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

    public function mount(): void
    {
        abort_if($this->tabs() === [], 403);

        $this->tab = $this->tabOrFirst($this->tab);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(MailLogQuery $mail, PendingInvitationsQuery $invitations, FailedJobsQuery $failed, QueueSizesQuery $sizes): array
    {
        $tab = $this->tabOrFirst($this->tab);
        $data = ['tabs' => $this->tabs(), 'activeTab' => $tab];

        return $data + match ($tab) {
            'mail' => [
                'mailLog' => $mail->forTable($this->search, $this->kind, MailStatus::tryFrom($this->status), $this->date($this->from), $this->date($this->to)),
                'kinds' => array_map(fn (string $kind): array => ['id' => $kind, 'name' => $kind], $mail->kinds()),
                'statuses' => array_map(fn (MailStatus $status): array => ['id' => $status->value, 'name' => $status->label()], MailStatus::cases()),
            ],
            'invitations' => ['pending' => $invitations->all()],
            default => [
                'sizes' => $sizes->all(),
                'failedJobs' => $failed->forTable(),
                'errorJob' => $this->showError && $this->errorUuid !== null ? $failed->findOrFail($this->errorUuid) : null,
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

    public function resend(string $id, SendAccessInvitation $invite): void
    {
        $this->authorize('viewAny', MailLog::class);
        $this->authorize('resendInvitation', MailLog::class);

        $user = KeyLookup::findOrFail(User::query(), $id);

        try {
            $invite->handle($user, (string) $user->email, auth()->user());
            $this->success('Invitación reenviada', e((string) $user->email));
        } catch (ValidationException $exception) {
            $this->error('No se reenvió la invitación', e(collect($exception->errors())->flatten()->first()));
        } catch (RuntimeException $exception) {
            $this->error('No se reenvió la invitación', e($exception->getMessage()));
        }
    }

    public function retry(string $uuid, FailedJobsQuery $failed, RetryFailedJob $retry): void
    {
        $this->authorize('viewAny', FailedJob::class);
        $job = $failed->findOrFail($uuid);
        $this->authorize('retry', $job);

        $retry->handle($job, auth()->user());
        $this->success('Trabajo reenviado a la cola', e($job->job_name));
    }

    public function forget(string $uuid, FailedJobsQuery $failed, ForgetFailedJob $forget): void
    {
        $this->authorize('viewAny', FailedJob::class);
        $job = $failed->findOrFail($uuid);
        $this->authorize('forget', $job);

        $forget->handle($job, auth()->user());
        $this->success('Trabajo descartado', e($job->job_name));
    }

    public function openError(string $uuid, FailedJobsQuery $failed): void
    {
        $this->authorize('viewAny', FailedJob::class);

        $this->errorUuid = $failed->findOrFail($uuid)->uuid;
        $this->showError = true;
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
