<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as SpatieRole;

/*
| Validación de catálogos (`/catalog-validation`, catalog-validation::pages.review), variante C «tablero de
| tarjetas» (DESIGN.md). El jefe ve su departamento, valida o reabre cada catálogo (CV5 veta reabrir con la carga
| fuera de borrador), corrige materias, carreras, planes y salones (CV1, CV2) y pone observaciones (CV4). Admin y
| Subdirección abren cualquier departamento con ?department= solo en lectura: resuelven observaciones, nada más
| (CV-S1). Los demás roles reciben 403.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'settings' => [...CampusProfile::DEFAULTS, 'heads_edit_rooms' => true]]);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);
    app(CreateTermReviews::class)->handle($this->term, $this->campus);

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->subject = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Programación Orientada a Objetos', 'department_id' => $this->sis->id, 'theory_hours' => 3, 'practice_hours' => 0, 'credits' => 5]);
    Subject::factory()->inactive()->create(['code' => 'SCA-1002', 'name' => 'Administración de Redes', 'department_id' => $this->sis->id]);
    Subject::factory()->create(['code' => 'AEF-1009', 'name' => 'Circuitos', 'department_id' => $this->ele->id]);
});

function reviewOf(Catalog $catalog, ?Department $department = null): CatalogReview
{
    return CatalogReview::query()
        ->where('term_id', test()->term->id)
        ->where('department_id', ($department ?? test()->sis)->id)
        ->where('catalog', $catalog)
        ->sole();
}

function reviewPage(?User $user = null, array $query = [])
{
    return Livewire::withQueryParams($query)->actingAs($user ?? test()->jefe)->test('catalog-validation::pages.review');
}

describe('quién entra', function () {
    it('el jefe ve su departamento: una tarjeta por catálogo y el avance', function () {
        reviewPage()
            ->assertOk()
            ->assertSee('Validación de catálogos')
            ->assertSee('Sistemas y Computación')
            ->assertSee('0 de 5')
            ->assertSee('Faltan 5 para sacar la carga de borrador')
            ->assertSee('Docentes')->assertSee('Salones')->assertSee('Materias')->assertSee('Planes de estudio')->assertSee('Carreras');
    });

    it('la ruta responde al jefe con su enlace en el menú de Académico', function () {
        $this->actingAs($this->jefe)->get(route('catalog-validation.review'))->assertOk()->assertSee('Validación de catálogos');
    });

    it('el jefe de un departamento no abre el de otro', function () {
        reviewPage($this->jefe, ['department' => $this->ele->id])->assertForbidden();
    });

    it('un jefe de varios departamentos elige entre ellos', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
        RoleScope::factory()->for($jefe)->create(['role' => Role::JefeDepartamento, 'department_id' => $this->ele->id]);

        reviewPage($jefe)->assertSee('Sistemas y Computación')->assertSee('Eléctrica-Electrónica');
        reviewPage($jefe, ['department' => $this->ele->id])->assertOk()->assertSee('ELE');
    });

    it('coordinador, jefe de proyecto docente y docente reciben 403', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, $scope)->create();

        reviewPage($user)->assertForbidden();
        $this->actingAs($user)->get(route('catalog-validation.review'))->assertForbidden();
    })->with([
        'coordinador' => [Role::Coordinador, fn () => ['campus_id' => test()->campus->id, 'career_id' => Career::factory()->create()->id]],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['campus_id' => test()->campus->id, 'department_id' => test()->sis->id]],
        'docente' => [Role::Docente, fn () => ['campus_id' => test()->campus->id]],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('catalog-validation.review'))->assertRedirect(route('login'));
    });

    it('admin y Subdirección abren cualquier departamento con ?department= en lectura', function (string $who) {
        $user = $who === 'admin' ? User::factory()->admin()->create() : User::factory()->withRole(Role::Subdireccion, [])->create();

        reviewPage($user, ['department' => $this->ele->id])
            ->assertOk()
            ->assertSee('Eléctrica-Electrónica')
            ->assertDontSee('data-testid="validate-subjects"', escape: false);
    })->with(['admin', 'subdirección']);

    it('sin ?department=, admin y Subdirección eligen un departamento', function () {
        reviewPage(User::factory()->admin()->create())->assertOk()->assertSee('Elige un departamento');
    });
});

describe('estados vacíos', function () {
    it('sin periodo activo dice que no hay periodo', function () {
        $this->term->update(['is_current' => false]);

        reviewPage()->assertOk()->assertSee('No hay un periodo activo');
    });

    it('con el periodo sin abrir en el campus (sin revisiones) lo dice', function () {
        CatalogReview::query()->delete();

        reviewPage()->assertOk()->assertSee('no tiene revisiones de catálogo');
    });
});

describe('tablero', function () {
    it('cuenta los validados y el total baja a 4 si el campus no usa salones', function () {
        $this->campus->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
        CatalogReview::query()->where('catalog', Catalog::Rooms)->delete();
        reviewOf(Catalog::Careers)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertSee('1 de 4')->assertSee('Faltan 3 para sacar la carga de borrador');
    });

    it('HC-14: si el campus usa salones y falta su revisión, la carga no puede salir de borrador aunque lo demás esté validado', function () {
        CatalogReview::query()->where('catalog', Catalog::Rooms)->delete();
        CatalogReview::query()->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()
            ->assertSee('4 de 5')
            ->assertSee('Faltan 1 para sacar la carga de borrador')
            ->assertDontSee('Todo validado');
    });

    it('con los cinco validados dice que la carga puede salir de borrador', function () {
        CatalogReview::query()->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertSee('5 de 5')->assertSee('Todo validado');
    });

    it('la revisión de salones de un campus donde el alcance del jefe no vale no se muestra (CatalogReviewPolicy::view)', function () {
        /*
        | El jefe es de SIS solo en C1 y además coordina en C2 (otro rol, con su rol de spatie), así que C2 está en su
        | credencial y puede ser su campus activo. En C2 su alcance de jefe no vale: las revisiones generales siguen
        | visibles (son de todo el Tec) y la de salones de C2, no.
        */
        $c1 = Campus::factory()->create(['code' => 'C1']);
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id, 'campus_id' => $c1->id])->create();
        $jefe->assignRole(SpatieRole::findOrCreate(Role::Coordinador->value));
        RoleScope::factory()->for($jefe)->create(['role' => Role::Coordinador, 'campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id]);
        session([ScopedContext::ACTIVE_CAMPUS => $this->campus->id]);

        reviewPage($jefe)
            ->assertSee($this->campus->name)
            ->assertSeeHtml('data-testid="card-subjects"')
            ->assertDontSeeHtml('data-testid="card-rooms"');
    });

    it('en el primer periodo en Nexus lo dice y no muestra altas ni bajas', function () {
        reviewPage()->assertSee('Primer periodo en Nexus')->assertDontSee('altas');
    });

    it('con la foto de un periodo anterior cuenta las altas y las bajas con su lista', function () {
        $previous = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-15']);
        $gone = Subject::factory()->create(['code' => 'OLD-1', 'name' => 'Materia que se fue', 'department_id' => $this->sis->id]);
        CatalogReview::factory()->general(Catalog::Subjects)->validated()->create([
            'term_id' => $previous->id, 'department_id' => $this->sis->id,
            'snapshot' => [['id' => $this->subject->id, 'code' => 'SCD-1027', 'name' => 'x'], ['id' => $gone->id, 'code' => 'OLD-1', 'name' => 'Materia que se fue']],
        ]);
        $gone->update(['is_active' => false]);
        Subject::factory()->create(['code' => 'NEW-1', 'name' => 'Materia nueva', 'department_id' => $this->sis->id]);
        $this->term->update(['starts_on' => '2027-01-15', 'ends_on' => '2027-06-10']);

        reviewPage()->assertSee('Materia que se fue')->assertSeeInOrder(['+1', 'alta', '−1', 'baja']);
    });

    it('muestra quién y cuándo validó, y los contadores', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertSee('Validó '.$this->jefe->name)->assertSee('1 de 5')->assertSee('Inactivos');
    });

    it('valida desde la tarjeta con confirmación: cambia el estado y deja bitácora', function () {
        reviewPage()
            ->call('confirm', 'validate', 'subjects')
            ->assertSee('Vas a validar')
            ->call('confirmed')
            ->assertHasNoErrors();

        $review = reviewOf(Catalog::Subjects);

        expect($review->status)->toBe(ReviewStatus::Validated)
            ->and($review->validated_by)->toBe($this->jefe->id)
            ->and(Activity::query()->where('event', 'validated')->where('subject_id', $review->id)->exists())->toBeTrue();
    });

    it('reabre una revisión validada y deja bitácora', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->call('confirm', 'reopen', 'subjects')->call('confirmed')->assertHasNoErrors();

        expect(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::InReview)
            ->and(Activity::query()->where('event', 'reopened')->exists())->toBeTrue();
    });

    it('CV5: con la carga fuera de borrador, Reabrir queda deshabilitado con su motivo y no reabre', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);
        AcademicLoad::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->sis->id, 'status' => LoadStatus::Proposed]);

        reviewPage()
            ->assertSee('La carga académica de SIS ya salió de borrador en C2.')
            ->call('confirm', 'reopen', 'subjects')
            ->assertHasErrors('status')
            ->assertSee('La carga académica de SIS ya salió de borrador en C2.')
            ->assertSet('showConfirm', false);

        expect(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::Validated);
    });

    it('Subdirección y el admin no validan ni reabren, ni llamando los métodos directo', function (string $who) {
        $user = $who === 'admin' ? User::factory()->admin()->create() : User::factory()->withRole(Role::Subdireccion, [])->create();

        reviewPage($user, ['department' => $this->sis->id])->call('confirm', 'validate', 'subjects')->assertForbidden();
        reviewPage($user, ['department' => $this->sis->id])->call('confirm', 'reopen', 'subjects')->assertForbidden();

        expect(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::Pending);
    })->with(['admin', 'subdirección']);

    it('otro jefe no valida el departamento de otro llamando el método directo', function () {
        $otro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create();

        reviewPage($otro, ['department' => $this->sis->id])->assertForbidden();
    });
});

