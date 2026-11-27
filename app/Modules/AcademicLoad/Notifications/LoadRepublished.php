<?php

namespace App\Modules\AcademicLoad\Notifications;

use App\Modules\AcademicLoad\Enums\RepublicationAudience;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\SnapshotDiffText;
use App\Modules\CatalogValidation\Queries\HeadMailAddressQuery;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AcademicLoadNotice;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\PositionNotice;
use App\Modules\Core\Notifications\PositionRef;
use App\Modules\Core\Notifications\RoutesMailAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * El aviso de la republicación de una carga (tarea 8.4): se publicó la versión n + 1 y algo le cambió a quien lo recibe
 * (RA-05, confirmado el 17 de noviembre de 2026). Cada destinatario recibe solo sus líneas (SnapshotDiff::teachers(),
 * careers() o departments(), en palabras de SnapshotDiffText):
 *
 * - el docente: «Cambiaron tus grupos en la carga de <depto>», con exactamente lo que cambió para él; lleva a su semana
 *   publicada (o a la consulta, si ya no tiene grupos en la versión nueva);
 * - los coordinadores de una carrera afectada: «La carga de <depto> cambió grupos de <carrera>»; lleva al semestre
 *   publicado de la carrera;
 * - los jefes de otro departamento: «La carga de <depto> cambió préstamos de salón o grupos compartidos de <su depto>»;
 *   lleva a la consulta publicada.
 *
 * Todos llevan a la consulta pública, que se abre sin sesión, porque un docente puede no tener acceso a Nexus.
 *
 * RA-06 (confirmado el 17 de noviembre de 2026): va por la campana y por el correo institucional. A un usuario le llega
 * al institucional de su ficha de docente o, si no tiene, al de su usuario si es institucional (HeadMailAddressQuery, la
 * regla de CV11); nunca al personal, y a una cuenta dada de baja no le llega correo (supuesto por confirmar). Sin
 * dirección, solo la campana. Al docente sin usuario, que también se tiene que enterar, le llega solo el correo, a su
 * institucional (una notificación por ruta, `Notification::route('mail', …)`, que no tiene campana). Sale de la cola,
 * después del commit; la campana se guarda en el momento (`viaConnections`, conexión `sync`). Mientras el admin tiene
 * oculta la carga académica, el correo no sale (AcademicLoadNotice, MN-05).
 *
 * CE-02 (confirmado el 26 de noviembre de 2026): los avisos a la coordinación de una carrera y a la jefatura de otro
 * departamento compiten al puesto (PositionNotice): su correo va al buzón del coordinador de esa carrera en la modalidad
 * de la carga o al de la jefatura (o del jefe de proyecto docente) de ese departamento, y, sin buzón, espera; la campana
 * llega igual. `$audienceId` es el id de esa carrera o de ese departamento; un aviso en cola de antes no lo trae y sigue
 * a la persona. El aviso al docente es de persona.
 */
final class LoadRepublished extends NexusNotification implements AcademicLoadNotice, PositionNotice, RoutesMailAddress, ShouldQueue
{
    use Queueable;

    /** CE-02: la carrera (audiencia Career) o el departamento (audiencia Department) del aviso. No promovida, con valor por omisión, para leer los avisos en cola de antes. */
    private ?string $audienceId = null;

    public static function typeKey(): string
    {
        return 'load-republished';
    }

    public static function typeLabel(): string
    {
        return 'Carga republicada';
    }

    /**
     * @param  string  $recipientLabel  el nombre del docente, la clave de la carrera o el nombre del otro departamento
     * @param  list<array{kind: string, group: array<string, mixed>, changes?: array<string, mixed>}>  $entries  lo que le cambió
     * @param  array<string, string>  $parameters
     * @param  ?string  $audienceId  el id de la carrera o del departamento del aviso (CE-02)
     */
    public function __construct(
        private readonly AcademicLoad $load,
        private readonly int $version,
        private readonly ?SnapshotReason $reason,
        private readonly RepublicationAudience $audience,
        private readonly string $recipientLabel,
        private readonly array $entries,
        private readonly string $route,
        private readonly array $parameters,
        ?string $audienceId = null,
    ) {
        $this->audienceId = $audienceId;
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return $this->positionChannels()
            ?? ($notifiable instanceof User && $this->mailAddressFor($notifiable) !== null ? ['database', 'mail'] : ['database']);
    }

