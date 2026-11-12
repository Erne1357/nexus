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
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Queries\TeacherTermReadinessQuery;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

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

/**
 * Marca como revisados todos los registros de la revisión (CV7), para poder validarla.
 */
function reviewMarkAll(Catalog $catalog, ?Department $department = null): void
{
    markAllReviewed(reviewOf($catalog, $department), test()->jefe);
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
        $jefe = $this->jefe;
        RoleAssignment::factory()->for($jefe)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $this->ele->id]);

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
            ->assertDontSeeHtml("wire:click=\"confirm('validate', 'subjects')\"");
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
            ->assertSee('Falta 1 para sacar la carga de borrador')
            ->assertDontSee('Todo validado');
    });

    it('con los cinco validados dice que la carga puede salir de borrador', function () {
        CatalogReview::query()->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertSee('5 de 5')->assertSee('Todo validado');
    });

    it('la revisión de salones de un campus donde el alcance del jefe no vale no se muestra (CatalogReviewPolicy::view)', function () {
        /*
        | El jefe es de SIS solo en C1 y de ELE en C2, así que C2 está en su credencial y puede ser su campus
        | activo. En C2 su alcance de jefe de SIS no vale: las revisiones generales de SIS siguen visibles (son de
        | todo el Tec) y la de salones de C2, no.
        */
        $c1 = Campus::factory()->create(['code' => 'C1']);
        vacate(Role::JefeDepartamento, $this->sis->id);
        vacate(Role::JefeDepartamento, $this->ele->id);
        $jefe = User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id, 'campus_id' => $c1->id, 'valid_from' => today()->toDateString()])
            ->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id, 'campus_id' => $this->campus->id, 'valid_from' => today()->toDateString()])
            ->create();
        session([Actor::ACTIVE_CAMPUS => $this->campus->id]);

        reviewPage($jefe, ['department' => $this->sis->id])
            ->assertSee($this->campus->name)
            ->assertSeeHtml('data-testid="card-subjects"')
            ->assertDontSeeHtml('data-testid="card-rooms"');
    });

    it('con varios puestos responde al perfil activo: como jefe no trae el campus de su coordinación, y como coordinador no entra', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus Norte']);
        vacate(Role::JefeDepartamento, $this->sis->id);
        $jefe = User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id, 'campus_id' => $c1->id, 'valid_from' => today()->toDateString()])
            ->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])
            ->create();

        // C2 quedó en la sesión, pero solo lo trae la Coordinación: con Jefatura activa, su campus es C1.
        actingAsRole($jefe, Role::JefeDepartamento);
        session([Actor::ACTIVE_CAMPUS => $this->campus->id]);

        reviewPage($jefe)
            ->assertOk()
            ->assertSee('Campus Norte')
            ->assertDontSee($this->campus->name)
            ->assertSeeHtml('data-testid="card-subjects"')
            ->assertDontSeeHtml('data-testid="card-rooms"');

        actingAsRole($jefe, Role::Coordinador);

        reviewPage($jefe)->assertForbidden();
    });

    it('en el primer periodo en Nexus lo dice y no muestra altas ni bajas', function () {
        $html = reviewPage()->assertSee('Primer periodo en Nexus')->assertDontSee('altas')->html();

        expect(substr_count($html, 'Primer periodo en Nexus'))->toBe(1);
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

        reviewPage()->assertSeeInOrder(['+1', 'alta', '−1', 'baja'])->assertDontSee('Materia que se fue');
        reviewPage($this->jefe, ['catalogo' => 'subjects'])->set('showRemoved', true)->assertSee('Materia que se fue');
    });

    it('muestra quién y cuándo validó, y los contadores', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertSee('Validó '.$this->jefe->name)->assertSee('1 de 5')->assertSee('Inactivos');
    });

    it('valida desde la tarjeta con confirmación: cambia el estado y deja bitácora', function () {
        reviewMarkAll(Catalog::Subjects);

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
        Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => '04230', 'name' => 'Ana Torres']);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])->assertSee('K-201')->assertSee('ED-K')->assertSee('38');
        reviewPage($this->jefe, ['catalogo' => 'study_plans'])->assertSee('ISIC-2010')->assertSee('ISC');
        reviewPage($this->jefe, ['catalogo' => 'careers'])->assertSee('Sistemas Computacionales');
        reviewPage($this->jefe, ['catalogo' => 'teachers'])->assertSee('04230')->assertSee('Ana Torres')->assertSee(route('faculty.teachers.show', Teacher::where('rfc', '04230')->sole()->id), escape: false);
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
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => '04230']);
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

