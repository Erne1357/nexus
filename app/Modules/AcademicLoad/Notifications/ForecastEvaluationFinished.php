<?php

namespace App\Modules\AcademicLoad\Notifications;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\ForecastConfidence;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Support\Str;

/**
 * «La evaluación de Ene-Jun 2026 está lista» o «… no terminó» (tarea 5.9b): a quien pidió la evaluación de la
 * proyección, al terminar RunForecastEvaluation. Lista, dice el error ponderado global y la confianza; fallida, el
 * error. Lleva a «Precisión de la proyección» con esa evaluación elegida (`?evaluacion=<id>`), sin mover la credencial:
 * el periodo evaluado es uno ya cursado. Deja de poder abrirse si la evaluación ya no existe o el usuario ya no ve el
 * reporte (DM-24).
 */
final class ForecastEvaluationFinished extends NexusNotification
{
    public function __construct(private readonly ForecastEvaluation $evaluation) {}

    public static function typeKey(): string
    {
        return 'forecast-evaluation-finished';
    }

    public static function typeLabel(): string
    {
        return 'Evaluación de la proyección';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $failed = $this->evaluation->status === ForecastEvaluationStatus::Failed;
        $term = $this->evaluation->term->name;

        return new NotificationMessage(
            title: $failed ? "La evaluación de {$term} no terminó" : "La evaluación de {$term} está lista",
            body: Str::limit($failed ? (string) $this->evaluation->error : $this->summary(), 160),
            icon: $failed ? 'o-exclamation-triangle' : 'o-check-circle',
            route: 'academic-load.forecast-accuracy',
            parameters: ['evaluacion' => $this->evaluation->getKey()],
            tone: $failed ? 'warning' : 'success',
        );
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        if (KeyLookup::find(ForecastEvaluation::query(), $message->parameters['evaluacion'] ?? null) === null) {
            return 'Esa evaluación ya no existe.';
        }

        if (! $context->allowsAny(AcademicLoadPermission::ForecastEvaluationView)) {
            return 'Ya no puedes ver la precisión de la proyección.';
        }

        return parent::unavailableReason($context, $message);
    }

    /**
     * «Presencial, todos los campus: error ponderado 12.4 %, Sugerencia fuerte».
     */
    private function summary(): string
    {
        $overall = $this->evaluation->totals['overall'] ?? [];
        $error = $overall['weighted_error'] ?? null;
        $confidence = ForecastConfidence::tryFrom((string) ($overall['confidence'] ?? '')) ?? ForecastConfidence::Unmeasured;
        $where = $this->evaluation->modality->name.', '.($this->evaluation->campus->name ?? 'todos los campus');

        return $error === null
            ? "{$where}: {$confidence->label()}"
            : "{$where}: error ponderado ".number_format($error * 100, 1).' %, '.$confidence->label();
    }
}