    /**
     * CE-02: el coordinador de la carrera o la jefatura del departamento, en la modalidad de la carga, con el puesto con
     * que se mandó; null para el docente.
     */
    public function position(): ?PositionRef
    {
        $role = $this->addressedRole();

        if ($role === null || $this->audienceId === null) {
            return null;
        }

        return match ($this->audience) {
            RepublicationAudience::Teacher => null,
            RepublicationAudience::Career => PositionRef::of($role, career: $this->audienceId, modality: $this->load->modality_id),
            RepublicationAudience::Department => PositionRef::of($role, unit: $this->audienceId, modality: $this->load->modality_id),
        };
    }

    /**
     * RA-06 con la regla de CV11: el institucional de su ficha o de su usuario; ninguno a una cuenta dada de baja.
     */
    public function mailAddressFor(User $user): ?string
    {
        return $user->is_active ? app(HeadMailAddressQuery::class)->for($user) : null;
    }

    /**
     * La campana en el momento; el correo, en la conexión de cola por omisión.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /**
     * Lo que le cambió a quien lo recibe, una línea por grupo.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(SnapshotDiffText::entry(...), $this->entries);
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: $this->title(),
            body: Str::limit(implode(' ', $this->lines()), 160),
            icon: 'o-arrow-path-rounded-square',
            route: $this->route,
            parameters: $this->parameters,
            termId: $this->load->term_id,
            tone: 'warning',
            modalityId: $this->load->modality_id,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->load->loadMissing(['term', 'modality', 'department']);
        $name = $notifiable instanceof User ? $notifiable->name : $this->recipientLabel;

        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting("Hola, {$name}")
            ->line("Se publicó la versión {$this->version} de la carga de {$this->load->department->name} en {$this->load->modality->name}, {$this->load->term->name}{$this->reasonLabel()}.")
            ->line($this->intro());

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action('Ver lo publicado', route($this->route, $this->parameters));
    }

    /**
     * El título (y el asunto del correo) del aviso al docente; el tablero de versiones lo busca en la campana y en el
     * registro de correos para decir por dónde salió (LoadVersionsQuery::noticeChannels()).
     */
    public static function teacherTitle(string $department): string
    {
        return "Cambiaron tus grupos en la carga de {$department}";
    }

    /**
     * El principio del título (y del asunto del correo) del aviso a la coordinación de una carrera y a los jefes de otro
     * departamento («La carga de <depto> cambió …»); el tablero de versiones lo busca para saber si el aviso ya salió
     * (LoadVersionsQuery::noticeChannels()).
     */
    public static function othersTitlePrefix(string $department): string
    {
        return "La carga de {$department} cambió ";
    }

    private function title(): string
    {
        $department = $this->load->loadMissing('department')->department->name;

        return match ($this->audience) {
            RepublicationAudience::Teacher => self::teacherTitle($department),
            RepublicationAudience::Career => self::othersTitlePrefix($department)."grupos de {$this->recipientLabel}",
            RepublicationAudience::Department => self::othersTitlePrefix($department)."préstamos de salón o grupos compartidos de {$this->recipientLabel}",
        };
    }

    private function intro(): string
    {
        return match ($this->audience) {
            RepublicationAudience::Teacher => 'Esto cambió para ti:',
            RepublicationAudience::Career => "Esto cambió en los grupos con lugares de {$this->recipientLabel}:",
            RepublicationAudience::Department => "Esto cambió en los grupos que usan salones o tienen lugares de {$this->recipientLabel}:",
        };
    }

    private function reasonLabel(): string
    {
        return match ($this->reason) {
            SnapshotReason::Reanalysis => ', después de su re-análisis',
            SnapshotReason::Correction => ', con una corrección',
            default => '',
        };
    }
}
