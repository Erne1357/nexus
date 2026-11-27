<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\DeriveQualificationsFromHistory;
use App\Modules\Faculty\Actions\RemoveQualification;
use App\Modules\Faculty\Actions\SetQualification;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Enums\QualificationSource;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;

function taught(Teacher $teacher, Subject $subject, string $term, string $group = '1A'): void
{
    TeacherSubjectHistory::factory()->create([
        'teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'term_code' => $term, 'group_code' => $group,
    ]);
}

beforeEach(function () {
    $this->teacher = Teacher::factory()->create();
    $this->action = new DeriveQualificationsFromHistory;
});

it('D-02: crea Apto con source history para las materias de los últimos 6 semestres', function () {
    [$recent, $mid, $old] = Subject::factory()->count(3)->create();
    taught($this->teacher, $recent, '20263');
    taught($this->teacher, $mid, '20241');
    taught($this->teacher, $old, '20233');
    // Los periodos de 6 semestres hacia atrás desde 20263: 20263, 20261, 20253, 20251, 20243, 20241.
    // Sembramos los intermedios con otra materia para que la ventana tenga los 6.
    $filler = Subject::factory()->create();
    foreach (['20261', '20253', '20251', '20243'] as $term) {
        taught($this->teacher, $filler, $term);
    }

    $result = $this->action->handle('20263');

    expect($result)->toBe(['created' => 3, 'removed' => 0, 'kept_manual' => 0]);
    $rows = TeacherSubjectQualification::where('teacher_id', $this->teacher->id)->get();
    expect($rows->pluck('subject_id')->sort()->values()->all())->toBe(collect([$recent->id, $mid->id, $filler->id])->sort()->values()->all())
        ->and($rows->pluck('source')->unique()->all())->toBe([QualificationSource::History])
        ->and($rows->pluck('level')->unique()->all())->toBe([QualificationLevel::Apto])
        ->and($rows->pluck('notes')->unique()->all())->toBe(['Del historial del SII'])
        ->and($rows->pluck('subject_id')->contains($old->id))->toBeFalse();
});

it('D-02: no toca las materias de experiencia académica manuales', function () {
    $subject = Subject::factory()->create();
    taught($this->teacher, $subject, '20263');
    $manual = TeacherSubjectQualification::factory()->preferente()->create([
        'teacher_id' => $this->teacher->id, 'subject_id' => $subject->id, 'notes' => 'Mi nota',
        'source' => QualificationSource::Manual,
    ]);

    $result = $this->action->handle('20263');

    expect($result)->toBe(['created' => 0, 'removed' => 0, 'kept_manual' => 1])
        ->and(TeacherSubjectQualification::count())->toBe(1)
        ->and($manual->fresh()->level)->toBe(QualificationLevel::Preferente)
        ->and($manual->fresh()->notes)->toBe('Mi nota')
        ->and($manual->fresh()->source)->toBe(QualificationSource::Manual);
});

it('D-02: quita las de historial que salieron de la ventana', function () {
    $subject = Subject::factory()->create();
    $kept = Subject::factory()->create();
    taught($this->teacher, $subject, '20233');
    taught($this->teacher, $kept, '20263');
    $stale = TeacherSubjectQualification::factory()->create([
        'teacher_id' => $this->teacher->id, 'subject_id' => $subject->id, 'source' => QualificationSource::History,
    ]);
    $manualOut = TeacherSubjectQualification::factory()->create(['teacher_id' => $this->teacher->id, 'source' => QualificationSource::Manual]);

    // Con 20263 y la ventana de 6 periodos, 20233 sigue dentro mientras haya menos de 6 periodos en el historial;
    // la llenamos para empujarlo fuera.
    $filler = Subject::factory()->create();
    foreach (['20261', '20253', '20251', '20243', '20241'] as $term) {
        taught($this->teacher, $filler, $term);
    }

    $result = $this->action->handle('20263');

    expect($result['removed'])->toBe(1)
        ->and($stale->fresh()->trashed())->toBeTrue()
        ->and($manualOut->fresh()->trashed())->toBeFalse();
});

it('D-02: es idempotente', function () {
    taught($this->teacher, Subject::factory()->create(), '20263');
    taught($this->teacher, Subject::factory()->create(), '20261');

    $first = $this->action->handle('20263');
    $second = $this->action->handle('20263');

    expect($first['created'])->toBe(2)
        ->and($second)->toBe(['created' => 0, 'removed' => 0, 'kept_manual' => 0])
        ->and(TeacherSubjectQualification::count())->toBe(2);
});

it('D-02: ignora el periodo 2 y los periodos posteriores al dado', function () {
    $verano = Subject::factory()->create();
    $futura = Subject::factory()->create();
    taught($this->teacher, $verano, '20262');
    taught($this->teacher, $futura, '20271');

    expect($this->action->handle('20263'))->toBe(['created' => 0, 'removed' => 0, 'kept_manual' => 0]);
});

it('D-02: el comando usa el periodo que contiene hoy', function () {
    Term::factory()->create(['code' => '20263', 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonth()]);
    taught($this->teacher, Subject::factory()->create(), '20263');

    $this->artisan('faculty:derive-qualifications', ['--no-interaction' => true])->assertSuccessful();

    expect(TeacherSubjectQualification::where('source', 'history')->count())->toBe(1);
});

it('D-02: la ventana son los 6 semestres del calendario aunque el periodo actual no tenga historial', function () {
    $inside = Subject::factory()->create();
    $outside = Subject::factory()->create();
    taught($this->teacher, $inside, '20243');
    taught($this->teacher, $outside, '20241');

    $this->action->handle('20271');

    expect(TeacherSubjectQualification::pluck('subject_id')->all())->toBe([$inside->id]);
});

it('D-02: una materia del historial que el jefe edita pasa a manual y la derivación ya no la toca', function () {
    $subject = Subject::factory()->create();
    $jefe = User::factory()->create();
    taught($this->teacher, $subject, '20233');
    $row = TeacherSubjectQualification::factory()->create([
        'teacher_id' => $this->teacher->id, 'subject_id' => $subject->id, 'source' => QualificationSource::History,
    ]);

    (new SetQualification)->handle($this->teacher, $subject, QualificationLevel::Preferente, $jefe);
    $this->action->handle('20263');

    expect($row->fresh()->source)->toBe(QualificationSource::Manual)
        ->and($row->fresh()->trashed())->toBeFalse()
        ->and($row->fresh()->level)->toBe(QualificationLevel::Preferente);
});

it('D-02: una materia del historial que el jefe quita no vuelve con la siguiente derivación', function () {
    $subject = Subject::factory()->create();
    taught($this->teacher, $subject, '20263');
    $this->action->handle('20263');
    $row = TeacherSubjectQualification::firstOrFail();

    (new RemoveQualification)->handle($row);
    $result = $this->action->handle('20263');

    expect($result['created'])->toBe(0)
        ->and(TeacherSubjectQualification::count())->toBe(0)
        ->and($row->fresh()->source)->toBe(QualificationSource::Manual);
});
