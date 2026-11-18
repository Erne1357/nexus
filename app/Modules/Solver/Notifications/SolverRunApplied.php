<?php

namespace App\Modules\Solver\Notifications;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Solver\Enums\SolverPermission;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Support\Str;

/**
 * «La corrida del motor para Sistemas se aplicó» o «… no se pudo aplicar» (tarea 6.10, SV-05): a quien la aceptó, cuando
 * ApplySolverRunJob termina de escribir el horario o la regresa a `done` con su error. Lleva a la pantalla de corridas
 * con esa corrida elegida.
 */
final class SolverRunApplied extends NexusNotification
{
    /**
     * @param  array{groups: int, sessions: int}|null  $summary  el resumen de ApplySolverRun; nulo si falló
     */
    public function __construct(private readonly SolverRun $run, private readonly ?array $summary = null, private readonly ?string $error = null) {}

    public static function typeKey(): string
    {
        return 'solver-run-applied';
    }

    public static function typeLabel(): string
    {
        return 'Corrida aplicada';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $failed = $this->error !== null;
        $scope = $this->run->department->name ?? '';

        return new NotificationMessage(
            title: $failed ? "No se pudo aplicar la corrida del motor para {$scope}" : "La corrida del motor para {$scope} se aplicó",
            body: Str::limit($failed ? $this->error : "{$this->summary['sessions']} sesiones escritas en {$this->summary['groups']} grupos; choques recalculados.", 160),
            icon: $failed ? 'o-exclamation-triangle' : 'o-check-circle',
            route: 'solver.runs',
            parameters: ['corrida' => $this->run->getKey()],
            termId: $this->run->term_id,
            tone: $failed ? 'warning' : 'success',
            modalityId: $this->run->modality_id,
        );
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        if (KeyLookup::find(SolverRun::query(), $message->parameters['corrida'] ?? null) === null) {
            return 'Esa corrida ya no existe.';
        }

        if (! $context->allowsAny(SolverPermission::DepartmentRunView)) {
            return 'Ya no puedes ver las corridas del motor.';
        }

        return parent::unavailableReason($context, $message);
    }
}
