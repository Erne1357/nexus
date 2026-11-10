<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\AcademicLoad\Queries\LoadVersionsQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
| El componente de publicar (tarea 4.3, DESIGN.md «Publicación»): la línea de versiones, por qué no se puede publicar,
| la confirmación con lo que se congelaría y el veto. Lo ve quien ve la carga; publica quien tiene `publish`.
*/

const PUBLISH_COMPONENT = 'academic-load::components.publish-load';

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->load = AcademicLoad::factory()->create(['campus_id' => $this->campus->id, 'department_id' => departmentKey(10), 'status' => LoadStatus::Closed]);
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Laura Méndez']);
});

/**
 * Un grupo de la carga sin choques duros, con docente con horas, salón donde cabe y sesión de 2 h.
 */
function componentGroup(): Group
{
    $teacher = Teacher::factory()->create();
    TeacherPosition::factory()->asignatura(20)->create(['teacher_id' => $teacher->id]);
    $room = Room::factory()->capacity(40)->create(['building_id' => Building::factory()->create(['campus_id' => test()->load->campus_id])->id, 'department_id' => departmentKey(10)]);
    $group = Group::factory()->withQuota()->create([
        'term_id' => test()->load->term_id, 'campus_id' => test()->load->campus_id, 'teacher_id' => $teacher->id, 'capacity' => 30,
        'subject_id' => Subject::factory()->create(['department_id' => departmentKey(10), 'theory_hours' => 2, 'practice_hours' => 0])->id,
    ]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $group->campus_id]);
    GroupSession::factory()->for($group)->inRoom($room)->on(1, '07:00', '09:00')->create();

    return $group;
}

function publishedVersion(int $version, string $at, ?array $groups = null): LoadSnapshot
{
    $factory = LoadSnapshot::factory()->for(test()->load)->publishedAt(CarbonImmutable::parse($at, 'America/Ciudad_Juarez'));

    return ($groups === null ? $factory : $factory->withGroups($groups))->create(['version' => $version, 'created_by' => test()->subdireccion->id]);
}

it('pinta una versión por publicación, la vigente marcada, y la siguiente para publicar', function () {
    publishedVersion(1, '2026-12-03 18:42');
    publishedVersion(2, '2026-12-10 10:31');

    Livewire::actingAs($this->subdireccion)->test(PUBLISH_COMPONENT, ['load' => $this->load->fresh()])
        ->assertSeeInOrder(['v1', '3 dic 2026', 'v2', '10 dic 2026', '· vigente', 'v3', 'Publicar versión 3', 'Reemplaza a la v2 en la consulta'])
        ->assertDontSee('No se puede publicar');
});

it('sin versiones ofrece la primera publicación', function () {
    Livewire::actingAs($this->subdireccion)->test(PUBLISH_COMPONENT, ['load' => $this->load])
        ->assertSee('La primera versión pública')
        ->assertSeeHtml('data-testid="publish-next"');
});

it('abrir una versión dice quién y cuándo la publicó y qué cambió contra la anterior', function () {
    $teacher = ['id' => fakeKey(1), 'rfc' => 'AAAA800101AA1', 'name' => 'Ana Ruiz'];
    $kept = LoadSnapshotFactory::group(['id' => fakeKey(10), 'code' => 'A', 'teacher' => $teacher]);
    publishedVersion(1, '2026-12-03 18:42', [$kept, LoadSnapshotFactory::group(['id' => fakeKey(11), 'code' => 'B'])]);
    publishedVersion(2, '2026-12-10 10:31', [array_replace($kept, ['teacher' => [...$teacher, 'id' => fakeKey(2)]]), LoadSnapshotFactory::group(['id' => fakeKey(12), 'code' => 'C'])]);
    LoadTransition::factory()->create(['academic_load_id' => $this->load->id, 'from_status' => LoadStatus::Closed, 'to_status' => LoadStatus::Published, 'comment' => 'Primera']);
    LoadTransition::factory()->create(['academic_load_id' => $this->load->id, 'from_status' => LoadStatus::Closed, 'to_status' => LoadStatus::Published, 'comment' => 'Ajuste de salones']);

    Livewire::actingAs($this->subdireccion)->test(PUBLISH_COMPONENT, ['load' => $this->load->fresh()])
        ->call('toggleVersion', 2)
        ->assertSee('Publicó Laura Méndez el 10 dic 2026 a las')
        ->assertSee('10:31')
        ->assertSee('Ajuste de salones')
        ->assertSee(['+1 grupo', '−1 grupo', '1 grupo cambió de docente'])
        ->call('toggleVersion', 2)
        ->assertSet('openVersion', null);
});

