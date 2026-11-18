<?php

namespace App\Modules\Solver\Notifications;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Solver\Enums\SolverPermission;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Support\Str;

/**
 * «La corrida del motor para Sistemas está lista» o «… no terminó» (tarea 6.8): a quien lanzó la corrida, al terminar
 * RunSolver o cuando LaunchSolverRun da por perdida una que no se movió (SV-01). Lista, dice cuántos grupos quedaron sin
 * acomodar (del resumen de `metrics`); fallida, el error. Lleva a la pantalla de corridas con esa corrida elegida
 * (`solver.runs?corrida=<id>`, tarea 6.10) en el periodo y la modalidad de la corrida. Mientras esa pantalla no exista,
 * la campana dice que no está disponible (NexusNotification); tampoco se abre si la corrida ya no existe o el usuario ya
 * no ve corridas.
 */
final class SolverRunFinished extends NexusNotification
{
    public function __construct(private readonly SolverRun $run) {}

    public static function typeKey(): string
    {
        return 'solver-run-finished';
    }

    public static function typeLabel(): string
    {
        return 'Corrida del motor';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $failed = $this->run->status === SolverRunStatus::Failed;
        $scope = $this->run->department->name ?? $this->run->career->name ?? '';

        return new NotificationMessage(
            title: $failed ? "La corrida del motor para {$scope} no terminó" : "La corrida del motor para {$scope} está lista",
            body: Str::limit($failed ? (string) $this->run->error : $this->summary(), 160),
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

        if (! $context->allowsAny(SolverPermission::DepartmentRunView) && ! $context->allowsAny(SolverPermission::CareerRunView)) {
            return 'Ya no puedes ver las corridas del motor.';
        }

        return parent::unavailableReason($context, $message);
    }

    /**
     * «Presencial, Ene-Jun 2027: 3 grupos sin acomodar.»
     */
    private function summary(): string
    {
        $unscheduled = (int) ($this->run->metrics['unscheduled'] ?? 0);
        $where = "{$this->run->modality->name}, {$this->run->term->name}";

        return match ($unscheduled) {
            0 => "{$where}: todos los grupos quedaron acomodados.",
            1 => "{$where}: 1 grupo sin acomodar.",
            default => "{$where}: {$unscheduled} grupos sin acomodar.",
        };
    }
}
