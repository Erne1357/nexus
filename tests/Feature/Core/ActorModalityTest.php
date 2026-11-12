<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Authorization\AuthorizationSnapshot;
use App\Modules\Core\Authorization\AuthorizationSnapshots;
use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Authorization\FakePermission;

/*
| AZ-S17: el alcance por modalidad, con el campus opcional (M5, M7 y M9, confirmados el 12 de noviembre de 2026; MS6 y
| MS8, supuestos por confirmar). Un nombramiento sin modalidad cubre todas las activas; uno con modalidad, solo esa y
| solo si está activa y sin borrar (MS8); uno sin modalidad cubre también las inactivas . Lo que se lee declara la modalidad sin el campus; lo que se escribe sobre algo con
| campus declara también el campus. Permisos de prueba: FakePermission::ViewModality*. Carreras X (20) e Y (21),
| departamento 10. EAD se activa en estas pruebas; En línea sigue inactiva.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00', 'America/Ciudad_Juarez'));
    useFakePermissions();

    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->ead->update(['is_active' => true]);
    $this->enl = modalityOf('ENL');
    $this->x = careerKey(20);
    $this->y = careerKey(21);
});

function modalityActor(User $user): Actor
{
    app()->forgetScopedInstances();
    test()->actingAs($user);

    return app(Actor::class);
}

it('AZ-S17: un coordinador de Presencial en C2 lee su carrera en la modalidad sin importar el campus y escribe solo en C2', function () {
    $actor = modalityActor(User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->x])->create());

    expect($actor->allows(FakePermission::ViewModalityCareer, Target::of(modality: $this->pres->id, career: $this->x)))->toBeTrue()
        ->and($actor->allows(FakePermission::ViewModalityCampusCareer, Target::of(modality: $this->pres->id, career: $this->x, campus: $this->c2->id)))->toBeTrue()
        ->and($actor->allows(FakePermission::ViewModalityCampusCareer, Target::of(modality: $this->pres->id, career: $this->x, campus: $this->c1->id)))->toBeFalse()
        ->and($actor->allows(FakePermission::ViewModalityCareer, Target::of(modality: $this->ead->id, career: $this->x)))->toBeFalse()
        ->and($actor->allows(FakePermission::ViewModalityCareer, Target::of(modality: $this->pres->id, career: $this->y)))->toBeFalse()
        ->and($actor->allows(FakePermission::ViewModalityCareer, Target::of(career: $this->x)))->toBeFalse();
});

it('AZ-S17: un coordinador de Presencial sin campus escribe en todos los campus de su modalidad, y en ninguno de otra', function () {
    $actor = modalityActor(User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => $this->x])->create());

    expect($actor->allows(FakePermission::ViewModalityCampusCareer, Target::of(modality: $this->pres->id, career: $this->x, campus: $this->c1->id)))->toBeTrue()
        ->and($actor->allows(FakePermission::ViewModalityCampusCareer, Target::of(modality: $this->pres->id, career: $this->x, campus: $this->c2->id)))->toBeTrue()
        ->and($actor->allows(FakePermission::ViewModalityCampusCareer, Target::of(modality: $this->ead->id, career: $this->x, campus: $this->c2->id)))->toBeFalse()
        ->and($actor->campuses()->pluck('code')->all())->toBe(['C1', 'C2']);
});

it('MS8: un nombramiento en una modalidad desactivada o borrada no cuenta para nada', function (string $retired) {
    $user = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->x])->create();
    $retired === 'borrada' ? $this->pres->delete() : $this->pres->update(['is_active' => false]);

    $actor = modalityActor($user);

    expect($actor->heldRoles())->toBe([])
        ->and($actor->heldScopes())->toBeEmpty()
        ->and($actor->allows(FakePermission::ViewModalityCareer, Target::of(modality: $this->pres->id, career: $this->x)))->toBeFalse()
        ->and($actor->allows(FakePermission::ViewCampusCareer, Target::of(campus: $this->c2->id, career: $this->x)))->toBeFalse()
        ->and($actor->allowsAny(FakePermission::ViewModalityCareer))->toBeFalse()
        ->and($actor->modalities())->toBeEmpty()
        ->and($actor->campuses())->toBeEmpty();
})->with(['desactivada', 'borrada']);

it('MS8: desactivar la modalidad deja sin acceso aunque el snapshot ya esté en caché, y reactivarla lo devuelve', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => $this->x])->create();
    $target = Target::of(modality: $this->pres->id, career: $this->x);

    expect(modalityActor(User::find($user->id))->allows(FakePermission::ViewModalityCareer, $target))->toBeTrue();

    $this->pres->update(['is_active' => false]);
    expect(modalityActor(User::find($user->id))->allows(FakePermission::ViewModalityCareer, $target))->toBeFalse();

    $this->pres->update(['is_active' => true]);
    expect(modalityActor(User::find($user->id))->allows(FakePermission::ViewModalityCareer, $target))->toBeTrue();
});

it('AZ-S17: un jefe de departamento, que no fija modalidad, cubre todas las modalidades activas y no las inactivas', function () {
    $actor = modalityActor(User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => departmentKey(10)])->create());

    expect($actor->allows(FakePermission::ViewModalityUnit, Target::of(modality: $this->pres->id, unit: departmentKey(10))))->toBeTrue()
        ->and($actor->allows(FakePermission::ViewModalityUnit, Target::of(modality: $this->ead->id, unit: departmentKey(10))))->toBeTrue()
        ->and($actor->allows(FakePermission::ViewModalityUnit, Target::of(modality: $this->enl->id, unit: departmentKey(10))))->toBeFalse()
        ->and($actor->allows(FakePermission::ViewModalityUnit, Target::of(unit: departmentKey(10))))->toBeTrue()
        ->and($actor->modalities()->pluck('code')->all())->toBe(['EAD', 'PRES']);
});

it('Actor::modalities(): las del perfil activo; todas las activas para el admin y para quien no fija modalidad', function () {
    $coordinator = User::factory()
        ->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => $this->x])
        ->withRole(Role::Docente)
        ->create();

    $this->actingAs($coordinator)->withSession([Actor::ACTIVE_ROLE => Role::Coordinador->value]);
    app()->forgetScopedInstances();
    $asCoordinator = app(Actor::class);

    expect($asCoordinator->modalities()->pluck('code')->all())->toBe(['PRES'])
        ->and($asCoordinator->asRole(Role::Docente)->modalities()->pluck('code')->all())->toBe(['EAD', 'PRES'])
        ->and(modalityActor(User::factory()->admin()->create())->modalities()->pluck('code')->all())->toBe(['EAD', 'PRES'])
        ->and(modalityActor(User::factory()->create())->modalities())->toBeEmpty();
});

it('AZ-S17: constrain() deja fuera las filas de otra modalidad y, con campus, las de otro campus', function () {
    Schema::create('modality_probes', function (Blueprint $table): void {
        $table->id();
        $table->uuid('modality_id')->nullable();
        $table->uuid('campus_id')->nullable();
        $table->uuid('career_id')->nullable();
    });

    $rows = [
        'pres-c1-x' => [$this->pres->id, $this->c1->id, $this->x],
        'pres-c2-x' => [$this->pres->id, $this->c2->id, $this->x],
        'pres-c2-y' => [$this->pres->id, $this->c2->id, $this->y],
        'ead-c2-x' => [$this->ead->id, $this->c2->id, $this->x],
        'enl-c2-x' => [$this->enl->id, $this->c2->id, $this->x],
        'sin-c2-x' => [null, $this->c2->id, $this->x],
    ];
    $ids = [];

    foreach ($rows as $name => [$modality, $campus, $career]) {
        $ids[DB::table('modality_probes')->insertGetId(['modality_id' => $modality, 'campus_id' => $campus, 'career_id' => $career])] = $name;
    }

    $probe = new class extends Model
    {
        protected $table = 'modality_probes';

        public $timestamps = false;
    };
    $columns = ['modality' => 'modality_probes.modality_id', 'campus' => 'modality_probes.campus_id', 'career' => 'modality_probes.career_id'];
    $names = fn (Builder $query): array => array_map(fn (int $id): string => $ids[$id], $query->orderBy('id')->pluck('id')->all());

    $ofC2 = modalityActor(User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->x])->create());

    expect($names($ofC2->constrain($probe->newQuery(), FakePermission::ViewModalityCampusCareer, $columns)))->toBe(['pres-c2-x'])
        ->and($names($ofC2->constrain($probe->newQuery(), FakePermission::ViewModalityCareer, $columns)))->toBe(['pres-c1-x', 'pres-c2-x']);

    $ofAll = modalityActor(User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => $this->x])->create());

    expect($names($ofAll->constrain($probe->newQuery(), FakePermission::ViewModalityCampusCareer, $columns)))->toBe(['pres-c1-x', 'pres-c2-x']);

    $head = modalityActor(User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => departmentKey(10)])->create());

    expect($names($head->constrain($probe->newQuery(), FakePermission::ViewModality, $columns)))->toBe(['pres-c1-x', 'pres-c2-x', 'pres-c2-y', 'ead-c2-x', 'sin-c2-x']);
});

it('constrain() pide la modalidad como columna, no como Closure', function () {
    $actor = modalityActor(User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => departmentKey(10)])->create());

    expect(fn () => $actor->constrain(User::query(), FakePermission::ViewModality, ['modality' => fn (Builder $query, array $values) => null]))
        ->toThrow(LogicException::class, 'la modalidad es una columna');
});

it('la dimensión modalidad vive en modality_id', function () {
    expect(Dimension::Modality->value)->toBe('modality')
        ->and(Dimension::Modality->column())->toBe('modality_id')
        ->and(Target::of(modality: 'm1')->values(Dimension::Modality))->toBe(['m1'])
        ->and(Target::of(campus: 'c1')->values(Dimension::Modality))->toBeNull();
});

it('un snapshot cacheado sin modality_id se descarta y se vuelve a leer de la base', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->x])->create();
    $fresh = User::find($user->id);
    $key = 'authz:user:v'.AuthorizationSnapshots::FORMAT.":{$fresh->id}:{$fresh->authz_version}";
    Cache::put($key, ['scopes' => [['role' => 'coordinador', 'campus_id' => null, 'org_unit_id' => null, 'career_id' => $this->x]]]);

    $snapshot = app(AuthorizationSnapshots::class)->for($fresh);

    expect(AssignmentScope::tryFromArray(['role' => 'coordinador', 'campus_id' => null, 'org_unit_id' => null, 'career_id' => $this->x]))->toBeNull()
        ->and(AuthorizationSnapshots::FORMAT)->toBe(3)
        ->and($snapshot->scopes())->toEqual([new AssignmentScope(Role::Coordinador, $this->c2->id, null, $this->x, $this->pres->id)])
        ->and(AuthorizationSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
});

it('MS8: con EAD inactiva, quien fija EAD o PRES no la cubre con un permiso de gestión, y el jefe sin modalidad tampoco', function () {
    $this->ead->update(['is_active' => false]);
    $onEad = Target::of(modality: $this->ead->id, unit: departmentKey(10), career: $this->x);

    $head = modalityActor(User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => departmentKey(10)])->create());
    $headCovers = $head->allows(FakePermission::ViewModalityUnit, $onEad);
    $ofEad = modalityActor(User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->ead->id, 'career_id' => $this->x])->create());
    $ofEadCovers = $ofEad->allows(FakePermission::ViewModalityCareer, $onEad);
    $ofPres = modalityActor(User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => $this->x])->create());
    $ofPresCovers = $ofPres->allows(FakePermission::ViewModalityCareer, $onEad);

    expect($headCovers)->toBeFalse()->and($ofEadCovers)->toBeFalse()->and($ofPresCovers)->toBeFalse();
});