describe('lista de un catálogo', function () {
    it('lista las materias con clave, nombre y horas, con búsqueda y filtro de estado', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSee('SCD-1027')->assertSee('Programación Orientada a Objetos')->assertSee('SCA-1002')
            ->assertDontSee('AEF-1009')
            ->set('search', 'Redes')->assertSee('SCA-1002')->assertDontSee('SCD-1027')
            ->set('search', '')->set('status', 'inactive')->assertSee('SCA-1002')->assertDontSee('SCD-1027');
    });

    it('filtra por observación abierta', function () {
        CatalogReviewFlag::factory()->on($this->subject)->create(['review_id' => reviewOf(Catalog::Subjects)->id, 'flag' => ReviewFlag::MissingData]);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSee('Faltan datos')
            ->set('flagFilter', 'with')->assertSee('SCD-1027')->assertDontSee('SCA-1002')
            ->set('flagFilter', 'without')->assertSee('SCA-1002')->assertDontSee('SCD-1027');
    });

    it('lista las columnas propias de salones, planes, carreras y docentes', function () {
        $building = Building::factory()->create(['campus_id' => $this->campus->id, 'code' => 'ED-K']);
        Room::factory()->for($building)->create(['code' => 'K-201', 'capacity' => 38, 'department_id' => $this->sis->id]);
        $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas Computacionales', 'department_id' => $this->sis->id]);
        StudyPlan::factory()->create(['code' => 'ISIC-2010', 'career_id' => $career->id]);
        Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '04230', 'name' => 'Ana Torres']);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])->assertSee('K-201')->assertSee('ED-K')->assertSee('38');
        reviewPage($this->jefe, ['catalogo' => 'study_plans'])->assertSee('ISIC-2010')->assertSee('ISC');
        reviewPage($this->jefe, ['catalogo' => 'careers'])->assertSee('Sistemas Computacionales');
        reviewPage($this->jefe, ['catalogo' => 'teachers'])->assertSee('04230')->assertSee('Ana Torres')->assertSee(route('faculty.teachers.show', Teacher::where('employee_number', '04230')->sole()->id), escape: false);
    });

    it('un catálogo que no existe cae al tablero', function () {
        reviewPage($this->jefe, ['catalogo' => 'nada'])->assertSee('0 de 5');
    });
});