describe('captura de docentes (2.6, CV6)', function () {
    beforeEach(function () {
        $this->ready = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => '00001', 'name' => 'Con Todo']);
        $this->bare = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => '00002', 'name' => 'Sin Nada']);
        $this->profileOnly = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => '00003', 'name' => 'Solo Perfil']);
        TeacherAvailability::factory()->for($this->ready)->for($this->term)->create();
        TeacherSubjectQualification::factory()->for($this->ready)->for($this->subject)->create();
        TeacherSubjectQualification::factory()->for($this->profileOnly)->for($this->subject)->create();
        TeacherExternalLoad::factory()->for($this->ready)->for($this->term)->create(['hours' => 8]);
    });

    it('la tarjeta de docentes avisa cuántos faltan y enlaza a la lista filtrada, sin bloquear validar', function () {
        $component = reviewPage()
            ->assertSee('sin disponibilidad en 20271')
            ->assertSee('sin perfil')
            ->assertSeeHtml(e(route('catalog-validation.review', ['department' => $this->sis->id, 'catalogo' => 'teachers', 'falta' => 'disponibilidad'])))
            ->assertSeeHtml('data-testid="missing-perfil"');

        reviewMarkAll(Catalog::Teachers);

        $component->call('confirm', 'validate', 'teachers')->assertSet('showConfirm', true);
    });

    it('la lista trae disponibilidad, perfil, horas fuera y plaza, y las acciones de ficha y disponibilidad', function () {
        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSee('Disponibilidad')->assertSee('Capturada')->assertSee('Falta')
            ->assertSee('Plaza')
            ->assertSeeHtml(route('faculty.teachers.availability', $this->bare->id))
            ->assertSeeHtml(route('faculty.teachers.show', $this->bare->id));
    });

    it('el filtro «Falta» deja solo a quienes les falta disponibilidad o perfil', function (string $falta, array $see, array $dontSee) {
        reviewPage($this->jefe, ['catalogo' => 'teachers', 'falta' => $falta])
            ->assertSet('missing', $falta)
            ->assertSee($see)->assertDontSee($dontSee);
    })->with([
        'disponibilidad' => ['disponibilidad', ['Sin Nada', 'Solo Perfil'], ['Con Todo']],
        'perfil' => ['perfil', ['Sin Nada'], ['Con Todo', 'Solo Perfil']],
    ]);

    it('un valor de «Falta» que no existe no filtra', function () {
        reviewPage($this->jefe, ['catalogo' => 'teachers', 'falta' => 'otra'])->assertSee('Con Todo')->assertSee('Sin Nada');
    });

    it('un docente inactivo no sale como «Falta» ni con el 0 de perfil en advertencia, y no cuenta en los indicadores', function () {
        $inactive = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => '00009', 'name' => 'Ya Baja', 'is_active' => false]);

        $component = reviewPage($this->jefe, ['catalogo' => 'teachers', 'search' => '']);
        $component->set('search', 'Ya Baja')->assertSee('Ya Baja')->assertSeeHtml('Sin dato: docente inactivo')->assertSee('Docente inactivo');

        reviewPage()->assertSeeHtml('data-testid="missing-disponibilidad"');
        expect(app(TeacherTermReadinessQuery::class)->counts($this->sis, $this->term)['no_availability'])->toBe(2);
    });

    it('«Falta» implica activos: al elegirlo Estado pasa a Activos y elegir Inactivos lo quita', function () {
        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->set('missing', 'perfil')->assertSet('status', 'active')
            ->set('status', 'inactive')->assertSet('missing', '');

        reviewPage($this->jefe, ['catalogo' => 'teachers', 'falta' => 'perfil'])->assertSet('status', 'active');
    });

    it('la lista solo prepara a los docentes de la página visible', function () {
        DB::enableQueryLog();
        reviewPage($this->jefe, ['catalogo' => 'teachers']);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'teacher_external_loads'));

        expect($queries)->toHaveCount(1);
    });

    it('Subdirección ve los indicadores del departamento que abre, en lectura', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        reviewPage($subdireccion, ['department' => $this->sis->id])->assertOk()->assertSee('sin disponibilidad en 20271');
    });
});

