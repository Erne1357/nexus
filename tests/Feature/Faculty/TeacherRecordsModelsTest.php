<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Enums\EvaluationKind;
use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherEvaluation;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;

it('un docente tiene disponibilidad, horas externas, historial, perfil y evaluaciones', function () {
    $teacher = Teacher::factory()->create();
    TeacherAvailability::factory()->for($teacher)->create();
    TeacherExternalLoad::factory()->for($teacher)->create();
    TeacherSubjectHistory::factory()->for($teacher)->create();
    TeacherSubjectQualification::factory()->for($teacher)->create();
    TeacherEvaluation::factory()->for($teacher)->create();

    $teacher = $teacher->fresh();

    expect($teacher->availability)->toHaveCount(1)
        ->and($teacher->externalLoads)->toHaveCount(1)
        ->and($teacher->history)->toHaveCount(1)
        ->and($teacher->qualifications)->toHaveCount(1)
        ->and($teacher->evaluations)->toHaveCount(1)
        ->and($teacher->availability->first()->teacher->is($teacher))->toBeTrue();
});

it('la disponibilidad se filtra por periodo y sus estados fijan el tipo', function () {
    $teacher = Teacher::factory()->create();
    $term = Term::factory()->create();
    $other = TeacherAvailability::factory()->for($teacher)->create();
    $block = TeacherAvailability::factory()->preferred()->for($teacher)->for($term)->create();

    $found = TeacherAvailability::query()->forTerm($term)->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->is($block))->toBeTrue()
        ->and($block->kind)->toBe(AvailabilityKind::Preferred)
        ->and($block->term->is($term))->toBeTrue()
        ->and($other->kind)->toBe(AvailabilityKind::Unavailable);
});

it('el historial relaciona materia y campus y tiene origen', function () {
    $subject = Subject::factory()->create();
    $campus = Campus::factory()->create();
    $row = TeacherSubjectHistory::factory()->for($subject)->for($campus)->fromNexus()->create();

    expect($row->subject->is($subject))->toBeTrue()
        ->and($row->campus->is($campus))->toBeTrue()
        ->and($row->source)->toBe(HistorySource::Nexus)
        ->and(TeacherSubjectHistory::factory()->create()->source)->toBe(HistorySource::Import);
});

it('el perfil guarda quién lo declaró y su nivel', function () {
    $user = User::factory()->create();
    $profile = TeacherSubjectQualification::factory()->preferente()->create(['qualified_by' => $user->id]);

    expect($profile->level)->toBe(QualificationLevel::Preferente)
        ->and($profile->qualifier->is($user))->toBeTrue()
        ->and(TeacherSubjectQualification::factory()->apto()->create()->level)->toBe(QualificationLevel::Apto);
});

it('la evaluación guarda el puntaje con dos decimales y su tipo', function () {
    $evaluation = TeacherEvaluation::factory()->departamental()->create(['score' => 4.25]);

    expect($evaluation->fresh()->score)->toBe('4.25')
        ->and($evaluation->kind)->toBe(EvaluationKind::Departamental)
        ->and(TeacherEvaluation::factory()->estudiantil()->create()->kind)->toBe(EvaluationKind::Estudiantil);
});

it('las horas externas relacionan el periodo', function () {
    $term = Term::factory()->create();

    expect(TeacherExternalLoad::factory()->for($term)->create()->term->is($term))->toBeTrue();
});

it('los enums tienen etiqueta en español', function () {
    expect(AvailabilityKind::Unavailable->label())->toBe('No disponible')
        ->and(AvailabilityKind::Preferred->label())->toBe('Preferido')
        ->and(HistorySource::Import->label())->toBe('Importado')
        ->and(HistorySource::Nexus->label())->toBe('Nexus')
        ->and(QualificationLevel::Apto->label())->toBe('Apto')
        ->and(QualificationLevel::Preferente->label())->toBe('Preferente')
        ->and(EvaluationKind::Estudiantil->label())->toBe('Estudiantil')
        ->and(EvaluationKind::Departamental->label())->toBe('Departamental');
});