describe('corregir desde el drawer', function () {
    it('CV1: corrige una materia con CorrectRecord: guarda, avisa y pasa la revisión a en revisión', function () {
        $component = reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->assertSet('name', 'Programación Orientada a Objetos')
            ->set('practice_hours', '2')
            ->set('name', 'Programación Orientada a Objetos I')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->subject->fresh())->practice_hours->toBe(2)->name->toBe('Programación Orientada a Objetos I')
            ->and(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::InReview)
            ->and(lastToastOf($component)['title'])->toBe('Materia actualizada');
    });

    it('muestra el error de validación de la Action en la pantalla', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->set('credits', '-1')
            ->call('save')
            ->assertHasErrors('credits');
    });

    it('el registro de otro departamento no se abre', function () {
        $foreign = Subject::where('code', 'AEF-1009')->sole();

        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('edit', $foreign->id)->assertNotFound();
    });

    it('una revisión validada no se corrige: pide reabrirla y muestra el error', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->set('name', 'Otro nombre')
            ->call('save')
            ->assertHasErrors('status')
            ->assertSee('reábrela para corregir u observar');

        expect($this->subject->fresh()->name)->toBe('Programación Orientada a Objetos');
    });

    it('desactiva una materia desde el menú de la fila', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('toggleActive', $this->subject->id)->assertHasNoErrors();

        expect($this->subject->fresh()->is_active)->toBeFalse();
    });

    it('en carreras y planes la actividad queda bloqueada: no se activa ni se desactiva, solo se observa', function () {
        $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas', 'department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'careers'])
            ->call('toggleActive', $career->id)->assertForbidden();
        reviewPage($this->jefe, ['catalogo' => 'careers'])
            ->call('edit', $career->id)->set('name', 'Ingeniería en Sistemas')->set('is_active', false)->call('save')->assertHasNoErrors();

        expect($career->fresh())->name->toBe('Ingeniería en Sistemas')->is_active->toBeTrue();
    });

    it('CV2: los salones se corrigen solo si el campus deja que los jefes los editen', function () {
        $building = Building::factory()->create(['campus_id' => $this->campus->id]);
        $room = Room::factory()->for($building)->create(['code' => 'K-201', 'capacity' => 38, 'department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])->call('edit', $room->id)->set('capacity', '40')->call('save')->assertHasNoErrors();
        expect($room->fresh()->capacity)->toBe(40);

        $this->campus->update(['settings' => [...CampusProfile::DEFAULTS, 'heads_edit_rooms' => false]]);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])->call('edit', $room->id)->set('capacity', '50')->call('save')->assertForbidden();
        reviewPage($this->jefe, ['catalogo' => 'rooms'])->call('toggleActive', $room->id)->assertForbidden();
        expect($room->fresh()->capacity)->toBe(40);
    });

    it('sin heads_edit_rooms el drawer de un salón solo deja observar', function () {
        $this->campus->update(['settings' => [...CampusProfile::DEFAULTS, 'heads_edit_rooms' => false]]);
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->campus->id]))->create(['department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])
            ->call('edit', $room->id)
            ->assertSee('Este campus no deja que los jefes corrijan salones')
            ->set('flag', 'wrong_capacity')->call('addFlag')->assertHasNoErrors();

        expect(CatalogReviewFlag::where('flaggable_id', $room->id)->count())->toBe(1);
    });

    it('los docentes no se editan aquí: guardar es 403', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();

        reviewPage($this->jefe, ['catalogo' => 'teachers'])->call('edit', $teacher->id)->call('save')->assertForbidden();
    });

    it('un coordinador no corrige, observa ni valida llamando los métodos directo sobre una página que abrió el jefe', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create();

        foreach ([['edit', [$this->subject->id]], ['save', []], ['toggleActive', [$this->subject->id]], ['addFlag', []], ['confirm', ['validate', 'subjects']]] as [$method, $arguments]) {
            $page = reviewPage($this->jefe, ['catalogo' => 'subjects']);
            $this->actingAs($coordinador);

            $page->call($method, ...$arguments)->assertForbidden();
        }

        expect($this->subject->fresh()->is_active)->toBeTrue();
    });

    it('admin y Subdirección abren el drawer en lectura y no guardan ni observan', function () {
        $admin = User::factory()->admin()->create();

        reviewPage($admin, ['department' => $this->sis->id, 'catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)->assertOk()->assertSee('Solo el jefe del departamento')
            ->call('save')->assertForbidden();
        reviewPage($admin, ['department' => $this->sis->id, 'catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)->set('flag', 'duplicate')->call('addFlag')->assertForbidden();
        reviewPage($admin, ['department' => $this->sis->id, 'catalogo' => 'subjects'])->call('toggleActive', $this->subject->id)->assertForbidden();
    });
});

describe('observaciones', function () {
    it('CV4: agrega una observación con su tipo y comentario y la revisión pasa a en revisión', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->set('flag', 'missing_data')
            ->set('comment', 'Las horas de práctica están en 0.')
            ->call('addFlag')
            ->assertHasNoErrors()
            ->assertSee('Las horas de práctica están en 0.');

        $flag = CatalogReviewFlag::sole();

        expect($flag->flag)->toBe(ReviewFlag::MissingData)->and($flag->flagged_by)->toBe($this->jefe->id)
            ->and(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::InReview);
    });

    it('«Otra» exige comentario y capacidad incorrecta no se ofrece en materias', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->set('flag', 'other')->set('comment', '')->call('addFlag')
            ->assertHasErrors('comment')
            ->assertDontSee('Capacidad incorrecta');
    });

    it('resuelve una observación y el jefe, el admin y Subdirección pueden', function (string $who) {
        $user = match ($who) {
            'jefe' => $this->jefe,
            'admin' => User::factory()->admin()->create(),
            'subdirección' => User::factory()->withRole(Role::Subdireccion, [])->create(),
        };
        $flag = CatalogReviewFlag::factory()->on($this->subject)->create(['review_id' => reviewOf(Catalog::Subjects)->id]);

        reviewPage($user, ['department' => $this->sis->id, 'catalogo' => 'subjects'])->call('edit', $this->subject->id)->call('resolveFlag', $flag->id)->assertHasNoErrors();

        expect($flag->fresh()->resolved_at)->not->toBeNull()->and($flag->fresh()->resolved_by)->toBe($user->id);
    })->with(['jefe', 'admin', 'subdirección']);

    it('una observación de otra revisión no se resuelve desde esta pantalla', function () {
        $flag = CatalogReviewFlag::factory()->on($this->subject)->create(['review_id' => reviewOf(Catalog::Subjects, $this->ele)->id]);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('resolveFlag', $flag->id)->assertNotFound();

        expect($flag->fresh()->resolved_at)->toBeNull();
    });
});

