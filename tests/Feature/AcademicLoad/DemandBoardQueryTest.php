<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\DemandProposalState;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Queries\DemandBoard;
use App\Modules\AcademicLoad\Queries\DemandBoardQuery;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;

/*
| «Demanda por materia» (tarea 5.7): lo que proyecta Demand contra lo que piden las propuestas de carrera, de todo el Tec,
| por materia, carrera y campus. Una carrera sin propuesta enviada cuenta lo sugerido (supuesto por confirmar); la lente
| del departamento son las materias que imparte y la de la carrera, las que lleva en cualquier departamento.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->modality = modalityOf('PRES');
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->isic = Career::factory()->create(['code' => 'ISIC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $this->itic = Career::factory()->create(['code' => 'ITIC', 'name' => 'Ingeniería en Tecnologías de la Información']);
    $this->ige = Career::factory()->create(['code' => 'IGE', 'name' => 'Ingeniería en Gestión Empresarial']);
    $this->topics = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación', 'department_id' => $this->sc->id, 'needs_lab' => true, 'theory_hours' => 2, 'practice_hours' => 3]);
    $this->web = Subject::factory()->create(['code' => 'AEB-1055', 'name' => 'Programación Web', 'department_id' => $this->sc->id, 'theory_hours' => 1, 'practice_hours' => 4]);
    $this->calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $this->cb->id, 'theory_hours' => 3, 'practice_hours' => 2]);
    $this->settings = new DemandSettings(highlightThreshold: 20);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function demandBoardForecast(Career $career, Subject $subject, ?Campus $campus, int $suggested, array $attributes = []): DemandForecast
{
    return DemandForecast::factory()->create([
        'term_id' => test()->term->id,
        'modality_id' => test()->modality->id,
        'career_id' => $career->id,
        'subject_id' => $subject->id,
        'campus_id' => $campus?->id,
        'eligible_students' => $suggested * 30,
        'expected_students' => $suggested * 28,
        'suggested_groups' => $suggested,
        ...$attributes,
    ]);
}

function demandBoardProposal(Career $career, ProposalStatus $status): CareerProposal
{
    return CareerProposal::factory()->inStatus($status)->create(['term_id' => test()->term->id, 'modality_id' => test()->modality->id, 'career_id' => $career->id]);
}

function demandBoardItem(CareerProposal $proposal, Subject $subject, ?Campus $campus, int $groups, int $semester = 4): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => $proposal->id,
        'subject_id' => $subject->id,
        'campus_id' => $campus?->id,
        'requested_groups' => $groups,
        'semester' => $semester,
    ]);
}

function demandBoardOf(Department $department): DemandBoard
{
    return app(DemandBoardQuery::class)->forDepartment(test()->term, test()->modality, $department, test()->settings);
}

it('agrega por materia, carrera y campus lo sugerido y lo pedido en las propuestas enviadas', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->isic, $this->topics, $this->c2, 1);
    demandBoardForecast($this->itic, $this->topics, $this->c1, 2);
    $isic = demandBoardProposal($this->isic, ProposalStatus::Submitted);
    demandBoardItem($isic, $this->topics, $this->c1, 3);
    demandBoardItem($isic, $this->topics, $this->c2, 1);
    demandBoardItem(demandBoardProposal($this->itic, ProposalStatus::Accepted), $this->topics, $this->c1, 2);

    $board = demandBoardOf($this->sc);
    $topics = $board->subject('SCD-1027');

    expect(array_map(fn (array $column): string => $column['career']->code, $board->careers))->toBe(['ISIC', 'ITIC'])
        ->and(array_map(fn (array $column): DemandProposalState => $column['state'], $board->careers))->toBe([DemandProposalState::Submitted, DemandProposalState::Accepted])
        ->and($board->sentCareers())->toBe(2)
        ->and($topics->cell($this->isic->id))->toMatchArray(['suggested' => 3, 'counted' => 4, 'pending' => false])
        ->and($topics->cell($this->isic->id, $this->c1->id))->toMatchArray(['suggested' => 2, 'counted' => 3])
        ->and($topics->cell($this->itic->id, $this->c2->id))->toBeNull()
        ->and($topics->total())->toMatchArray(['suggested' => 5, 'counted' => 6])
        ->and(array_map(fn (array $entry): array => [$entry['campus']->code, $entry['cell']->counted, $entry['cell']->suggested], $topics->byCampus()))->toBe([['C1', 5, 4], ['C2', 1, 1]])
        ->and(array_map(fn (Campus $campus): string => $campus->code, $board->campuses))->toBe(['C1', 'C2']);
});

it('una carrera sin propuesta enviada cuenta lo sugerido y su borrador todavía no cuenta', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->ige, $this->topics, $this->c1, 3);
    demandBoardItem(demandBoardProposal($this->isic, ProposalStatus::Draft), $this->topics, $this->c1, 9);

    $board = demandBoardOf($this->sc);
    $topics = $board->subject('SCD-1027');

    expect(array_map(fn (array $column): string => $column['state']->label(), $board->careers))->toBe(['Sin propuesta', 'En captura'])
        ->and($board->sentCareers())->toBe(0)
        ->and($topics->cell($this->isic->id))->toMatchArray(['suggested' => 2, 'counted' => 2, 'pending' => true, 'large' => false])
        ->and($topics->cell($this->ige->id))->toMatchArray(['counted' => 3, 'pending' => true])
        ->and($topics->total())->toMatchArray(['suggested' => 5, 'counted' => 5])
        ->and($topics->hasPending())->toBeTrue()
        ->and($board->figures())->toMatchArray(['pendingSuggested' => 5, 'withoutProposal' => 1]);
});

it('una propuesta regresada cuenta lo que pidió, y una enviada sin la materia pide cero', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->itic, $this->topics, $this->c1, 2);
    demandBoardItem(demandBoardProposal($this->isic, ProposalStatus::Returned), $this->topics, $this->c1, 4);
    demandBoardItem(demandBoardProposal($this->itic, ProposalStatus::Submitted), $this->web, $this->c1, 1);

    $topics = demandBoardOf($this->sc)->subject('SCD-1027');

    expect($topics->cell($this->isic->id))->toMatchArray(['counted' => 4, 'pending' => false])
        ->and($topics->cell($this->itic->id))->toMatchArray(['suggested' => 2, 'counted' => 0, 'large' => true]);
});

it('resalta la diferencia que llega al umbral de los parámetros, y nunca la de una carrera sin propuesta', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 5);
    demandBoardForecast($this->itic, $this->topics, $this->c1, 5);
    demandBoardForecast($this->ige, $this->topics, $this->c1, 5);
    demandBoardItem(demandBoardProposal($this->isic, ProposalStatus::Submitted), $this->topics, $this->c1, 6);
    demandBoardItem(demandBoardProposal($this->itic, ProposalStatus::Submitted), $this->topics, $this->c1, 5);

    $loose = app(DemandBoardQuery::class)->forDepartment($this->term, $this->modality, $this->sc, new DemandSettings(highlightThreshold: 50))->subject('SCD-1027');
    $topics = demandBoardOf($this->sc)->subject('SCD-1027');

    expect($topics->cell($this->isic->id)->large)->toBeTrue()
        ->and($topics->cell($this->itic->id)->large)->toBeFalse()
        ->and($topics->cell($this->ige->id)->large)->toBeFalse()
        ->and($topics->total()->large)->toBeFalse()
        ->and($topics->hasDifference())->toBeTrue()
        ->and($loose->cell($this->isic->id)->large)->toBeFalse()
        ->and($loose->hasDifference())->toBeFalse();
});

it('el umbral sale de los parámetros de la proyección cuando no se pasa', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 10);
    demandBoardItem(demandBoardProposal($this->isic, ProposalStatus::Submitted), $this->topics, $this->c1, 12);
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => DemandSettings::HIGHLIGHT_THRESHOLD_SETTING, 'value' => 30]);

    $board = app(DemandBoardQuery::class)->forDepartment($this->term, $this->modality, $this->sc);

    expect($board->settings->highlightThreshold)->toBe(30)
        ->and($board->subject('SCD-1027')->cell($this->isic->id)->large)->toBeFalse();
});

it('el filtro de campus deja las celdas, los renglones y las cifras de ese campus', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->isic, $this->topics, $this->c2, 2);
    demandBoardForecast($this->isic, $this->web, $this->c1, 3);
    demandBoardItem($isic = demandBoardProposal($this->isic, ProposalStatus::Submitted), $this->topics, $this->c1, 2);
    demandBoardItem($isic, $this->topics, $this->c2, 4);
    demandBoardItem($isic, $this->web, $this->c1, 3);

    $board = demandBoardOf($this->sc);

    expect(array_map(fn ($subject): string => $subject->subject->code, $board->visible(campusId: $this->c2->id)))->toBe(['SCD-1027'])
        ->and($board->subject('SCD-1027')->cell($this->isic->id, $this->c2->id))->toMatchArray(['suggested' => 2, 'counted' => 4, 'large' => true])
        ->and($board->subject('SCD-1027')->cell($this->isic->id, $this->c1->id)->large)->toBeFalse()
        ->and($board->filterCounts($this->c2->id))->toBe(['all' => 1, 'difference' => 1, 'pending' => 0])
        ->and($board->filterCounts($this->c1->id))->toBe(['all' => 2, 'difference' => 0, 'pending' => 0])
        ->and($board->figures()['countedByCampus'])->toBe(['C1' => 5, 'C2' => 4]);
});

it('busca por clave o nombre sin distinguir acentos y filtra con diferencia o sin propuesta', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->isic, $this->web, $this->c1, 2);
    demandBoardForecast($this->itic, $this->web, $this->c1, 1);
    demandBoardItem(demandBoardProposal($this->isic, ProposalStatus::Submitted), $this->topics, $this->c1, 4);

    $board = demandBoardOf($this->sc);
    $codes = fn (array $subjects): array => array_map(fn ($subject): string => $subject->subject->code, $subjects);

    expect($codes($board->visible('topicos')))->toBe(['SCD-1027'])
        ->and($codes($board->visible('aeb-')))->toBe(['AEB-1055'])
        ->and($codes($board->visible(filter: 'difference')))->toBe(['AEB-1055', 'SCD-1027'])
        ->and($codes($board->visible(filter: 'pending')))->toBe(['AEB-1055'])
        ->and($board->filterCounts())->toBe(['all' => 2, 'difference' => 2, 'pending' => 1]);
});

it('la lente del departamento trae solo las materias que imparte, de todas las carreras', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->ige, $this->topics, $this->c1, 1);
    demandBoardForecast($this->isic, $this->calculus, $this->c1, 4);

    $board = demandBoardOf($this->sc);

    expect(array_map(fn ($subject): string => $subject->subject->code, $board->subjects))->toBe(['SCD-1027'])
        ->and(array_map(fn (array $column): string => $column['career']->code, $board->careers))->toBe(['IGE', 'ISIC'])
        ->and($board->isCareerLens())->toBeFalse();
});

it('la lente de la carrera trae sus materias de todos los departamentos, con las demás carreras que las llevan, por semestre', function () {
    $plan = StudyPlan::factory()->create(['career_id' => $this->isic->id]);
    placeInPlan($plan, $this->calculus, 1);
    placeInPlan($plan, $this->topics, 4);
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->isic, $this->calculus, $this->c1, 4);
    demandBoardForecast($this->ige, $this->calculus, $this->c2, 3);
    demandBoardForecast($this->ige, $this->web, $this->c1, 1);
    demandBoardItem(demandBoardProposal($this->itic, ProposalStatus::Submitted), $this->web, $this->c1, 1);

    $board = app(DemandBoardQuery::class)->forCareer($this->term, $this->modality, $this->isic, $this->settings);

    expect(array_map(fn ($subject): string => $subject->subject->code, $board->subjects))->toBe(['ACF-0901', 'SCD-1027'])
        ->and(array_map(fn ($subject): ?int => $subject->semester, $board->subjects))->toBe([1, 4])
        ->and(array_map(fn (array $column): string => $column['career']->code, $board->careers))->toBe(['ISIC', 'IGE'])
        ->and($board->subject('ACF-0901')->cell($this->ige->id))->toMatchArray(['suggested' => 3, 'counted' => 3, 'pending' => true])
        ->and(array_map(fn ($department): string => $department->code, $board->departments()))->toBe(['CB', 'SC'])
        ->and(array_map(fn ($subject): string => $subject->subject->code, $board->withinDepartment($this->cb)->subjects))->toBe(['ACF-0901'])
        ->and(array_map(fn (array $column): string => $column['career']->code, $board->withinDepartment($this->sc)->careers))->toBe(['ISIC'])
        ->and($board->isCareerLens())->toBeTrue();
});

it('la lente de la carrera también trae lo que la carrera pidió sin proyección', function () {
    demandBoardItem(demandBoardProposal($this->isic, ProposalStatus::Submitted), $this->web, $this->c1, 2, semester: 6);

    $board = app(DemandBoardQuery::class)->forCareer($this->term, $this->modality, $this->isic, $this->settings);
    $web = $board->subject('AEB-1055');

    expect($web->semester)->toBe(6)
        ->and($web->cell($this->isic->id))->toMatchArray(['suggested' => 0, 'counted' => 2, 'large' => true])
        ->and($web->rows[0]->method)->toBeNull();
});

it('un campus borrado después de la proyección sigue siendo el campus de sus filas', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->isic, $this->topics, $this->c2, 1);
    $this->c2->delete();

    $topics = demandBoardOf($this->sc)->subject('SCD-1027');

    expect(array_map(fn ($row): ?string => $row->campus?->code, $topics->rows))->toBe(['C1', 'C2'])
        ->and(array_map(fn (array $line): ?string => $line['campus']?->code, $topics->breakdown()['campuses']))->toBe(['C1', 'C2']);
});

it('en la lente de carrera, el semestre sale de su retícula aunque su fila no tenga demanda', function () {
    $plan = StudyPlan::factory()->create(['career_id' => $this->isic->id]);
    placeInPlan($plan, $this->web, 5);
    demandBoardForecast($this->isic, $this->web, $this->c1, 0, ['eligible_students' => 0, 'expected_students' => 0]);
    demandBoardForecast($this->ige, $this->web, $this->c1, 2);

    $web = app(DemandBoardQuery::class)->forCareer($this->term, $this->modality, $this->isic, $this->settings)->subject('AEB-1055');

    expect($web->semester)->toBe(5)
        ->and($web->cell($this->isic->id))->toBeNull();
});

it('deja fuera las filas sin elegibles, sin esperados, sin sugeridos y sin nada pedido', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    demandBoardForecast($this->ige, $this->topics, $this->c1, 0, ['eligible_students' => 0, 'expected_students' => 0]);
    demandBoardForecast($this->isic, $this->web, $this->c1, 0, ['eligible_students' => 0, 'expected_students' => 0]);

    $board = demandBoardOf($this->sc);

    expect(array_map(fn ($subject): string => $subject->subject->code, $board->subjects))->toBe(['SCD-1027'])
        ->and(array_map(fn (array $column): string => $column['career']->code, $board->careers))->toBe(['ISIC']);
});

it('da las cuatro cifras de todo el Tec: materias, sugeridos, pedidos y la diferencia en grupos, porcentaje y horas', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 4);
    demandBoardForecast($this->isic, $this->topics, $this->c2, 2);
    demandBoardForecast($this->isic, $this->web, $this->c1, 3);
    demandBoardForecast($this->ige, $this->web, $this->c2, 1);
    $isic = demandBoardProposal($this->isic, ProposalStatus::Accepted);
    demandBoardItem($isic, $this->topics, $this->c1, 6);
    demandBoardItem($isic, $this->topics, $this->c2, 2);
    demandBoardItem($isic, $this->web, $this->c1, 2);

    expect(demandBoardOf($this->sc)->figures())->toBe([
        'subjects' => 2,
        'withDifference' => 2,
        'withoutProposal' => 1,
        'suggested' => 10,
        'suggestedByCampus' => ['C1' => 7, 'C2' => 3],
        'suggestedLab' => 6,
        'counted' => 11,
        'countedByCampus' => ['C1' => 8, 'C2' => 3],
        'countedLab' => 8,
        'pendingSuggested' => 1,
        'difference' => 1,
        'percent' => 10,
        'large' => false,
        // SCD-1027: +2 grupos × 5 h; AEB-1055: −1 grupo × 5 h.
        'hours' => 5,
    ]);
});

it('la fecha de la proyección es la más reciente de sus filas, y sin filas no hay fecha', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2, ['computed_at' => '2026-11-16 08:00:00']);
    demandBoardForecast($this->ige, $this->topics, $this->c1, 2, ['computed_at' => '2026-11-17 06:10:00']);

    expect(demandBoardOf($this->sc)->computedAt->format('Y-m-d H:i'))->toBe('2026-11-17 06:10')
        ->and(demandBoardOf($this->cb)->computedAt)->toBeNull()
        ->and(demandBoardOf($this->cb)->subjects)->toBe([]);
});

it('arma el tablero con las mismas consultas sin importar cuántas materias, carreras y campus tenga', function () {
    $countFor = function (int $subjects): int {
        $department = Department::factory()->create();

        foreach (range(1, $subjects) as $n) {
            $subject = Subject::factory()->create(['department_id' => $department->id]);

            foreach ([$this->isic, $this->itic, $this->ige] as $career) {
                demandBoardForecast($career, $subject, $this->c1, 2);
                demandBoardForecast($career, $subject, $this->c2, 1);
            }

            demandBoardItem(CareerProposal::query()->where('career_id', $this->isic->id)->first() ?? demandBoardProposal($this->isic, ProposalStatus::Submitted), $subject, $this->c1, 2);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $board = app(DemandBoardQuery::class)->forDepartment($this->term, $this->modality, $department, $this->settings);
        $board->figures();
        $board->visible(filter: 'difference');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = $countFor(2);
    $many = $countFor(12);

    expect($many)->toBe($few)->toBeLessThanOrEqual(10);
});

it('da el historial de la materia por campus en los periodos del mismo tipo, del más antiguo al más reciente', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->topics->id, 'term_code' => '20261', 'campus_id' => $this->c1->id, 'group_code' => 'A', 'capacity' => 35, 'enrolled' => 30]);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->topics->id, 'term_code' => '20261', 'campus_id' => $this->c1->id, 'group_code' => 'B', 'capacity' => 35, 'enrolled' => 28]);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->topics->id, 'term_code' => '20241', 'campus_id' => $this->c2->id, 'group_code' => 'A', 'capacity' => 30, 'enrolled' => 12]);
    $plan = StudyPlan::factory()->create(['career_id' => $this->isic->id]);
    foreach ([KardexResult::Aprobada, KardexResult::Aprobada, KardexResult::Reprobada] as $result) {
        $student = Student::factory()->create(['campus_id' => $this->c1->id, 'modality_id' => $this->modality->id, 'study_plan_id' => $plan->id]);
        KardexRecord::factory()->for($student)->for($this->topics)->create(['term_code' => '20261', 'result' => $result, 'grade' => $result === KardexResult::Aprobada ? 85 : null]);
    }

    $board = demandBoardOf($this->sc);
    $history = app(DemandBoardQuery::class)->history($board, $board->subject('SCD-1027'));

    expect($history['label'])->toBe('Ene-Jun')
        ->and(array_map(fn (array $entry): ?string => $entry['campus']?->code, $history['campuses']))->toBe(['C1', 'C2'])
        ->and(array_column($history['campuses'][0]['terms'], 'year'))->toBe(['2023', '2024', '2025', '2026'])
        ->and($history['campuses'][0]['terms'][3])->toBe(['code' => '20261', 'year' => '2026', 'groups' => 2, 'capacity' => 70, 'enrolled' => 58, 'students' => 3, 'passed' => 2, 'failed' => 1])
        ->and($history['campuses'][0]['terms'][0])->toMatchArray(['groups' => null, 'students' => null])
        ->and($history['campuses'][1]['terms'][1])->toMatchArray(['groups' => 1, 'capacity' => 30, 'enrolled' => 12]);
});

it('en verano no hay historial', function () {
    $summer = Term::factory()->create(['code' => '20272']);
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2, ['term_id' => $summer->id]);

    $board = app(DemandBoardQuery::class)->forDepartment($summer, $this->modality, $this->sc, $this->settings);

    expect(app(DemandBoardQuery::class)->history($board, $board->subject('SCD-1027')))->toBe(['label' => '', 'campuses' => []]);
});

it('cada fila guarda el método de la proyección y si hay que revisarla', function () {
    demandBoardForecast($this->isic, $this->topics, $this->c1, 2, ['method' => ForecastMethod::DepartmentFallback, 'needs_review' => true]);

    $row = demandBoardOf($this->sc)->subject('SCD-1027')->rows[0];

    expect($row->method)->toBe(ForecastMethod::DepartmentFallback)
        ->and($row->needsReview)->toBeTrue()
        ->and($row->state)->toBe(DemandProposalState::Missing);
});
