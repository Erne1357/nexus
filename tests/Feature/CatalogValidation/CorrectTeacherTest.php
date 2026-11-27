<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\TeacherTeachingHoursQuery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CV17 (confirmado el 26 de noviembre de 2026): el jefe corrige desde su revisión el nombre, el grado, los correos, la
| fecha de ingreso y las plazas de sus docentes (tipo, horas, agregar, cerrar), con bitácora y sin cambiar el RFC.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    Campus::factory()->create(['code' => 'C2']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->otro = Department::factory()->create(['code' => 'IND', 'name' => 'Industrial']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle($this->term, Catalog::cases());
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->review = CatalogReview::query()->where('term_id', $this->term->id)->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->sole();
    $this->teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1', 'name' => 'Ana Activa', 'institutional_email' => 'ana@cdjuarez.tecnm.mx']);
    $this->plaza = TeacherPosition::create(['teacher_id' => $this->teacher->id, 'kind' => PositionKind::Asignatura, 'base_hours' => 10, 'valid_from' => '2026-01-01']);
});

function correctTeacherPage(?User $user = null, ?string $department = null)
{
    return Livewire::withQueryParams(['catalogo' => 'teachers', ...($department ? ['department' => $department] : [])])->actingAs($user ?? test()->jefe)->test('catalog-validation::pages.review');
}

function openTeacher(?User $user = null)
{
    return correctTeacherPage($user)->call('edit', test()->teacher->id);
}

it('CV17: el jefe corrige nombre, grado, correos y fecha de ingreso desde la revisión, con bitácora y revisión en curso', function () {
    openTeacher()
        ->assertSet('name', 'Ana Activa')
        ->set('name', 'Ana María Activa')
        ->set('degree', 'maestria')
        ->set('email', 'ana@correo.com')
        ->set('institutional_email', 'ana.maria@cdjuarez.tecnm.mx')
        ->set('hired_on', '2015-08-17')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->teacher->fresh())
        ->name->toBe('Ana María Activa')->degree->value->toBe('maestria')->email->toBe('ana@correo.com')
        ->institutional_email->toBe('ana.maria@cdjuarez.tecnm.mx')->hired_on->toDateString()->toBe('2015-08-17')
        ->rfc->toBe('AAAA800101AA1')
        ->and($this->review->fresh()->status)->toBe(ReviewStatus::InReview)
        ->and(Activity::query()->where('subject_type', $this->teacher->getMorphClass())->where('subject_id', $this->teacher->id)->where('causer_id', $this->jefe->id)->exists())->toBeTrue();
});

it('CV17: el jefe edita tipo y horas de una plaza, agrega una y cierra otra, todo con bitácora', function () {
    openTeacher()
        ->set('positions.0.kind', 'tiempo_completo')
        ->set('positions.0.base_hours', '40')
        ->call('addPositionRow')
        ->set('positions.1.kind', 'medio_tiempo')
        ->set('positions.1.base_hours', '20')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->plaza->fresh())->kind->toBe(PositionKind::TiempoCompleto)->base_hours->toBe(40)
        ->and($this->teacher->positions()->count())->toBe(2)
        ->and(Activity::query()->where('subject_type', $this->plaza->getMorphClass())->count())->toBeGreaterThanOrEqual(2)
        ->and($this->review->fresh()->status)->toBe(ReviewStatus::InReview);

    openTeacher()->call('closePositionRow', 0)->set('positions.0.valid_to', '2026-12-31')->call('save')->assertHasNoErrors();

    expect($this->plaza->fresh()->valid_to->toDateString())->toBe('2026-12-31');
});

it('CV17: el RFC no se cambia aunque llegue (la pantalla no lo ofrece y CorrectRecord lo ignora)', function () {
    openTeacher()->assertSee('AAAA800101AA1')->set('name', 'Otro Nombre')->call('save');

    expect($this->teacher->fresh()->rfc)->toBe('AAAA800101AA1');
});

it('CV17: errores en español junto al campo, también en la fila de una plaza, y no queda nada guardado', function () {
    openTeacher()
        ->set('name', 'Nombre nuevo')
        ->set('positions.0.kind', 'tiempo_completo')
        ->set('positions.0.base_hours', '12')
        ->call('save')
        ->assertHasErrors(['positions.0.base_hours'])
        ->assertSee('Las horas base de tiempo completo son 40.');

    expect($this->teacher->fresh()->name)->toBe('Ana Activa')->and($this->plaza->fresh()->base_hours)->toBe(10);

    openTeacher()->set('institutional_email', 'ana@gmail.com')->call('save')->assertHasErrors(['institutional_email']);
    openTeacher()->set('name', '')->call('save')->assertHasErrors(['name']);
});

it('CV17 y CV-S7: guardar sin cambios deja revisado al docente sin tocar sus datos ni sus plazas', function () {
    openTeacher()->call('save')->assertHasNoErrors();

    expect($this->teacher->fresh()->name)->toBe('Ana Activa')
        ->and($this->plaza->fresh()->base_hours)->toBe(10)
        ->and($this->review->marks()->where('record_id', $this->teacher->id)->exists())->toBeTrue();
});

