<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;

/**
 * Deja activas solo las especialidades vigentes de cada plan (1.69; reglas-de-negocio.md, "Especialidades").
 * «Vigente» es la que tiene el `periodo_inicio` más reciente del plan según el catálogo de especialidades del SII,
 * y no está cerrada con el periodo de término `20001`; las demás especialidades del plan que el SII lista quedan
 * inactivas. Una especialidad de Nexus que el SII no lista no se toca y se informa. Confirmado el 26 de
 * noviembre de 2026: las más recientes activas y las demás inactivas.
 *
 * Cada cambio pasa por UpdateSpecialty (transacción, bitácora y evento), así que es idempotente: una segunda corrida
 * no cambia nada. Con `$dryRun` solo cuenta. No sabe leer el SII: recibe los renglones ya resueltos a la clave de
 * su plan de Nexus (los junta el comando `academic:mark-current-specialties`).
 */
final class MarkCurrentSpecialties
{
    /** Periodo de término que el SII pone a las especialidades cerradas. */
    public const string CLOSED_END = '20001';

    public function __construct(private readonly UpdateSpecialty $update) {}

    /**
     * @param  list<array{plan: string, code: string, start: string, end: string}>  $siiRows  clave del plan de Nexus, clave de la especialidad y sus periodos del SII
     * @return array{plans: array<string, array{current: string, activated: int, deactivated: int, unchanged: int}>, unmatched: list<string>, unknownPlans: list<string>}
     */
    public function handle(array $siiRows, bool $dryRun = false): array
    {
        /** @var array<string, array<string, array{start: int, end: string}>> $byPlan */
        $byPlan = [];

        foreach ($siiRows as $row) {
            $start = (int) $row['start'];
            $code = trim($row['code']);
            $known = $byPlan[trim($row['plan'])][$code] ?? null;

            if ($known === null || $start > $known['start']) {
                $byPlan[trim($row['plan'])][$code] = ['start' => $start, 'end' => trim($row['end'])];
            }
        }

        $plans = [];
        $unmatched = [];
        $unknownPlans = [];

        ksort($byPlan);

        foreach ($byPlan as $planCode => $codes) {
            $plan = StudyPlan::query()->where('code', $planCode)->first();

            if ($plan === null) {
                $unknownPlans[] = $planCode;

                continue;
            }

            $newest = max(array_column($codes, 'start'));
            $summary = ['current' => (string) $newest, 'activated' => 0, 'deactivated' => 0, 'unchanged' => 0];

            foreach (Specialty::query()->where('study_plan_id', $plan->getKey())->orderBy('code')->get() as $specialty) {
                $sii = $codes[$specialty->code] ?? null;

                if ($sii === null) {
                    $unmatched[] = "{$planCode}/{$specialty->code}";

                    continue;
                }

                $current = $sii['start'] === $newest && $sii['end'] !== self::CLOSED_END;

                if ($specialty->is_active === $current) {
                    $summary['unchanged']++;

                    continue;
                }

                $summary[$current ? 'activated' : 'deactivated']++;

                if (! $dryRun) {
                    $this->update->handle($specialty, ['is_active' => $current]);
                }
            }

            $plans[$planCode] = $summary;
        }

        return ['plans' => $plans, 'unmatched' => $unmatched, 'unknownPlans' => $unknownPlans];
    }
}
