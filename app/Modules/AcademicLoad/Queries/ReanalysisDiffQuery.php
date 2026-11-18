<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * El diff de demanda del re-análisis de una carga (RA-04, RA-07, RA-09; tarea 8.2): dónde cambió la demanda respecto de lo
 * publicado. Compara la proyección vigente de Demand con la oferta de la última versión publicada de la carga, y no
 * guarda nada: se calcula al leer, así que una proyección que se recalcula a media revisión se refleja sola.
 *
 * La llave es la de la demanda, campus × carrera × materia (el campus es nulo en una modalidad sin salones, MS5), sobre
 * el periodo y la modalidad de la carga y las materias de su departamento. De la proyección salen `expected_students` y
 * `suggested_groups`; de la oferta, los renglones `placements[]` de los grupos del payload (SnapshotPayload v2): la suma
 * de `seats` de esa carrera en los grupos de esa materia y ese campus, y cuántos grupos son. Aparecen las dos mitades de
 * una unión completa: la demanda sin oferta (faltante) y la oferta sin demanda (sobrante).
 *
 * La clase la da `DemandSettings::balanceOf()` con `demand.highlight_threshold` (RA-04). Las materias de primer semestre
 * (método `first_semester`: el nuevo ingreso entra a la proyección, DM-05) se muestran con su `new_students` y sin clase
 * (RA-09); una fila sin proyección cuya materia es de primer semestre en el plan vigente también. Cada fila marca
 * `needsReview` (la materia de especialidad o que nadie cursó, como la propuesta) y `insufficientHistory` (se proyectó con
 * el promedio del departamento o sin historia, el mismo criterio de «sin historia» de la precisión de la proyección).
 *
 * `$careerIds` limita las filas a esas carreras: la pantalla pasa las del coordinador (RA-07). Quién ve qué lo decide
 * quien llama, no esta consulta. Un número fijo de consultas, sin importar cuántas filas: la proyección, la última
 * versión, el semestre de cada materia en el plan vigente y los parámetros.
 */
final class ReanalysisDiffQuery
{
    /**
     * @param  list<string>|null  $careerIds  solo estas carreras; null, todas
     */
    public function forLoad(AcademicLoad $load, ?array $careerIds = null): ReanalysisDiff
    {
        $settings = DemandSettings::fromSettings();
        $forecasts = $this->forecasts($load, $careerIds);
        $snapshot = LoadSnapshot::query()
            ->where('academic_load_id', $load->getKey())
            ->orderByDesc('version')
            ->first(['version', 'payload']);
        $offer = $snapshot === null ? [] : $this->offer(SnapshotPayload::normalize($snapshot->payload), $careerIds);

        $subjectIds = array_values(array_unique([
            ...$forecasts->pluck('subject_id')->all(),
            ...array_map(fn (array $cell): string => $cell['subjectId'], $offer),
        ]));
        $planSemester = $this->planSemesters($subjectIds);

        $rows = [];

        foreach ($forecasts as $forecast) {
            $key = $this->key($forecast->campus_id, $forecast->career_id, $forecast->subject_id);
            $rows[$key] = $this->row($settings, $planSemester, $forecast, $offer[$key] ?? null);
            unset($offer[$key]);
        }

        foreach ($offer as $key => $cell) {
            $rows[$key] = $this->row($settings, $planSemester, null, $cell);
        }

        $rows = array_values($rows);
        usort($rows, fn (ReanalysisRow $a, ReanalysisRow $b): int => [$a->campusCode ?? '', $a->careerCode, $a->subjectCode] <=> [$b->campusCode ?? '', $b->careerCode, $b->subjectCode]);

        return new ReanalysisDiff(
            rows: $rows,
            totals: $this->totals($rows),
            snapshotVersion: $snapshot?->version,
            forecastComputedAt: ($computedAt = $forecasts->max('computed_at')) === null ? null : new CarbonImmutable($computedAt),
        );
    }

    /**
     * La proyección del periodo y la modalidad de la carga para las materias de su departamento, con los códigos y
     * nombres de campus, carrera y materia en la misma consulta.
     *
     * @param  list<string>|null  $careerIds
     * @return Collection<int, DemandForecast>
     */
    private function forecasts(AcademicLoad $load, ?array $careerIds): Collection
    {
        return DemandForecast::query()
            ->join('subjects', 'subjects.id', '=', 'demand_forecasts.subject_id')
            ->join('careers', 'careers.id', '=', 'demand_forecasts.career_id')
            ->leftJoin('campuses', 'campuses.id', '=', 'demand_forecasts.campus_id')
            ->where('demand_forecasts.term_id', $load->term_id)
            ->where('demand_forecasts.modality_id', $load->modality_id)
            ->where('subjects.department_id', $load->department_id)
            ->when($careerIds !== null, fn ($query) => $query->whereIn('demand_forecasts.career_id', $careerIds))
            ->select([
                'demand_forecasts.*',
                'campuses.code as campus_code', 'campuses.name as campus_name',
                'careers.code as career_code', 'careers.name as career_name',
                'subjects.code as subject_code', 'subjects.name as subject_name',
            ])
            ->get();
    }

