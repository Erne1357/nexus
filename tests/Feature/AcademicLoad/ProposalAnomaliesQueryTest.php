<?php

use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;

/*
| La propuesta marca sus filas raras sin bloquear el envío (confirmado el 2 de noviembre de 2026), con el mínimo de
| alumnos por grupo del parámetro global `demand.min_group_size` (10 por omisión, supuesto por confirmar).
*/

it('da las anomalías de cada fila con el mínimo por omisión', function () {
    $proposal = CareerProposal::factory()->create();
    $normal = ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'eligible_students' => 70, 'expected_students' => 70, 'requested_groups' => 2]);
    $odd = ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'eligible_students' => 98, 'expected_students' => 105, 'requested_groups' => 3]);
    $tiny = ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'eligible_students' => 9, 'expected_students' => 2, 'requested_groups' => 1]);

    $overview = app(CareerProposalQuery::class)->overview($proposal);

    expect($overview->anomaliesFor($normal))->toBe([])
        ->and($overview->anomaliesFor($odd))->toBe(['105 esperados con 98 elegibles'])
        ->and($overview->anomaliesFor($tiny))->toBe(['1 grupo para 2 alumnos']);
});

it('usa el mínimo del parámetro global si existe', function () {
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => ProposalAnomalies::SETTING, 'value' => 25]);
    $proposal = CareerProposal::factory()->create();
    $item = ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'eligible_students' => 40, 'expected_students' => 20, 'requested_groups' => 1]);

    expect(app(CareerProposalQuery::class)->overview($proposal)->anomaliesFor($item))->toBe(['1 grupo para 20 alumnos']);
});

it('con proyección vigente compara los esperados con lo que explica: posibles, pendientes de seriación y nuevo ingreso', function () {
    $proposal = CareerProposal::factory()->create();
    // Primer semestre: 49 posibles, 13 por seriación y 0 de nuevo ingreso explican 62; 90 ya no.
    $explained = ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'eligible_students' => 49, 'expected_students' => 62, 'requested_groups' => 2]);
    $beyond = ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'eligible_students' => 49, 'expected_students' => 90, 'requested_groups' => 3]);
    $firstSemester = ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'eligible_students' => 20, 'expected_students' => 64, 'requested_groups' => 2]);

    foreach ([[$explained, 13, null, ForecastMethod::Formula], [$beyond, 13, null, ForecastMethod::Formula], [$firstSemester, 0, 44, ForecastMethod::FirstSemester]] as [$item, $pending, $new, $method]) {
        DemandForecast::factory()->create([
            'term_id' => $proposal->term_id,
            'modality_id' => $proposal->modality_id,
            'career_id' => $proposal->career_id,
            'subject_id' => $item->subject_id,
            'campus_id' => $item->campus_id,
            'pending_prerequisite_students' => $pending,
            'new_students' => $new,
            'method' => $method,
        ]);
    }

    $overview = app(CareerProposalQuery::class)->overview($proposal);

    expect($overview->anomaliesFor($explained))->toBe([])
        ->and($overview->anomaliesFor($beyond))->toBe(['90 esperados con 62 que explica la proyección'])
        ->and($overview->anomaliesFor($firstSemester))->toBe([]);
});
