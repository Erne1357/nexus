<?php

use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Database\Seeders\TeacherCaptureDemoSeeder;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
| Variedad de captura docente en el último periodo abierto (tarea 2.6): unos sin disponibilidad, unos sin perfil y
| unos con horas fuera del ITCJ, para ver el estado «Capturada» y «Falta» en la validación de docentes.
*/

function seedCaptureScenario(bool $open = true): Term
{
    test()->actingAs(User::factory()->admin()->create());
    $term = Term::factory()->create(['code' => '20271']);

    if ($open) {
        Campus::factory()->create(['code' => 'C2']);
        app(OpenTerm::class)->handle($term, modalityOf('PRES'));
    }

    foreach (range(1, 200) as $n) {
        $teacher = Teacher::factory()->create(['rfc' => sprintf('T%04d', $n)]);
        TeacherAvailability::factory()->create(['teacher_id' => $teacher->id, 'term_id' => $term->id]);
        TeacherSubjectQualification::factory()->create(['teacher_id' => $teacher->id]);
    }

    return $term;
}

function runCaptureSeeder(string $profile = 'massive', bool $complete = false): void
{
    (new DatabaseSeeder)->setContainer(app())->callWith(TeacherCaptureDemoSeeder::class, ['profile' => $profile, 'complete' => $complete]);
}

it('rechaza correr en producción', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => runCaptureSeeder())->toThrow(RuntimeException::class, 'producción');
});

it('no hace nada sin periodos abiertos', function () {
    seedCaptureScenario(open: false);

    runCaptureSeeder();

    expect(TeacherAvailability::count())->toBe(200)->and(TeacherSubjectQualification::count())->toBe(200)->and(TeacherExternalLoad::count())->toBe(0);
});

it('deja docentes con y sin disponibilidad, perfil y horas fuera del ITCJ en las proporciones del perfil masivo', function () {
    $term = seedCaptureScenario();

    runCaptureSeeder('massive');

    $withAvailability = TeacherAvailability::query()->where('term_id', $term->id)->distinct()->count('teacher_id');
    $withProfile = TeacherSubjectQualification::query()->distinct()->count('teacher_id');
    $withExternal = TeacherExternalLoad::query()->where('term_id', $term->id)->distinct()->count('teacher_id');

    expect($withAvailability)->toBeBetween(130, 170)
        ->and($withProfile)->toBeBetween(150, 190)
        ->and($withExternal)->toBeBetween(40, 80);
});

it('es idempotente', function () {
    seedCaptureScenario();
    runCaptureSeeder();
    $first = [TeacherAvailability::count(), TeacherSubjectQualification::count(), TeacherExternalLoad::count()];

    runCaptureSeeder();

    expect([TeacherAvailability::count(), TeacherSubjectQualification::count(), TeacherExternalLoad::count()])->toBe($first);
    expect(DB::table('teacher_external_loads')->count())->toBe($first[2]);
});

it('lo que quita lo borra de forma lógica y no cuenta como capturado', function () {
    $term = seedCaptureScenario();

    runCaptureSeeder('massive');

    expect(TeacherAvailability::withTrashed()->count())->toBeGreaterThan(TeacherAvailability::count())
        ->and(TeacherSubjectQualification::onlyTrashed()->count())->toBe(200 - TeacherSubjectQualification::count())
        ->and(TeacherAvailability::query()->where('term_id', $term->id)->distinct()->count('teacher_id'))->toBeBetween(130, 170);

    $trashed = TeacherAvailability::withTrashed()->count();
    runCaptureSeeder('massive');

    expect(TeacherAvailability::withTrashed()->count())->toBe($trashed);
});

it('completa la captura que la variedad quitó: todo docente activo vuelve a tener su disponibilidad y su perfil, y las horas externas no cambian (demo por puntos, punto 3)', function () {
    $term = seedCaptureScenario();
    $original = TeacherAvailability::query()->orderBy('id')->pluck('id')->all();
    runCaptureSeeder();
    $external = TeacherExternalLoad::query()->count();
    $withoutAvailability = fn (): int => Teacher::query()->whereDoesntHave('availability', fn ($query) => $query->where('term_id', $term->id))->count();
    $withoutProfile = fn (): int => Teacher::query()->whereDoesntHave('qualifications')->count();

    expect($withoutAvailability())->toBeGreaterThan(0)->and($withoutProfile())->toBeGreaterThan(0);

    runCaptureSeeder(complete: true);

    expect($withoutAvailability())->toBe(0)
        ->and($withoutProfile())->toBe(0)
        ->and(TeacherAvailability::query()->orderBy('id')->pluck('id')->all())->toBe($original)
        ->and(TeacherExternalLoad::query()->count())->toBe($external);
});
