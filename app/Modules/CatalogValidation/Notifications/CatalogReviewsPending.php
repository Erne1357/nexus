<?php

namespace App\Modules\CatalogValidation\Notifications;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Queries\HeadMailAddressQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\RoutesMailAddress;
use App\Modules\Core\Support\StageTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * «Tienes catálogos por validar» (tarea 2.9) y su recordatorio (tarea 2.5): al jefe de un departamento, con los catálogos
 * que le faltan del periodo. Lleva a la validación de catálogos de ese departamento (tarea 2.4), con la credencial en el
 * periodo del aviso; no lleva campus, porque la validación es de todo el Tec (decisión 6). Deja de poder abrirse si el
 * departamento se borró o el usuario ya no es su jefe.
 *
 * CV11 (D3 y D6, confirmados el 14 de noviembre de 2026): los dos dicen la fecha límite, si la etapa tiene ventana
 * (ValidationWindowQuery), y llegan también al correo del jefe. El recordatorio tiene su propio título y dice cuánto
 * lleva revisado de cada catálogo que falta (`$progress`, CV7). El correo va al institucional de la ficha de docente del
 * jefe y, si no tiene, al de su usuario si es institucional; nunca al personal, y sin ninguno, solo la campana
 * (HeadMailAddressQuery; CV11, confirmado el 15 de noviembre de 2026). La misma dirección decide el canal y recibe el
 * correo (RoutesMailAddress). Sale de la cola, después del commit; la campana se guarda en el momento
 * (`viaConnections`, conexión `sync`).
 */
final class CatalogReviewsPending extends NexusNotification implements RoutesMailAddress, ShouldQueue
{
    use Queueable;

    public static function typeKey(): string
    {
        return 'catalog-reviews-pending';
    }

    public static function typeLabel(): string
    {
        return 'Catálogos por validar';
    }

    /**
     * @param  list<Catalog>  $pending
     * @param  array<string, array{reviewed: int, total: int}>  $progress  por catálogo (`Catalog->value`), solo en el recordatorio
     */
    public function __construct(
        private readonly Term $term,
        private readonly Department $department,
        private readonly array $pending,
        private readonly ?CarbonImmutable $deadline = null,
        private readonly bool $reminder = false,
        private readonly array $progress = [],
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $this->mailAddressFor($notifiable) !== null ? ['database', 'mail'] : ['database'];
    }

    /**
     * CV11: el institucional de la ficha del jefe, el institucional de su usuario o ninguno.
     */
    public function mailAddressFor(User $user): ?string
    {
        return app(HeadMailAddressQuery::class)->for($user);
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

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: $this->title(),
            body: "{$this->term->name} · {$this->department->name}: {$this->catalogList()}".($this->deadline === null ? '' : ' · fecha límite '.StageTimeline::dateLabel($this->deadline)),
            icon: 'o-clipboard-document-check',
            route: 'catalog-validation.review',
            parameters: ['department' => $this->department->getKey()],
            termId: $this->term->getKey(),
            tone: 'warning',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting("Hola, {$notifiable->name}")
            ->line($this->reminder
                ? "En {$this->department->name} te falta validar en {$this->term->name}:"
                : "Se abrió la validación de catálogos de {$this->term->name}. En {$this->department->name} te falta validar:");

        foreach ($this->ordered() as $catalog) {
            $mail->line($catalog->label().($this->reminder ? ': '.$this->progressLabel($catalog) : ''));
        }

        if ($this->deadline !== null) {
            $mail->line('La fecha límite es el '.$this->deadline->translatedFormat('j \d\e F').'.');
        }

        return $mail
            ->action('Ir a validar', route('catalog-validation.review', ['department' => $this->department->getKey()]))
            ->line('Revisa cada registro y valida el catálogo cuando esté completo.');
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        $department = Department::query()->find($message->parameters['department'] ?? null);

        if ($department === null) {
            return 'Ese departamento ya no existe.';
        }

        if (! $context->allows(CatalogValidationPermission::ReviewValidate, Target::of(unit: $department->getKey()))) {
            return "Ya no eres jefe de {$department->name}.";
        }

        return parent::unavailableReason($context, $message);
    }

    private function title(): string
    {
        return $this->reminder ? 'Recordatorio: te faltan catálogos por validar' : 'Tienes catálogos por validar';
    }

    /**
     * «docentes, materias, planes de estudio, carreras y salones»; en el recordatorio, cada uno con cuánto lleva
     * revisado («docentes (12 de 77 revisados)»).
     */
    private function catalogList(): string
    {
        $names = array_map(fn (Catalog $catalog): string => mb_strtolower($catalog->label()).($this->reminder ? " ({$this->progressLabel($catalog)})" : ''), $this->ordered());

        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' y '.$last;
    }

    /**
     * «12 de 77 revisados», o «sin registros» si el catálogo no tiene registros activos.
     */
    private function progressLabel(Catalog $catalog): string
    {
        $progress = $this->progress[$catalog->value] ?? ['reviewed' => 0, 'total' => 0];

        return $progress['total'] === 0 ? 'sin registros' : "{$progress['reviewed']} de {$progress['total']} revisados";
    }

    /**
     * Los catálogos en el orden del enum, con los salones al final.
     *
     * @return list<Catalog>
     */
    private function ordered(): array
    {
        return [...array_filter($this->pending, fn (Catalog $catalog): bool => $catalog !== Catalog::Rooms), ...array_filter($this->pending, fn (Catalog $catalog): bool => $catalog === Catalog::Rooms)];
    }
}
