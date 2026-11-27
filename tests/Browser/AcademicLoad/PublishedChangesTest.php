<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
| Qué cambió en la consulta pública, en el navegador (tarea 8.5): un alumno sin sesión abre el semestre de su carrera
| después de una republicación y ve «Actualizado el …» con la hora y la marca «Cambió» en el grupo que cambió, con su
| detalle, en celular y en escritorio, sin número de versión.
*/

beforeEach(function () {
    $term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027', 'starts_on' => '2027-01-25']);
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $placement = LoadSnapshotFactory::placement(['career' => ['id' => (string) Str::uuid7(), 'code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales'], 'study_plan' => ['id' => (string) Str::uuid7(), 'code' => 'ISIC-2010-224'], 'semester' => 3]);
    $room = ['id' => (string) Str::uuid7(), 'code' => 'B-12', 'name' => null, 'building' => ['id' => (string) Str::uuid7(), 'code' => 'B', 'name' => 'Edificio B'], 'owner_department_id' => null];
    $group = LoadSnapshotFactory::group([
        'code' => 'B',
        'campus' => ['id' => $campus->id, 'code' => 'C2', 'name' => 'Campus II'],
        'subject' => ['id' => (string) Str::uuid7(), 'code' => 'ACF-0904', 'name' => 'Cálculo Vectorial', 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => (string) Str::uuid7()],
        'teacher' => ['id' => (string) Str::uuid7(), 'rfc' => 'RIML800101AB1', 'name' => 'Laura Ríos Medina'],
        'placements' => [$placement],
        'sessions' => [LoadSnapshotFactory::session(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => $room])],
    ]);

    $load = AcademicLoad::factory()->for($term)->state(['modality_id' => modalityOf('PRES')->id])->for(Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']))->inStatus(LoadStatus::Published)->create();
    $first = LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse('2026-12-12 10:30'))->withGroups([$group])->create();

    $publisher = User::factory()->create();
    $at = CarbonImmutable::parse('2027-01-20 13:05');
    $payload = LoadSnapshotFactory::payload($load, 2, $publisher, [[...$group, 'sessions' => [LoadSnapshotFactory::session(['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => $room])]]], $at);
    LoadSnapshot::factory()->for($load)->publishedAt($at)->create([
        'version' => 2, 'created_by' => $publisher->id, 'payload' => $payload, 'reason' => SnapshotReason::Reanalysis,
        'diff' => SnapshotDiff::between(SnapshotPayload::normalize($first->payload), SnapshotPayload::normalize($payload)),
    ]);
});

it('un alumno sin sesión ve «Actualizado el» y la marca del grupo que cambió, en celular y en escritorio', function (int $width) {
    $page = visit('/horarios?periodo=20271')->resize($width, 900)->navigate('/horarios/20271/pres/carrera/ISC?reticula=ISIC-2010-224&semestre=3');

    $page->assertSee('Actualizado el 20 de enero de 2027 a las 13:05')
        ->assertSee('Cambió: horario')
        ->assertDontSee('Horario de lunes 7:00 a 9:00 a martes 7:00 a 9:00.')
        ->click('[data-change="schedule"] summary')
        ->assertSee('Horario de lunes 7:00 a 9:00 a martes 7:00 a 9:00.')
        ->assertDontSee('Versión')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
})->with(['celular' => 390, 'escritorio' => 1440]);
