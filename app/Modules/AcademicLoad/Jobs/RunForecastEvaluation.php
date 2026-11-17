<?php

namespace App\Modules\AcademicLoad\Jobs;

use App\Modules\AcademicLoad\Actions\EvaluateForecast;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Notifications\ForecastEvaluationFinished;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Corre en la cola una evaluación de la proyección que pidió RequestForecastEvaluation (tarea 5.9b; DM-25 y DM-28): la
 * pasa de cola a corriendo, la evalúa (EvaluateForecast) y la deja lista; si algo lanza, failed() la deja fallida con
 * el mensaje, sin la traza. En los dos casos avisa en la campana a quien la pidió (ForecastEvaluationFinished).
 *
 * Único por periodo, modalidad y campus mientras espera o corre (Review Focus 5): dos evaluaciones del mismo alcance no
 * se encolan dos veces. Además solo corre una evaluación que sigue en cola, y la toma con una actualización condicional,
 * así que un job repetido o uno de una evaluación que ya se dio por perdida no hace nada. Un solo intento: si falla, se
 * vuelve a pedir. `timeout` queda por debajo del `retry_after` de la conexión (config/queue.php).
 */
final class RunForecastEvaluation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Una evaluación en cola o corriendo que no se movió en este tiempo se da por perdida (RequestForecastEvaluation). */
    public const int STALE_AFTER = 3600;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = self::STALE_AFTER;

    /** El alcance de la evaluación, que se lee una vez, al encolarla, y viaja con el job para soltar el candado. */
    private ?string $scope = null;

    public function __construct(public readonly string $evaluationId) {}

    /**
     * La llave del alcance de una evaluación: periodo, modalidad y campus (`all` sin campus).
     */
    public static function scopeKey(string $termId, string $modalityId, ?string $campusId): string
    {
        return implode('|', [$termId, $modalityId, $campusId ?? 'all']);
    }

    public function uniqueId(): string
    {
        if ($this->scope === null) {
            $evaluation = ForecastEvaluation::query()->whereKey($this->evaluationId)->first(['term_id', 'modality_id', 'campus_id']);
            $this->scope = $evaluation === null ? "missing|{$this->evaluationId}" : self::scopeKey($evaluation->term_id, $evaluation->modality_id, $evaluation->campus_id);
        }

        return $this->scope;
    }

    public function handle(EvaluateForecast $evaluate): void
    {
        $claimed = ForecastEvaluation::query()
            ->whereKey($this->evaluationId)
            ->where('status', ForecastEvaluationStatus::Queued)
            ->update(['status' => ForecastEvaluationStatus::Running, 'started_at' => now(), 'progress' => 0, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $evaluation = ForecastEvaluation::query()->findOrFail($this->evaluationId);
        $evaluate->run($evaluation);

        $evaluation->forceFill([
            'status' => ForecastEvaluationStatus::Done,
            'finished_at' => now(),
            'progress' => 100,
            'stage' => null,
        ])->save();

        $evaluation->requester?->notify(new ForecastEvaluationFinished($evaluation));
    }

    /**
     * Deja fallida la evaluación que corría (o que no llegó a correr) con el mensaje del error, y avisa a quien la pidió.
     * Una ya lista o ya fallida no se toca.
     */
    public function failed(?Throwable $exception): void
    {
        $evaluation = ForecastEvaluation::query()->whereKey($this->evaluationId)->first();

        if ($evaluation === null || in_array($evaluation->status, [ForecastEvaluationStatus::Done, ForecastEvaluationStatus::Failed], true)) {
            return;
        }

        $message = trim((string) $exception?->getMessage());

        $evaluation->forceFill([
            'status' => ForecastEvaluationStatus::Failed,
            'finished_at' => now(),
            'error' => Str::limit($message === '' ? 'La evaluación no terminó.' : $message, 1000),
        ])->save();

        $evaluation->requester?->notify(new ForecastEvaluationFinished($evaluation));
    }
}