describe('pestañas de catálogo y lista compacta', function () {
    beforeEach(function () {
        $this->previous = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-15']);
        $this->gone = Subject::factory()->create(['code' => 'OLD-1', 'name' => 'Materia que se fue', 'department_id' => $this->sis->id]);
        CatalogReview::factory()->general(Catalog::Subjects)->validated()->create([
            'term_id' => $this->previous->id, 'department_id' => $this->sis->id,
            'snapshot' => [['id' => $this->subject->id, 'code' => 'SCD-1027', 'name' => 'x'], ['id' => $this->gone->id, 'code' => 'OLD-1', 'name' => 'Materia que se fue']],
        ]);
        $this->gone->update(['is_active' => false]);
        Subject::factory()->create(['code' => 'NEW-1', 'name' => 'Materia nueva', 'department_id' => $this->sis->id]);
        $this->term->update(['starts_on' => '2027-01-15', 'ends_on' => '2027-06-10']);
    });

    it('el tablero y la lista llevan una fila de pestañas: Tablero y una por catálogo con su estado', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        foreach ([[], ['catalogo' => 'subjects']] as $query) {
            reviewPage($this->jefe, $query)
                ->assertSeeHtml('data-testid="tab-board"')
                ->assertSeeHtml('data-testid="tab-teachers"')
                ->assertSeeHtml('data-testid="tab-subjects"')
                ->assertSeeInOrder(['Tablero', 'Docentes', 'Salones', 'Materias', 'Planes de estudio', 'Carreras'])
                ->assertSee('Validado')
                ->assertDontSeeHtml('data-testid="back-to-board"');
        }
    });

    it('cambiar de pestaña cambia el catálogo (la URL) sin recargar y reinicia filtros y drawers', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->set('search', 'Redes')->call('edit', $this->subject->id)->assertSet('showDrawer', true)
            ->set('catalog', 'careers')
            ->assertSet('search', '')->assertSet('showDrawer', false)
            ->assertSeeHtml('data-testid="review-block"')
            ->set('catalog', '')
            ->assertSeeHtml('data-testid="review-board"')
            ->assertDontSeeHtml('data-testid="review-block"');
    });

    it('la lista abre con un solo renglón: nombre, estado, cifras y Validar', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSeeHtml('data-testid="review-block"')
            ->assertSeeHtml('data-testid="validate-subjects"')
            ->assertSeeInOrder(['Materias', 'Pendiente', 'activos', 'inactivos', '+1', 'alta'])
            ->assertDontSee('Periodo');
    });

    it('altas y bajas son cifras sin cajas de color; con cero dicen «Sin altas ni bajas»', function () {
        reviewPage($this->jefe)->assertSee('+1')->assertSee('−1')->assertDontSeeHtml('bg-error/10')->assertDontSeeHtml('bg-success/10');
        CatalogReview::factory()->general(Catalog::Careers)->validated()->create(['term_id' => $this->previous->id, 'department_id' => $this->sis->id, 'snapshot' => []]);

        reviewPage($this->jefe, ['catalogo' => 'careers'])->assertSee('Sin altas ni bajas')->assertDontSee('−0')->assertDontSee('+0');
    });

    it('el filtro «Cambio: Altas» deja solo los registros nuevos contra la foto anterior', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSee('SCD-1027')->assertSee('NEW-1')
            ->set('change', 'added')->assertSee('NEW-1')->assertDontSee('SCD-1027')
            ->set('change', '')->assertSee('SCD-1027');
    });

    it('«Ver bajas» abre un drawer con la clave y el nombre de la foto anterior', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSeeHtml('data-testid="view-removed"')
            ->assertDontSeeHtml('data-testid="removed-panel"')
            ->set('showRemoved', true)
            ->assertSeeHtml('data-testid="removed-panel"')->assertSee('OLD-1')->assertSee('Materia que se fue');
    });

    it('elegir «Bajas» en el filtro abre el drawer de bajas y no filtra la tabla', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->set('change', 'removed')
            ->assertSet('showRemoved', true)->assertSet('change', '')->assertSee('SCD-1027');
    });

    it('sin foto anterior no hay filtro «Cambio» ni «Ver bajas»', function () {
        reviewPage($this->jefe, ['catalogo' => 'careers'])->assertDontSeeHtml('data-testid="view-removed"')->assertDontSee('Cambio');
    });

    it('las tarjetas con trabajo van primero: pendientes, en revisión y al final las validadas', function () {
        reviewOf(Catalog::Teachers)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);
        reviewOf(Catalog::Rooms)->update(['status' => ReviewStatus::InReview]);

        reviewPage()->assertSeeInOrder(['card-subjects', 'card-study_plans', 'card-careers', 'card-rooms', 'card-teachers'], escape: false);
    });

    it('la tarjeta ya no repite «Abrir lista» y una validada solo ofrece Reabrir', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertDontSee('Abrir lista')->assertSeeHtml('data-testid="reopen-subjects"')->assertSeeHtml('data-testid="open-subjects"');
    });

    it('en solo lectura Validar y Reabrir se ven deshabilitados con su motivo', function () {
        $admin = User::factory()->admin()->create();

        reviewPage($admin, ['department' => $this->sis->id, 'catalogo' => 'subjects'])
            ->assertSeeHtml('data-testid="validate-subjects"')
            ->assertSee('Solo el jefe del departamento valida.');
    });

    it('la lista de salones trae la columna Nombre', function () {
        $building = Building::factory()->create(['campus_id' => $this->campus->id]);
        Room::factory()->for($building)->create(['code' => 'K-201', 'name' => 'Laboratorio de Redes', 'department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])->assertSee('Nombre')->assertSee('Laboratorio de Redes');
    });

    it('sin permiso de corregir el drawer muestra los datos como texto legible, con las observaciones primero', function () {
        $admin = User::factory()->admin()->create();

        $html = reviewPage($admin, ['department' => $this->sis->id, 'catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->assertSeeHtml('data-testid="record-facts"')
            ->assertSee('Programación Orientada a Objetos')
            ->assertDontSeeHtml('wire:model="name"')->html();

        expect(substr_count(str($html)->after('data-testid="record-panel"')->toString(), 'Solo el jefe del departamento corrige.'))->toBe(1);
        expect(strpos($html, 'aria-labelledby="drawer-flags"'))->toBeLessThan(strpos($html, 'aria-labelledby="drawer-data"'));
    });

    it('el jefe que corrige sigue viendo campos, y la clave bloqueada va como texto', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->assertSeeHtml('wire:model="name"')
            ->assertSeeHtml('data-testid="record-facts"');
    });

    it('el drawer no lleva pistas de uso, conserva el formato de los campos y cuenta las observaciones con plural', function () {
        CatalogReviewFlag::factory()->on($this->subject)->create(['review_id' => reviewOf(Catalog::Subjects)->id, 'flag' => ReviewFlag::MissingData]);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->assertSeeHtml('</span> abierta<')->assertDontSeeHtml('</span> abiertas')
            ->assertDontSee('Cada cambio queda en la bitácora.')->assertSee('Obligatorio en «Otra»');
    });

    it('un filtro sin resultados ofrece «Quitar filtros»', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->set('search', 'zzzz')->assertSeeHtml('data-testid="clear-filters"')
            ->call('clearFilters')->assertSet('search', '')->assertSee('SCD-1027');
    });
});

