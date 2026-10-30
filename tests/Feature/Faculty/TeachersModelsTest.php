<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use Illuminate\Support\Carbon;

it('un docente tiene usuario opcional, adscripciones y plazas', function () {
    $user = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'degree' => TeacherDegree::Maestria]);
    TeacherPosition::factory()->for($teacher)->medioTiempo()->create();

    expect($teacher->fresh()->user->is($user))->toBeTrue()
        ->and($teacher->degree)->toBe(TeacherDegree::Maestria)
        ->and($teacher->positions)->toHaveCount(1)
        ->and(Teacher::factory()->create()->user)->toBeNull();
});

it('adscribedTo crea la adscripción y primaryAdscription devuelve la principal', function () {
    $department = Department::factory()->create();
    $teacher = Teacher::factory()->adscribedTo($department)->create();
    TeacherDepartment::factory()->for($teacher)->create(['is_primary' => false]);

    $primary = $teacher->primaryAdscription;

    expect($teacher->adscriptions)->toHaveCount(2)
        ->and($primary->department->is($department))->toBeTrue()
        ->and($primary->teacher->is($teacher))->toBeTrue()
        ->and($primary->is_primary)->toBeTrue();
});

it('adscribedTo con primary falso la deja como no principal', function () {
    $teacher = Teacher::factory()->adscribedTo(Department::factory()->create(), false)->create();

    expect($teacher->primaryAdscription)->toBeNull()
        ->and($teacher->adscriptions->sole()->is_primary)->toBeFalse();
});

it('el scope active deja solo a los activos y inactive los desactiva', function () {
    $active = Teacher::factory()->create();
    Teacher::factory()->inactive()->create();

    expect(Teacher::active()->pluck('id')->all())->toBe([$active->id]);
});

it('las plazas vigentes en una fecha cuentan el día de Ciudad Juárez', function () {
    $vigente = TeacherPosition::factory()->create(['valid_from' => '2027-01-01', 'valid_to' => '2027-01-18']);
    TeacherPosition::factory()->expired()->create();
    TeacherPosition::factory()->future()->create();
    $sinLimites = TeacherPosition::factory()->create(['valid_from' => null, 'valid_to' => null]);

    $ids = TeacherPosition::currentOn(Carbon::parse('2027-01-18 20:00', 'America/Ciudad_Juarez'))->pluck('id')->all();
    // 03:00 UTC del 19 es todavía el 18 en Ciudad Juárez.
    $utc = TeacherPosition::currentOn(Carbon::parse('2027-01-19 03:00', 'UTC'))->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$vigente->id, $sinLimites->id])
        ->and($utc)->toEqualCanonicalizing([$vigente->id, $sinLimites->id]);
});

it('los estados de la factory de plazas respetan la regla de su tipo', function () {
    $factory = TeacherPosition::factory();

    expect($factory->tiempoCompleto()->create())->kind->toBe(PositionKind::TiempoCompleto)->base_hours->toBe(40)
        ->and($factory->tresCuartos()->create())->base_hours->toBe(30)
        ->and($factory->medioTiempo()->create())->base_hours->toBe(20)
        ->and($factory->asignatura(12)->create())->kind->toBe(PositionKind::Asignatura)->base_hours->toBe(12)
        ->and($factory->honorarios()->create())->base_hours->toBe(0);
});

it('borrar un docente lo borra lógicamente', function () {
    $teacher = Teacher::factory()->create();
    $teacher->delete();

    expect(Teacher::find($teacher->id))->toBeNull()
        ->and(Teacher::withTrashed()->find($teacher->id))->not->toBeNull();
});