it('CV17: el jefe de otro departamento no corrige a este docente', function () {
    $otro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->otro->id])->create();

    openTeacher($otro)->assertSet('editingId', null);
    expect($this->teacher->fresh()->name)->toBe('Ana Activa');
});

it('CV17: quien no es jefe (docente, jefe de proyecto docente) no corrige', function (Role $role) {
    $user = User::factory()->withRole($role, ['department_id' => $this->sis->id])->create();

    expect(Gate::forUser($user)->allows('correct', $this->review))->toBeFalse();
    $this->actingAs($user)->get(route('catalog-validation.review', ['catalogo' => 'teachers', 'department' => $this->sis->id]))->assertForbidden();
    expect($this->teacher->fresh()->name)->toBe('Ana Activa');
})->with([Role::Docente, Role::JefeProyectoDocente]);

it('CV17 y CV-S2: una revisión validada no se corrige', function () {
    $this->review->forceFill(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()])->save();

    openTeacher()->set('name', 'Otro')->call('save');

    expect($this->teacher->fresh()->name)->toBe('Ana Activa');
});

it('CV17: una plaza que no es del docente se rechaza', function () {
    $ajena = TeacherPosition::create(['teacher_id' => Teacher::factory()->create()->id, 'kind' => PositionKind::Honorarios, 'base_hours' => 0, 'valid_from' => '2026-01-01']);

    openTeacher()->set('positions.0.id', $ajena->id)->set('positions.0.kind', 'tiempo_completo')->set('positions.0.base_hours', '40')->call('save')->assertHasErrors();

    expect($ajena->fresh()->kind)->toBe(PositionKind::Honorarios);
});

it('CV17 y PL-01: el jefe elige la categoría legible y se guarda la clave del catálogo, con bitácora', function (string $kind, string $hours, string $category, string $clave) {
    openTeacher()
        ->set('positions.0.kind', $kind)
        ->set('positions.0.base_hours', $hours)
        ->set('positions.0.category', $category)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->plaza->fresh()->category)->toBe($clave)
        ->and(Activity::query()->where('subject_id', $this->plaza->id)->where('causer_id', $this->jefe->id)->latest('id')->first()->attribute_changes['attributes'])->toHaveKey('category');
})->with([
    'titular B de tiempo completo' => ['tiempo_completo', '40', 'E3815', 'E3815'],
    'asociado A de tres cuartos' => ['tres_cuartos', '30', 'E3707', 'E3707'],
    'investigador titular C de medio tiempo' => ['medio_tiempo', '20', 'E3663', 'E3663'],
    'asignatura B' => ['asignatura', '10', 'E3521', 'E3521'],
]);

it('CV17 y PL-01: la pantalla ofrece nombres, no claves, y vacía es sin categoría', function () {
    openTeacher()
        ->set('positions.0.kind', 'tiempo_completo')
        ->assertSee('Titular B')->assertSee('Sin categoría (se mide como titular)')
        ->set('positions.0.base_hours', '40')->set('positions.0.category', 'E3815')->call('save');

    expect($this->plaza->fresh()->category)->toBe('E3815');

    openTeacher()->set('positions.0.category', '')->call('save')->assertHasNoErrors();

    expect($this->plaza->fresh()->category)->toBeNull();
});

it('CV17 y PL-01: cambiar el tipo limpia la categoría y una clave de otro tipo se rechaza', function () {
    $this->plaza->update(['category' => 'E3519']);

    openTeacher()->set('positions.0.kind', 'tiempo_completo')->assertSet('positions.0.category', '');

    openTeacher()->set('positions.0.category', 'E3815')->call('save')->assertHasErrors(['positions.0.category']);
    expect($this->plaza->fresh()->category)->toBe('E3519');
});

it('CV17 y PL-01: cambiar la categoría recalcula las horas frente a grupo y avisa con PositionChanged', function () {
    $this->plaza->update(['kind' => PositionKind::TiempoCompleto, 'base_hours' => 40, 'category' => 'E3815']);
    $query = fn (): int => app(TeacherTeachingHoursQuery::class)->onDate(now(), [$this->teacher->id])[$this->teacher->id];
    expect($query())->toBe(22);

    Event::fake([PositionChanged::class]);
    openTeacher()->set('positions.0.category', 'E3809')->call('save')->assertHasNoErrors();

    Event::assertDispatched(PositionChanged::class);
    expect($query())->toBe(24);
});

it('CV17 y PL-01: el jefe de otro departamento no cambia la categoría', function () {
    $otro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->otro->id])->create();

    openTeacher($otro)->assertSet('editingId', null);
    expect(Gate::forUser($otro)->allows('correct', $this->review))->toBeFalse()
        ->and($this->plaza->fresh()->category)->toBeNull();
});
