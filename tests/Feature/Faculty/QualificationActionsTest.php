<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\RemoveQualification;
use App\Modules\Faculty\Actions\SetQualification;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Perfil de un docente (S17, supuesto por confirmar): el jefe declara una materia como apta o preferente; declararla
| de nuevo cambia el nivel y una quitada se restaura. Todo deja bitácora con el nivel anterior y el nuevo, y avisa
| tras el commit.
*/

beforeEach(function () {
    $this->teacher = Teacher::factory()->create(['employee_number' => '1000001']);
    $this->subject = Subject::factory()->create();
    $this->jefe = User::factory()->create();
    $this->activityBase = (int) Activity::max('id');
});

it('S17: declara una materia con su nivel, quién la declaró y la bitácora', function () {
    Event::fake([QualificationChanged::class]);

    $qualification = app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $this->jefe, 'Dio la materia en 2024');

    expect($qualification)->level->toBe(QualificationLevel::Apto)->qualified_by->toBe($this->jefe->id)->notes->toBe('Dio la materia en 2024');

    $log = Activity::where('id', '>', $this->activityBase)->sole();

    expect($log->event)->toBe('created')
        ->and($log->subject_id)->toBe($qualification->id)
        ->and($log->attribute_changes['attributes'])->toMatchArray(['level' => 'apto', 'subject_id' => $this->subject->id])
        ->and($log->properties['teacher_id'])->toBe($this->teacher->id);
    Event::assertDispatched(QualificationChanged::class);
});

it('S17: declararla con otro nivel lo cambia y deja el anterior en la bitácora', function () {
    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $this->jefe);
    $base = (int) Activity::max('id');

    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Preferente, $this->jefe);

    $log = Activity::where('id', '>', $base)->sole();

    expect(TeacherSubjectQualification::count())->toBe(1)
        ->and(TeacherSubjectQualification::sole()->level)->toBe(QualificationLevel::Preferente)
        ->and($log->event)->toBe('updated')
        ->and($log->attribute_changes['old']['level'])->toBe('apto')
        ->and($log->attribute_changes['attributes']['level'])->toBe('preferente');
});

it('declarar lo mismo dos veces no escribe, no registra ni avisa', function () {
    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $this->jefe);
    $base = (int) Activity::max('id');
    Event::fake([QualificationChanged::class]);

    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $this->jefe);

    expect(Activity::where('id', '>', $base)->count())->toBe(0);
    Event::assertNotDispatched(QualificationChanged::class);
});

it('quita una materia del perfil con borrado lógico y bitácora', function () {
    $qualification = app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Preferente, $this->jefe);
    $base = (int) Activity::max('id');

    app(RemoveQualification::class)->handle($qualification);

    $log = Activity::where('id', '>', $base)->sole();

    expect($qualification->fresh()->trashed())->toBeTrue()
        ->and($log->event)->toBe('deleted')
        ->and($log->attribute_changes['old']['level'])->toBe('preferente');
});

it('volver a declarar una materia quitada la restaura, sin duplicar la fila', function () {
    $qualification = app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $this->jefe);
    app(RemoveQualification::class)->handle($qualification);

    $restored = app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Preferente, $this->jefe);

    expect($restored->id)->toBe($qualification->id)
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->level)->toBe(QualificationLevel::Preferente)
        ->and(TeacherSubjectQualification::withTrashed()->count())->toBe(1);
});

it('no declara una materia inactiva ni borrada, pero deja cambiar el nivel de una ya declarada', function () {
    $kept = app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $this->jefe);
    $this->subject->update(['is_active' => false]);

    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Preferente, $this->jefe);

    expect($kept->fresh()->level)->toBe(QualificationLevel::Preferente);

    $other = Subject::factory()->create(['is_active' => false]);
    $deleted = Subject::factory()->create();
    $deleted->delete();

    foreach ([$other, $deleted] as $subject) {
        expect(fn () => app(SetQualification::class)->handle($this->teacher, $subject, QualificationLevel::Apto, $this->jefe))->toThrow(ValidationException::class);
    }
});