describe('periodo anterior por revisión', function () {
    it('en el caso mixto la tarjeta y el renglón sin foto dicen «Sin periodo anterior» y no «Primer periodo»', function () {
        $previous = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-15']);
        CatalogReview::factory()->general(Catalog::Subjects)->validated()->create(['term_id' => $previous->id, 'department_id' => $this->sis->id, 'snapshot' => []]);
        $this->term->update(['starts_on' => '2027-01-15', 'ends_on' => '2027-06-10']);

        $board = reviewPage()->assertSee('Sin periodo anterior')->assertDontSee('Primer periodo en Nexus')->html();
        expect(substr_count($board, 'Sin periodo anterior'))->toBe(4);

        reviewPage($this->jefe, ['catalogo' => 'careers'])->assertSee('Sin periodo anterior')->assertDontSee('Primer periodo en Nexus');
        reviewPage($this->jefe, ['catalogo' => 'subjects'])->assertDontSee('Sin periodo anterior')->assertSeeHtml('data-testid="changes"');
    });

    it('si ningún catálogo tiene foto, el renglón de la lista dice «Primer periodo en Nexus»', function () {
        reviewPage($this->jefe, ['catalogo' => 'careers'])->assertSee('Primer periodo en Nexus')->assertDontSee('Sin periodo anterior');
    });

    it('la insignia de observación de un salón se ve junto a la clave cuando la columna Observación se oculta', function () {
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->campus->id]))->create(['code' => 'K-301', 'department_id' => $this->sis->id]);
        CatalogReviewFlag::factory()->on($room)->create(['review_id' => reviewOf(Catalog::Rooms)->id, 'flag' => ReviewFlag::MissingData]);

        $html = reviewPage($this->jefe, ['catalogo' => 'rooms'])->html();

        expect(substr_count($html, 'Faltan datos'))->toBeGreaterThanOrEqual(2);
    });

    it('el catálogo abierto va en el historial del navegador para que Atrás regrese al tablero', function () {
        $url = collect(reviewPage()->effects['url'] ?? [])->get('catalog');

        expect($url['use'] ?? null)->toBe('push');
    });
});

