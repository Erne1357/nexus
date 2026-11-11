<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;
use Tests\Support\Academic\DemoCatalog;

/*
| Catálogo demo (tarea 1.10 ampliada): departamentos, carreras con su oferta por campus, planes con su retícula,
| especialidades y seriación, los usuarios de prueba y los periodos, para probar las pantallas con datos
| realistas. DemoSeeder siembra Campus II; con los dos campus se siembran las 12 carreras de Campus I.
|
| Las pruebas del demo académico van en cinco archivos (AcademicDemoSeederTest, AcademicDemoCatalogTest,
| AcademicDemoStaffTest, AcademicDemoReseedTest y AcademicDemoMassiveTest) porque casi todas siembran el demo
| completo: juntas tardaban unos 10 minutos en un solo proceso y marcaban el tiempo de toda la suite. Separadas, se
| reparten entre los procesos y los shards del CI (docs/arquitectura/testing.md, «Shards»). Los atajos están en
| tests/Support/Academic/DemoCatalog.php.
|
| Este archivo: Campus II con DemoSeeder, los casos extremos, los periodos y que no corre en producción.
*/

it('siembra las 6 carreras de Campus II con un plan vigente cada una', function () {
    $this->seed(DemoSeeder::class);

    $offered = CareerCampus::query()->whereRelation('campus', 'code', 'C2')->where('is_active', true)->with('career')->get();

    expect($offered->pluck('career.code')->all())->toEqualCanonicalizing(['LADM', 'COPU', 'ISIC', 'IIND', 'ILOG', 'IGEM'])
        ->and($offered->every(fn (CareerCampus $offering): bool => $offering->career->studyPlans()->where('is_active', true)->count() === 1))->toBeTrue()
        ->and($offered->every(fn (CareerCampus $offering): bool => $offering->career->studyPlans()->where('is_current', true)->count() === 1))->toBeTrue()
        ->and(CareerCampus::query()->whereRelation('campus', 'code', 'C1')->exists())->toBeFalse();
});

it('deja los casos extremos para probar las pantallas', function () {
    $this->seed(DemoSeeder::class);

    expect(StudyPlan::query()->doesntHave('placements')->exists())->toBeTrue('Falta un plan sin retícula.')
        ->and(StudyPlan::query()->where('is_active', false)->has('placements')->exists())->toBeTrue('Falta un plan viejo inactivo con retícula.')
        ->and(StudyPlan::query()->where('semesters_count', '>', 9)->whereHas('placements', fn ($query) => $query->where('semester', '>', 9))->exists())->toBeTrue('Falta un plan de más de 9 semestres.')
        ->and(StudyPlanSubject::query()->whereRelation('subject', 'is_active', false)->exists())->toBeTrue('Falta una materia inactiva en una retícula.')
        ->and(Department::query()->where('is_active', false)->exists())->toBeTrue('Falta un departamento inactivo.')
        ->and(Career::query()->where('is_active', false)->exists())->toBeTrue('Falta una carrera inactiva.')
        ->and(Department::onlyTrashed()->exists())->toBeTrue('Falta un departamento borrado.')
        ->and(Subject::onlyTrashed()->exists())->toBeTrue('Falta una materia borrada.')
        ->and(Specialty::onlyTrashed()->exists())->toBeTrue('Falta una especialidad borrada.')
        ->and(StudyPlanSubject::onlyTrashed()->exists())->toBeTrue('Falta una materia quitada de una retícula.')
        ->and(SubjectPrerequisite::onlyTrashed()->exists())->toBeTrue('Falta una seriación quitada.')
        ->and(StudyPlanSubject::query()->where('is_required', false)->exists())->toBeTrue('Falta una materia optativa.')
        ->and(Subject::query()->whereNull('kind')->exists())->toBeTrue('Falta una materia sin tipo capturado.')
        ->and(Subject::query()->whereRaw('char_length(name) > 100')->exists())->toBeTrue('Falta una materia con nombre muy largo.');
});

it('siembra los periodos 20261, 20263 y 20271, con Ene-Jun 2027 como actual', function () {
    $this->seed(DemoSeeder::class);

    expect(Term::query()->orderBy('code')->pluck('is_current', 'code')->all())->toBe(['20261' => false, '20263' => false, '20271' => true]);
});

it('no cambia el periodo actual si ya hay uno', function () {
    Term::factory()->current()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);

    (new DemoTermSeeder)->run();

    expect(Term::query()->orderBy('code')->pluck('is_current', 'code')->all())->toBe(['20261' => false, '20263' => true, '20271' => false]);
});

it('no corre en producción', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    foreach ([DemoSeeder::class, DemoTermSeeder::class, AcademicDemoSeeder::class, DemoStaffSeeder::class] as $seeder) {
        expect(fn () => $this->app->make($seeder)->setContainer($this->app)->__invoke())
            ->toThrow(RuntimeException::class, 'producción');
    }

    expect(Department::withTrashed()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(Term::query()->count())->toBe(0);
});
