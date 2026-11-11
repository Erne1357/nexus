<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Models\Campus;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Collection;
use Tests\Support\Academic\DemoCatalog;

/*
| Catálogo demo (tarea 1.10 ampliada): departamentos, carreras con su oferta por campus, planes con su retícula,
| especialidades y seriación, los usuarios de prueba y los periodos, para probar las pantallas con datos
| realistas. DemoSeeder siembra Campus II; con los dos campus se siembran las 12 carreras de Campus I.
|
| Las pruebas del demo académico van en cuatro archivos (AcademicDemoSeederTest, AcademicDemoCatalogTest,
| AcademicDemoStaffTest y AcademicDemoReseedTest) porque casi todas siembran el demo completo: juntas tardaban
| unos 10 minutos en un solo proceso y marcaban el tiempo de toda la suite. Separadas, se reparten entre los
| procesos y los shards del CI (docs/arquitectura/testing.md, «Shards»). Los atajos están en
| tests/Support/Academic/DemoCatalog.php.
|
| Este archivo: el catálogo de los dos campus (carreras, tronco común, seriación, especialidades y créditos).
*/

it('con los dos campus siembra 12 carreras en C1 y 6 en C2', function () {
    DemoCatalog::seed(['C1', 'C2']);

    $offered = fn (string $campus): int => CareerCampus::query()->whereRelation('campus', 'code', $campus)->where('is_active', true)->count();

    expect($offered('C1'))->toBe(12)
        ->and($offered('C2'))->toBe(6)
        ->and(StudyPlan::query()->where('is_active', true)->distinct()->count('career_id'))->toBe(12)
        ->and(StudyPlan::query()->where('is_active', true)->count())->toBe(12);
});

it('el tronco común se comparte entre planes', function () {
    DemoCatalog::seed(['C1', 'C2']);

    $plansWith = fn (string $code): int => Subject::query()->where('code', $code)->sole()->studyPlans()->count();

    expect($plansWith('ACF-0901'))->toBeGreaterThanOrEqual(10)
        ->and($plansWith('ACA-0907'))->toBe(12);
});

it('toda seriación está en la retícula de su plan y no forma ciclos', function () {
    DemoCatalog::seed(['C1', 'C2']);

    $semesterOf = StudyPlanSubject::query()->get()
        ->mapWithKeys(fn (StudyPlanSubject $placement): array => ["{$placement->study_plan_id}|{$placement->subject_id}" => $placement->semester]);
    $prerequisites = SubjectPrerequisite::query()->get();

    expect($prerequisites)->not->toBeEmpty();

    foreach ($prerequisites as $prerequisite) {
        $subject = $semesterOf["{$prerequisite->study_plan_id}|{$prerequisite->subject_id}"] ?? null;
        $required = $semesterOf["{$prerequisite->study_plan_id}|{$prerequisite->prerequisite_subject_id}"] ?? null;

        expect($subject)->not->toBeNull('La materia de una seriación no está en la retícula de su plan.')
            ->and($required)->not->toBeNull('El requisito de una seriación no está en la retícula de su plan.');

        $prerequisite->kind === PrerequisiteKind::Seriacion
            ? expect($required)->toBeLessThan($subject)
            : expect($required)->toBeLessThanOrEqual($subject);
    }

    $chains = $prerequisites->where('kind', PrerequisiteKind::Seriacion)->groupBy('study_plan_id')->map(DemoCatalog::longestSeriationChain(...));

    expect($chains->contains(null))->toBeFalse()
        ->and($chains->max())->toBeGreaterThanOrEqual(5)
        ->and($prerequisites->contains('kind', PrerequisiteKind::Correquisito))->toBeTrue();
});

it('cada plan vigente tiene 2 o 3 especialidades con materias de especialidad', function () {
    DemoCatalog::seed(['C1', 'C2']);

    $plans = StudyPlan::query()->where('is_active', true)->with(['specialties', 'placements.subject'])->get();

    expect($plans)->toHaveCount(12);

    foreach ($plans as $plan) {
        expect($plan->specialties->count())->toBeBetween(2, 3, "{$plan->code} no tiene 2 o 3 especialidades.");

        foreach ($plan->specialties as $specialty) {
            $subjects = $plan->placements->where('specialty_id', $specialty->id)->pluck('subject');

            expect($subjects)->not->toBeEmpty("La especialidad {$specialty->code} de {$plan->code} no tiene materias.")
                ->and($subjects->every(fn (Subject $subject): bool => $subject->kind === SubjectKind::Especialidad))->toBeTrue();
        }
    }
});

it('la especialidad de una fila de la retícula es de su mismo plan', function () {
    DemoCatalog::seed(['C1', 'C2']);

    $placements = StudyPlanSubject::withTrashed()->whereNotNull('specialty_id')->get();
    $planOf = Specialty::withTrashed()->pluck('study_plan_id', 'id');

    expect($placements)->not->toBeEmpty()
        ->and($placements->every(fn (StudyPlanSubject $placement): bool => $planOf[$placement->specialty_id] === $placement->study_plan_id))->toBeTrue();
});

it('los créditos de cada plan cuadran: las obligatorias sin especialidad más cualquiera de sus especialidades', function () {
    DemoCatalog::seed(['C1', 'C2']);

    $plans = StudyPlan::query()->whereNotNull('total_credits')->has('placements')->with('placements.subject')->get();

    expect($plans->count())->toBeGreaterThan(12);

    foreach ($plans as $plan) {
        $required = $plan->placements->whereNull('specialty_id')->where('is_required', true)->sum('subject.credits');
        $specialties = $plan->placements->whereNotNull('specialty_id')->groupBy('specialty_id')->map->sum('subject.credits');

        foreach ($specialties->whenEmpty(fn (Collection $none): Collection => $none->push(0)) as $credits) {
            expect($required + $credits)->toBe($plan->total_credits, "Los créditos de {$plan->code} no cuadran con su retícula.");
        }
    }
});
