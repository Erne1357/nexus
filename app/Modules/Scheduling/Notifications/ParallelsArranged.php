<?php

namespace App\Modules\Scheduling\Notifications;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Support\Str;

/**
 * «Ciencias Básicas armó los paralelos de ACF-0902 Cálculo Integral en C1» (tarea 8.8): a los coordinadores y al jefe de una
 * carrera cuyos lugares cambiaron, con sus lugares en cada grupo antes y después («ISIC: 30 lugares en el grupo 1. Antes: 35
 * en el 1 y 35 en el 2.»). Lleva al editor de horarios con el grupo donde le quedan más lugares (`?grupo=`, que abre la
 * carrera, el plan y el semestre que el usuario ve); sin lugares, al editor. Deja de poder abrirse si ese grupo ya no
 * existe o se canceló, o si el usuario ya no ve horarios.
 */
final class ParallelsArranged extends NexusNotification
{
    public static function typeKey(): string
    {
        return 'parallels-arranged';
    }

    public static function typeLabel(): string
    {
        return 'Paralelos armados';
    }

    /**
     * @param  array<string, int>  $before  lugares de la carrera por clave de grupo, antes
     * @param  array<string, int>  $after  y después
     */
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly string $department,
        private readonly string $subject,
        private readonly string $campus,
        private readonly string $career,
        private readonly array $before,
        private readonly array $after,
        private readonly ?string $groupId,
    ) {}

    public function message(object $notifiable): NotificationMessage
    {
        $now = $this->after === [] ? "{$this->career} se queda sin lugares." : "{$this->career}: ".self::places($this->after, long: true).'.';
        $before = $this->before === [] ? '' : ' Antes: '.self::places($this->before).'.';

        return new NotificationMessage(
            title: "{$this->department} armó los paralelos de {$this->subject} en {$this->campus}",
            body: Str::limit($now.$before, 160),
            icon: 'o-arrows-pointing-in',
            route: 'scheduling.editor',
            parameters: $this->groupId === null ? [] : ['grupo' => $this->groupId],
            termId: $this->termId,
            tone: 'info',
            modalityId: $this->modalityId,
        );
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        if (isset($message->parameters['grupo'])) {
            $group = KeyLookup::find(Group::query(), $message->parameters['grupo']);

            if ($group === null) {
                return 'Ese grupo ya no existe.';
            }

            if ($group->status === GroupStatus::Cancelled) {
                return 'Ese grupo se canceló.';
            }
        }

        if (! $context->allowsAny(SchedulingPermission::ScheduleView)) {
            return 'Ya no ves los horarios.';
        }

        return parent::unavailableReason($context, $message);
    }

    /**
     * «35 lugares en el grupo 1, 35 en el 2 y 7 en el 5» (`long`) o «35 en el 1 y 35 en el 2».
     *
     * @param  array<string, int>  $seats
     */
    private static function places(array $seats, bool $long = false): string
    {
        $parts = [];

        foreach ($seats as $code => $count) {
            $parts[] = $long && $parts === [] ? ($count === 1 ? '1 lugar' : "{$count} lugares")." en el grupo {$code}" : "{$count} en el {$code}";
        }

        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts)." y {$last}";
    }
}
