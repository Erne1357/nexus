<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Queries\ForecastEvaluationsQuery;
use App\Modules\AcademicLoad\Queries\SubjectGroupHistoryQuery;
use App\Modules\AcademicLoad\Queries\TermGroupsQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;

/*
| Los grupos abiertos de verdad de un periodo (tarea 5.9, DM-27): grupos físicos por materia y campus; lo de Nexus gana
| al SII en el mismo periodo; el indicador global de la pantalla no los multiplica por carrera.
*/

beforeEach(function () {
    $this->modality = Modality::query()->where('code', 'PRES')->firstOrFail();
    $this->subject = Subject::factory()->create();
    $this->campus = Campus::factory()->create();
});

it('DM-27: cuenta solo los grupos físicos, no los paralelos', function () {
    $parent = HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'campus_id' => $this->campus->id, 'group_code' => 'A']);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'campus_id' => $this->campus->id, 'group_code' => 'A2', 'parallel_of' => $parent->id]);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'campus_id' => $this->campus->id, 'group_code' => 'B']);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'campus_id' => null, 'group_code' => 'C']);

    expect((new TermGroupsQuery)->forTerm('20261', $this->modality))
        ->toBe([$this->subject->id => [$this->campus->id => 2, SubjectGroupHistoryQuery::NO_CAMPUS => 1]]);
});

it('DM-27: prefiere los renglones de Nexus a los del SII en el mismo periodo, sin sumarlos', function () {
    $other = Subject::factory()->create();
    HistoricalGroup::factory()->fromSii()->count(3)->create(['subject_id' => $this->subject->id, 'campus_id' => $this->campus->id]);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $other->id, 'campus_id' => $this->campus->id]);
    HistoricalGroup::factory()->fromNexus()->count(2)->create(['subject_id' => $this->subject->id, 'campus_id' => $this->campus->id]);

    expect((new TermGroupsQuery)->forTerm('20261', $this->modality))
        ->toBe([$this->subject->id => [$this->campus->id => 2]]);
});

it('DM-27: no mezcla otro periodo ni otro campus del pedido', function () {
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'campus_id' => $this->campus->id, 'term_code' => '20253']);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'campus_id' => Campus::factory()->create()->id]);

    expect((new TermGroupsQuery)->forTerm('20261', $this->modality, $this->campus))->toBe([])
        ->and((new TermGroupsQuery)->forTerm('20253', $this->modality, $this->campus))->toBe([$this->subject->id => [$this->campus->id => 1]]);
});

it('DM-27: la fuente se elige por periodo sin mirar el campus: con nexus solo en C1 y sii en C2, C2 da 0', function () {
    $c2 = Campus::factory()->create();
    HistoricalGroup::factory()->fromNexus()->create(['subject_id' => $this->subject->id, 'campus_id' => $this->campus->id]);
    HistoricalGroup::factory()->fromSii()->count(2)->create(['subject_id' => $this->subject->id, 'campus_id' => $c2->id]);

    expect((new TermGroupsQuery)->forTerm('20261', $this->modality, $c2))->toBe([])
        ->and((new TermGroupsQuery)->forTerm('20261', $this->modality, $this->campus))->toBe([$this->subject->id => [$this->campus->id => 1]]);
});

it('DM-27: el indicador global suma los grupos abiertos una vez por materia y campus, sin multiplicar por carreras', function () {
    $evaluation = ForecastEvaluation::factory()->create();
    $other = Subject::factory()->create();

    foreach ([[$this->subject, 3], [$other, 1]] as [$subject, $groups]) {
        foreach (Career::factory()->count(2)->create() as $career) {
            ForecastEvaluationRow::factory()->create([
                'evaluation_id' => $evaluation->id,
                'career_id' => $career->id,
                'campus_id' => $this->campus->id,
                'subject_id' => $subject->id,
                'actual_groups' => $groups,
            ]);
        }
    }

    $report = app(ForecastEvaluationsQuery::class)->report($evaluation);

    expect($report->counts['openedGroups'])->toBe(4);

    $evaluation->rows()->update(['actual_groups' => null]);

    expect(app(ForecastEvaluationsQuery::class)->report($evaluation)->counts['openedGroups'])->toBeNull();
});