    /**
     * La oferta de la versión por llave: asientos y grupos de cada carrera, materia y campus. Un grupo cuenta una vez por
     * carrera aunque ocupe varios renglones de ella (dos retículas).
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $careerIds
     * @return array<string, array{campus: ?array<string, mixed>, career: array<string, mixed>, subject: array<string, mixed>, semester: ?int, seats: int, groups: int}>
     */
    private function offer(array $payload, ?array $careerIds): array
    {
        $offer = [];

        foreach ($payload['groups'] as $group) {
            $groupCareers = [];

            foreach ($group['placements'] as $placement) {
                $career = $placement['career'];

                if ($careerIds !== null && ! in_array($career['id'], $careerIds, true)) {
                    continue;
                }

                $key = $this->key($group['campus']['id'] ?? null, $career['id'], $group['subject']['id']);
                $offer[$key] ??= ['campus' => $group['campus'], 'career' => $career, 'subject' => $group['subject'], 'semester' => null, 'seats' => 0, 'groups' => 0];
                $offer[$key]['seats'] += $placement['seats'];
                $offer[$key]['semester'] ??= $placement['semester'];

                if (! isset($groupCareers[$key])) {
                    $groupCareers[$key] = true;
                    $offer[$key]['groups']++;
                }
            }
        }

        return array_map(fn (array $cell): array => $cell + ['subjectId' => $cell['subject']['id']], $offer);
    }

    /**
     * El semestre en que el plan vigente de cada carrera pone la materia, por llave carrera|materia (DM-05).
     *
     * @param  list<string>  $subjectIds
     * @return array<string, int>
     */
    private function planSemesters(array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        return StudyPlanSubject::query()
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->where('study_plans.is_current', true)
            ->whereNull('study_plans.deleted_at')
            ->whereIn('study_plan_subjects.subject_id', $subjectIds)
            ->toBase()
            ->get(['study_plans.career_id', 'study_plan_subjects.subject_id', 'study_plan_subjects.semester'])
            ->mapWithKeys(fn (object $row): array => ["{$row->career_id}|{$row->subject_id}" => (int) $row->semester])
            ->all();
    }

    /**
     * @param  array<string, int>  $planSemester
     * @param  array{campus: ?array<string, mixed>, career: array<string, mixed>, subject: array<string, mixed>, semester: ?int, seats: int, groups: int}|null  $offer
     */
    private function row(DemandSettings $settings, array $planSemester, ?DemandForecast $forecast, ?array $offer): ReanalysisRow
    {
        $careerId = $forecast?->career_id ?? $offer['career']['id'];
        $subjectId = $forecast?->subject_id ?? $offer['subject']['id'];
        $semester = $planSemester["{$careerId}|{$subjectId}"] ?? $offer['semester'] ?? null;
        $expected = $forecast?->expected_students ?? 0;
        $seats = $offer['seats'] ?? 0;
        $firstSemester = $forecast === null ? $semester === 1 : $forecast->method === ForecastMethod::FirstSemester;

        return new ReanalysisRow(
            campusId: $forecast?->campus_id ?? $offer['campus']['id'] ?? null,
            campusCode: $forecast === null ? ($offer['campus']['code'] ?? null) : $forecast->campus_code,
            campusName: $forecast === null ? ($offer['campus']['name'] ?? null) : $forecast->campus_name,
            careerId: $careerId,
            careerCode: $forecast?->career_code ?? $offer['career']['code'],
            careerName: $forecast?->career_name ?? $offer['career']['name'],
            subjectId: $subjectId,
            subjectCode: $forecast?->subject_code ?? $offer['subject']['code'],
            subjectName: $forecast?->subject_name ?? $offer['subject']['name'],
            semester: $semester,
            forecasted: $forecast !== null,
            expectedStudents: $expected,
            suggestedGroups: $forecast?->suggested_groups ?? 0,
            newStudents: $forecast?->new_students,
            offeredSeats: $seats,
            offeredGroups: $offer['groups'] ?? 0,
            isFirstSemester: $firstSemester,
            balance: $firstSemester ? null : $settings->balanceOf($expected, $seats),
            needsReview: $forecast?->needs_review ?? false,
            insufficientHistory: $forecast !== null && in_array($forecast->method, [ForecastMethod::DepartmentFallback, ForecastMethod::NoHistory], true),
            computedAt: $forecast?->computed_at,
        );
    }

    /**
     * @param  list<ReanalysisRow>  $rows
     * @return array{shortfall: int, surplus: int, adjusted: int, unclassified: int}
     */
    private function totals(array $rows): array
    {
        $totals = ['shortfall' => 0, 'surplus' => 0, 'adjusted' => 0, 'unclassified' => 0];

        foreach ($rows as $row) {
            $totals[$row->balance?->value ?? 'unclassified']++;
        }

        return $totals;
    }

    private function key(?string $campusId, string $careerId, string $subjectId): string
    {
        return ($campusId ?? '')."|{$careerId}|{$subjectId}";
    }
}