describe('marcas de revisado (CV7)', function () {
    it('CV7: el jefe marca y desmarca un registro desde su fila, y la revisión pasa a en revisión', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSeeHtml("wire:click=\"toggleReviewed('{$this->subject->id}')\"")
            ->call('toggleReviewed', $this->subject->id)
            ->assertHasNoErrors();

        expect(CatalogReviewMark::query()->where('record_id', $this->subject->id)->exists())->toBeTrue()
            ->and(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::InReview);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('toggleReviewed', $this->subject->id)->assertHasNoErrors();

        expect(CatalogReviewMark::query()->where('record_id', $this->subject->id)->exists())->toBeFalse();
    });

    it('CV7: marca juntos los registros elegidos y limpia la selección', function () {
        $other = Subject::where('code', 'SCA-1002')->sole();

        $component = reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->set('selected', [$this->subject->id, $other->id])
            ->call('markSelected')
            ->assertHasNoErrors()
            ->assertSet('selected', []);

        expect(CatalogReviewMark::query()->pluck('record_id')->all())->toEqualCanonicalizing([$this->subject->id, $other->id])
            ->and(lastToastOf($component)['title'])->toBe('2 registros revisados');
    });

    it('sin elegir nada pide elegir, y un registro ajeno no se marca', function () {
        $foreign = Subject::where('code', 'AEF-1009')->sole();

        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('markSelected')->assertHasErrors('selected');
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->set('selected', [$this->subject->id, $foreign->id])
            ->call('markSelected')
            ->assertHasErrors('record');
        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('toggleReviewed', $foreign->id)->assertNotFound();

        expect(CatalogReviewMark::count())->toBe(0);
    });

    it('cuenta los revisados en la tarjeta, en la pestaña y en el renglón de la lista', function () {
        CatalogReviewMark::factory()->for(reviewOf(Catalog::Subjects), 'review')->on($this->subject)->create();

        reviewPage()->assertSeeHtml('data-testid="reviewed-count-subjects"')->assertSee('1 de 2 revisados');
        reviewPage($this->jefe, ['catalogo' => 'subjects'])->assertSee('1 de 2 revisados')->assertSeeHtml('data-testid="tab-reviewed-subjects"');
    });

    it('CV7: Validar queda deshabilitado con su motivo mientras falte revisar, y no valida llamando el método', function () {
        reviewPage()
            ->assertSee('Faltan 2 registros por revisar.')
            ->assertDontSeeHtml("wire:click=\"confirm('validate', 'subjects')\"")
            ->call('confirm', 'validate', 'subjects')
            ->assertHasErrors('status')
            ->assertSet('showConfirm', false);

        expect(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::Pending);

        reviewMarkAll(Catalog::Subjects);

        reviewPage()->assertSeeHtml("wire:click=\"confirm('validate', 'subjects')\"");
    });

    it('filtra por revisados y sin revisar', function () {
        CatalogReviewMark::factory()->for(reviewOf(Catalog::Subjects), 'review')->on($this->subject)->create();

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->set('reviewedFilter', 'unreviewed')->assertSee('SCA-1002')->assertDontSee('SCD-1027')
            ->set('reviewedFilter', 'reviewed')->assertSee('SCD-1027')->assertDontSee('SCA-1002');
    });

    it('con la revisión validada no se marca: la fila solo muestra el estado', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertDontSeeHtml("wire:click=\"toggleReviewed('{$this->subject->id}')\"")
            ->call('toggleReviewed', $this->subject->id)
            ->assertHasErrors('status');
    });

    it('admin, Subdirección y otro rol no marcan, ni llamando los métodos directo', function (string $who) {
        $user = match ($who) {
            'admin' => User::factory()->admin()->create(),
            'subdirección' => User::factory()->withRole(Role::Subdireccion, [])->create(),
            'coordinador' => User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create(),
        };

        $page = reviewPage($this->jefe, ['catalogo' => 'subjects']);
        $this->actingAs($user);
        $page->call('toggleReviewed', $this->subject->id)->assertForbidden();

        $page = reviewPage($this->jefe, ['catalogo' => 'subjects'])->set('selected', [$this->subject->id]);
        $this->actingAs($user);
        $page->call('markSelected')->assertForbidden();

        expect(CatalogReviewMark::count())->toBe(0);
    })->with(['admin', 'subdirección', 'coordinador']);

    it('CV7: en salones de un campus que no deja corregirlos, el jefe sí marca', function () {
        $this->campus->update(['settings' => [...CampusProfile::DEFAULTS, 'heads_edit_rooms' => false]]);
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->campus->id]))->create(['department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])->call('toggleReviewed', $room->id)->assertHasNoErrors();

        expect(CatalogReviewMark::query()->where('record_id', $room->id)->exists())->toBeTrue();
    });
});