it('publicar desde la confirmación congela la carga, guarda el comentario y avisa a la pantalla', function () {
    componentGroup();
    componentGroup();

    Livewire::actingAs($this->subdireccion)->test(PUBLISH_COMPONENT, ['load' => $this->load])
        ->call('confirm')
        ->assertSet('confirming', true)
        ->assertSeeHtml('data-testid="publish-counts"')
        ->assertSeeInOrder(['Grupos', '2', 'Sesiones', '2', 'Docentes', '2', 'Salones', '2'])
        ->assertSee('La consulta pública mostrará esta versión.')
        ->set('comment', 'Primera publicación')
        ->call('publish')
        ->assertSet('confirming', false)
        ->assertSet('openVersion', 1)
        ->assertDispatched('load-published', loadId: $this->load->id, version: 1);

    expect($this->load->fresh()->status)->toBe(LoadStatus::Published)
        ->and($this->load->transitions()->sole()->comment)->toBe('Primera publicación')
        ->and(app(LoadVersionsQuery::class)->versions($this->load->fresh())[0])->toMatchArray(['version' => 1, 'groups' => 2, 'sessions' => 2, 'comment' => 'Primera publicación', 'publisher' => 'Laura Méndez']);
});

it('un veto se muestra en la confirmación y no publica', function () {
    componentGroup();
    Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->to === LoadStatus::Published ? $event->block('1 grupo tiene choques duros (HC-15).') : null);

    Livewire::actingAs($this->subdireccion)->test(PUBLISH_COMPONENT, ['load' => $this->load])
        ->call('confirm')
        ->call('publish')
        ->assertSet('confirming', true)
        ->assertSet('vetoes', ['1 grupo tiene choques duros (HC-15).'])
        ->assertSee('No se puede publicar')
        ->assertNotDispatched('load-published');

    expect(LoadSnapshot::query()->count())->toBe(0);
});

it('dice por qué no se puede publicar', function (Closure $arrange, string $reason) {
    $user = $arrange();

    Livewire::actingAs($user)->test(PUBLISH_COMPONENT, ['load' => $this->load->fresh()])
        ->assertSee('No se puede publicar')
        ->assertSee($reason)
        ->assertDontSeeHtml('data-testid="publish-next"');
})->with([
    'el jefe de proyecto docente' => [fn () => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => test()->campus->id, 'department_id' => departmentKey(10)])->create(), 'Publica Subdirección o el jefe del departamento.'],
    'ya publicada' => [function () {
        test()->load->update(['status' => LoadStatus::Published]);

        return test()->subdireccion;
    }, 'Ya está publicada. Para cambiarla, Subdirección la reabre.'],
    'todavía en asignación' => [function () {
        test()->load->update(['status' => LoadStatus::Staffing]);

        return test()->subdireccion;
    }, 'Se publica cuando la carga está cerrada.'],
    'con visto bueno, cerrada sin aprobar' => [function () {
        test()->campus->update(['settings' => ['requires_subdireccion_approval' => true]]);

        return test()->subdireccion;
    }, 'Primero va el visto bueno de Subdirección.'],
    'con visto bueno, el jefe' => [function () {
        test()->campus->update(['settings' => ['requires_subdireccion_approval' => true]]);

        return User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();
    }, 'En este campus publica Subdirección, después de su visto bueno.'],
]);

it('quien no puede publicar no abre la confirmación', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->campus->id, 'department_id' => departmentKey(10)])->create();

    Livewire::actingAs($jpd)->test(PUBLISH_COMPONENT, ['load' => $this->load])
        ->call('confirm')
        ->assertForbidden();

    $this->load->update(['status' => LoadStatus::Staffing]);

    Livewire::actingAs($this->subdireccion)->test(PUBLISH_COMPONENT, ['load' => $this->load->fresh()])
        ->call('confirm')
        ->assertSet('confirming', false);
});

it('no lo ve otro departamento, otro campus ni otro rol', function (Closure $user) {
    Livewire::actingAs($user())->test(PUBLISH_COMPONENT, ['load' => $this->load])->assertForbidden();
})->with([
    'jefe de otro departamento' => [fn () => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create()],
    'jefe de proyecto docente de otro campus' => [fn () => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => Campus::factory()->create()->id, 'department_id' => departmentKey(10)])->create()],
    'coordinador' => [fn () => User::factory()->withRole(Role::Coordinador, ['campus_id' => test()->campus->id, 'career_id' => careerKey(20)])->create()],
    'docente' => [fn () => User::factory()->withRole(Role::Docente)->create()],
]);

it('si dos versiones son del mismo día, el punto también dice la hora', function () {
    publishedVersion(1, '2026-12-10 08:05');
    publishedVersion(2, '2026-12-10 16:40');

    Livewire::actingAs($this->subdireccion)->test(PUBLISH_COMPONENT, ['load' => $this->load->fresh()])
        ->assertSeeInOrder(['10 dic 2026, 8:05', '10 dic 2026, 16:40']);
});
