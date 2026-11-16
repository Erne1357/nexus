<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/*
| Al publicarse una carga (LoadTransitioned a `published`), AcademicLoad lleva los grupos de su última versión al
| historial por materia con fuente `nexus` (tarea 5.6): una versión nueva reemplaza a la anterior.
*/

beforeEach(function () {
    $term = Term::factory()->create(['code' => '20271']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create(['term_id' => $term->id]);
    $this->campus = Campus::factory()->create(['code' => 'HC1']);
    $this->subject = Subject::factory()->create();
    $this->other = Subject::factory()->create();
});

function historicalGroupsPublish(AcademicLoad $load, int $version, array $groups): void
{
    LoadSnapshot::factory()->for($load)->withGroups($groups)->create(['version' => $version]);
    LoadTransitioned::dispatch($load->fresh(), LoadStatus::Approved, LoadStatus::Published);
}

function historicalGroupsGroup(Subject $subject, string $code, ?Campus $campus, int $capacity = 35, array $placements = []): array
{
    return LoadSnapshotFactory::group([
        'code' => $code,
        'capacity' => $capacity,
        'campus' => $campus === null ? null : ['id' => $campus->id, 'code' => $campus->code, 'name' => $campus->name],
        'subject' => ['id' => $subject->id, 'code' => $subject->code, 'name' => 'Materia', 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => $subject->department_id],
        'placements' => $placements === [] ? [LoadSnapshotFactory::placement(['career' => ($career = Career::factory()->create())->only(['id', 'code', 'name'])])] : $placements,
    ]);
}

it('al publicar crea las filas nexus con campus, cupo y sin inscritos', function () {
    historicalGroupsPublish($this->load, 1, [
        historicalGroupsGroup($this->subject, 'A', $this->campus, 40),
        historicalGroupsGroup($this->other, 'A', null, 30),
    ]);

    $rows = HistoricalGroup::query()->get()->keyBy('subject_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$this->subject->id]->source)->toBe(HistoricalGroupSource::Nexus)
        ->and($rows[$this->subject->id]->term_code)->toBe('20271')
        ->and($rows[$this->subject->id]->campus_id)->toBe($this->campus->id)
        ->and($rows[$this->subject->id]->capacity)->toBe(40)
        ->and($rows[$this->subject->id]->enrolled)->toBeNull()
        ->and($rows[$this->subject->id]->modality_id)->toBe($this->load->modality_id)
        ->and($rows[$this->other->id]->campus_id)->toBeNull();
});

it('una versión nueva reemplaza a la anterior, también los grupos que ya no están', function () {
    historicalGroupsPublish($this->load, 1, [historicalGroupsGroup($this->subject, 'A', $this->campus, 40), historicalGroupsGroup($this->subject, 'B', $this->campus)]);
    $v2 = historicalGroupsPublish($this->load, 2, [historicalGroupsGroup($this->subject, 'A', $this->campus, 25)]);

    $rows = HistoricalGroup::query()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->group_code)->toBe('A')->and($rows->first()->capacity)->toBe(25)
        ->and($rows->first()->loadSnapshot->version)->toBe(2);
});

it('una transición a otro estado no escribe nada', function () {
    LoadSnapshot::factory()->for($this->load)->withGroups([historicalGroupsGroup($this->subject, 'A', $this->campus)])->create();

    LoadTransitioned::dispatch($this->load->fresh(), LoadStatus::Published, LoadStatus::Reanalysis);

    expect(HistoricalGroup::query()->count())->toBe(0);
});

it('el grupo compartido entre varias carreras queda sin carrera exclusiva y el de una sola la lleva', function () {
    $career = Career::factory()->create();
    $own = LoadSnapshotFactory::placement(['career' => ['id' => $career->id, 'code' => $career->code, 'name' => $career->name]]);
    $second = Career::factory()->create();
    $shared = [$own, LoadSnapshotFactory::placement(['is_owner' => false, 'career' => ['id' => $second->id, 'code' => $second->code, 'name' => $second->name]])];

    historicalGroupsPublish($this->load, 1, [
        historicalGroupsGroup($this->subject, 'A', $this->campus, 35, [$own]),
        historicalGroupsGroup($this->subject, 'B', $this->campus, 35, $shared),
    ]);

    $rows = HistoricalGroup::query()->get()->keyBy('group_code');

    expect($rows['A']->career_id)->toBe($career->id)->and($rows['B']->career_id)->toBeNull()
        ->and($rows['A']->parallel_of)->toBeNull();
});

it('dos cargas de distinta modalidad con la misma materia y grupo en el periodo no se pisan', function () {
    $otherLoad = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create(['term_id' => $this->load->term_id, 'department_id' => $this->load->department_id, 'modality_id' => Modality::query()->where('code', 'EAD')->value('id')]);

    historicalGroupsPublish($this->load, 1, [historicalGroupsGroup($this->subject, 'A', $this->campus, 40)]);
    historicalGroupsPublish($otherLoad, 1, [historicalGroupsGroup($this->subject, 'A', $this->campus, 25)]);

    expect($otherLoad->modality_id)->not->toBe($this->load->modality_id)
        ->and(HistoricalGroup::query()->orderBy('capacity')->pluck('capacity')->all())->toBe([25, 40]);
});
