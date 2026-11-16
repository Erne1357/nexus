<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Queries\SubjectGroupHistoryQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use Illuminate\Support\Facades\DB;

/*
| Historial de grupos por materia (tarea 5.6): los últimos periodos del mismo tipo (Ene-Jun con Ene-Jun, Ago-Dic con
| Ago-Dic, sin verano), por materia y campus; lo que Nexus publicó gana a lo del SII en el mismo periodo.
*/

beforeEach(function () {
    $this->modality = Modality::query()->where('code', 'PRES')->firstOrFail();
    $this->subject = Subject::factory()->create();
    $this->campus = Campus::factory()->create();
});

it('lista los periodos del mismo tipo anteriores al objetivo', function () {
    expect(SubjectGroupHistoryQuery::sameTypeTermsBefore('20271', 4))->toBe(['20261', '20251', '20241', '20231'])
        ->and(SubjectGroupHistoryQuery::sameTypeTermsBefore('20273', 2))->toBe(['20263', '20253'])
        ->and(SubjectGroupHistoryQuery::sameTypeTermsBefore('20272', 2))->toBe([]);
});

it('suma grupos, cupo e inscritos por materia, periodo y campus, solo de los periodos del mismo tipo', function () {
    foreach (['A', 'B'] as $code) {
        HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20261', 'campus_id' => $this->campus->id, 'group_code' => $code, 'capacity' => 35, 'enrolled' => 30]);
    }
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20261', 'campus_id' => null, 'group_code' => 'C', 'capacity' => 20, 'enrolled' => 10]);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20263', 'group_code' => 'A']);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20212', 'group_code' => 'A']);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20191', 'group_code' => 'A']);

    $history = (new SubjectGroupHistoryQuery)->history('20271', $this->modality, [$this->subject->id], 4);

    expect(array_map('strval', array_keys($history[$this->subject->id])))->toBe(['20261'])
        ->and($history[$this->subject->id]['20261'][$this->campus->id])->toBe(['groups' => 2, 'capacity' => 70, 'enrolled' => 60, 'source' => 'sii'])
        ->and($history[$this->subject->id]['20261'][SubjectGroupHistoryQuery::NO_CAMPUS])->toBe(['groups' => 1, 'capacity' => 20, 'enrolled' => 10, 'source' => 'sii']);
});

it('en un periodo con filas nexus, estas ganan a las sii', function () {
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20261', 'group_code' => 'A', 'capacity' => 35]);
    HistoricalGroup::factory()->fromNexus()->create(['subject_id' => $this->subject->id, 'term_code' => '20261', 'group_code' => 'A', 'capacity' => 50]);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20251', 'group_code' => 'A', 'capacity' => 33]);

    $history = (new SubjectGroupHistoryQuery)->history('20271', $this->modality, [$this->subject->id], 4)[$this->subject->id];

    expect($history['20261'][SubjectGroupHistoryQuery::NO_CAMPUS])->toMatchArray(['capacity' => 50, 'enrolled' => null, 'source' => 'nexus'])
        ->and($history['20251'][SubjectGroupHistoryQuery::NO_CAMPUS]['source'])->toBe('sii');
});

it('solo trae las materias y la modalidad pedidas y respeta el número de periodos', function () {
    $other = Subject::factory()->create();
    $ead = Modality::query()->where('code', 'EAD')->firstOrFail();
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20261']);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20241']);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $other->id, 'term_code' => '20261']);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->subject->id, 'term_code' => '20251', 'modality_id' => $ead->id]);

    $history = (new SubjectGroupHistoryQuery)->history('20271', $this->modality, [$this->subject->id], 3);

    expect(array_keys($history))->toBe([$this->subject->id])->and(array_map('strval', array_keys($history[$this->subject->id])))->toEqualCanonicalizing(['20261', '20241']);
});

it('hace pocas consultas sin importar cuántas materias se pidan', function () {
    $subjects = Subject::factory()->count(5)->create();
    $subjects->each(fn (Subject $subject) => HistoricalGroup::factory()->fromSii()->create(['subject_id' => $subject->id, 'term_code' => '20261']));

    DB::enableQueryLog();
    (new SubjectGroupHistoryQuery)->history('20271', $this->modality, $subjects->pluck('id')->all(), 4);

    expect(DB::getQueryLog())->toHaveCount(2);
});
