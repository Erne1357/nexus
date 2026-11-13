<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
| La consulta pública de lo publicado en el navegador (tarea 4.4): un invitado, sin cuenta, entra a /horarios, elige una
| carrera y su semestre, ve las fichas de las materias con su mini-semana y abre la vista de un docente.
*/

beforeEach(function () {
    $term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027', 'starts_on' => '2027-01-25']);
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $career = ['id' => (string) Str::uuid7(), 'code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales'];
    $plan = ['id' => (string) Str::uuid7(), 'code' => 'ISIC-2010-224'];
    $placement = LoadSnapshotFactory::placement(['career' => $career, 'study_plan' => $plan, 'semester' => 3]);
    $room = ['id' => (string) Str::uuid7(), 'code' => 'B-12', 'name' => 'Aula B-12', 'building' => ['id' => (string) Str::uuid7(), 'code' => 'B', 'name' => 'Edificio B'], 'owner_department_id' => null];

    $group = LoadSnapshotFactory::group([
        'code' => 'B',
        'subject' => ['id' => (string) Str::uuid7(), 'code' => 'ACF-0904', 'name' => 'Cálculo Vectorial', 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => (string) Str::uuid7()],
        'teacher' => ['id' => (string) Str::uuid7(), 'rfc' => 'RIML800101AB1', 'name' => 'Laura Ríos Medina'],
        'placements' => [$placement],
        'sessions' => [LoadSnapshotFactory::session(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => $room])],
    ]);

    $load = AcademicLoad::factory()->for($term)->state(['modality_id' => modalityOf('PRES')->id])->for(Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']))->inStatus(LoadStatus::Published)->create();
    LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse('2026-12-12 10:30'))->withGroups([$group])->create();
});

it('un invitado elige una carrera y su semestre, ve las fichas y abre la vista de un docente', function () {
    visit('/horarios?periodo=20271&campus=C2')
        ->assertSee('Horarios')
        ->assertSee('Ingeniería en Sistemas Computacionales')
        ->assertSee('Iniciar sesión')
        ->click('[aria-label="ISC, semestre 3"]')
        ->assertSee('Semestre 3')
        ->assertSee('ACF-0904')
        ->assertSee('Cálculo Vectorial')
        ->assertSee('B-12')
        ->click('Laura Ríos Medina')
        ->assertSee('1 grupo')
        ->assertSee('ACF-0904 · B')
        ->assertSee('Actualizado el 12 de diciembre de 2026')
        ->assertNoJavaScriptErrors();
});
