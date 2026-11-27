<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Actions\SyncPublishedTeaching;
use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectHistory;

function taughtRow(Teacher $teacher, Subject $subject, string $group): array
{
    return ['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'group_code' => $group];
}

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->subject = Subject::factory()->create();
    $this->action = new SyncPublishedTeaching;
});

it('inserta con origen nexus las filas de la primera publicación', function () {
    $this->action->handle('20271', $this->campus->id, [
        taughtRow($this->teacher, $this->subject, '1A'),
        taughtRow($this->teacher, $this->subject, '1B'),
    ], []);

    $rows = TeacherSubjectHistory::all();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('source')->unique()->all())->toBe([HistorySource::Nexus])
        ->and($rows->pluck('term_code')->unique()->all())->toBe(['20271'])
        ->and($rows->pluck('campus_id')->unique()->all())->toBe([$this->campus->id]);
});

it('al republicar con otro docente borra la fila nexus anterior e inserta la nueva', function () {
    $other = Teacher::factory()->create();
    $old = [taughtRow($this->teacher, $this->subject, '1A')];
    $this->action->handle('20271', $this->campus->id, $old, []);

    $this->action->handle('20271', $this->campus->id, [taughtRow($other, $this->subject, '1A')], $old);

    expect(TeacherSubjectHistory::onlyTrashed()->where('teacher_id', $this->teacher->id)->count())->toBe(1)
        ->and(TeacherSubjectHistory::where('teacher_id', $other->id)->count())->toBe(1)
        ->and(TeacherSubjectHistory::count())->toBe(1);
});

it('deja intacta una fila importada con la misma clave y nunca la borra', function () {
    $imported = TeacherSubjectHistory::factory()->create([
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
        'term_code' => '20271',
        'campus_id' => $this->campus->id,
        'group_code' => '1A',
    ]);
    $row = taughtRow($this->teacher, $this->subject, '1A');

    $this->action->handle('20271', $this->campus->id, [$row], []);
    expect(TeacherSubjectHistory::withTrashed()->count())->toBe(1);

    $this->action->handle('20271', $this->campus->id, [], [$row]);

    $imported->refresh();
    expect($imported->trashed())->toBeFalse()
        ->and($imported->source)->toBe(HistorySource::Import);
});

it('restaura una fila borrada en vez de violar la clave única', function () {
    $deleted = TeacherSubjectHistory::factory()->fromNexus()->create([
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
        'term_code' => '20271',
        'campus_id' => $this->campus->id,
        'group_code' => '1A',
    ]);
    $deleted->delete();

    $this->action->handle('20271', $this->campus->id, [taughtRow($this->teacher, $this->subject, '1A')], []);

    expect(TeacherSubjectHistory::withTrashed()->count())->toBe(1)
        ->and($deleted->fresh()->trashed())->toBeFalse();
});

it('no toca filas de otro periodo ni de otro campus', function () {
    $otherCampus = Campus::factory()->create();
    $base = ['teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'group_code' => '1A'];
    $otherTerm = TeacherSubjectHistory::factory()->fromNexus()->create($base + ['term_code' => '20261', 'campus_id' => $this->campus->id]);
    $otherCampusRow = TeacherSubjectHistory::factory()->fromNexus()->create($base + ['term_code' => '20271', 'campus_id' => $otherCampus->id]);

    $this->action->handle('20271', $this->campus->id, [], [taughtRow($this->teacher, $this->subject, '1A')]);

    expect($otherTerm->fresh()->trashed())->toBeFalse()
        ->and($otherCampusRow->fresh()->trashed())->toBeFalse()
        ->and(TeacherSubjectHistory::count())->toBe(2);
});

it('inserta una sola vez las claves repetidas en lo impartido', function () {
    $row = taughtRow($this->teacher, $this->subject, '1A');

    $this->action->handle('20271', $this->campus->id, [$row, $row], []);

    expect(TeacherSubjectHistory::count())->toBe(1);
});

it('no hace nada con entradas vacías', function () {
    $this->action->handle('20271', $this->campus->id, [], []);

    expect(TeacherSubjectHistory::withTrashed()->count())->toBe(0);
});