describe('acciones de fila', function () {
    it('una acción deshabilitada no lleva wire:click, wire:confirm ni data-testid', function () {
        $admin = User::factory()->admin()->create();

        reviewPage($admin, ['department' => $this->sis->id, 'catalogo' => 'subjects'])
            ->assertDontSeeHtml("wire:click=\"toggleActive('{$this->subject->id}')\"")
            ->assertDontSeeHtml('data-testid="flag-SCD-1027"')
            ->assertSee('Solo el jefe del departamento pone observaciones.');
    });

    it('las filas de docentes tienen un control habilitado que abre el drawer de observaciones, también en lectura y con la revisión validada', function (string $who) {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '04230']);
        $user = match ($who) {
            'jefe validada' => $this->jefe,
            'admin' => User::factory()->admin()->create(),
            'subdirección' => User::factory()->withRole(Role::Subdireccion, [])->create(),
        };
        $who === 'jefe validada' && reviewOf(Catalog::Teachers)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage($user, ['department' => $this->sis->id, 'catalogo' => 'teachers'])
            ->assertSeeHtml("wire:click=\"edit('{$teacher->id}')\"")
            ->assertSeeHtml('data-testid="observations-04230"');
    })->with(['jefe validada', 'admin', 'subdirección']);

    it('Subdirección resuelve la observación de un docente desde la pantalla', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
        $flag = CatalogReviewFlag::factory()->on($teacher)->create(['review_id' => reviewOf(Catalog::Teachers)->id]);
        $subdireccion = User::factory()->withRole(Role::Subdireccion, [])->create();

        reviewPage($subdireccion, ['department' => $this->sis->id, 'catalogo' => 'teachers'])
            ->call('edit', $teacher->id)
            ->assertSee('Marcar como resuelta')
            ->call('resolveFlag', $flag->id)
            ->assertHasNoErrors();

        expect($flag->fresh()->resolved_by)->toBe($subdireccion->id);
    });

    it('una característica de salón de más de 40 caracteres se rechaza como en la pantalla de salones', function () {
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->campus->id]))->create(['department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])
            ->call('edit', $room->id)
            ->set('features', 'Proyector, '.str_repeat('x', 41))
            ->call('save')
            ->assertHasErrors('features');
    });
});
