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
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
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
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->campus->modality_id]);
    app(CreateTermReviews::class)->handle($this->term, Catalog::cases());

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
    it('cuenta los validados y el total baja a 4 si ningún campus abierto usa salones', function () {
        $this->campus->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
        CatalogReview::query()->where('catalog', Catalog::Rooms)->delete();
        reviewOf(Catalog::Careers)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertSee('1 de 4')->assertSee('Faltan 3 para sacar la carga de borrador');
    });

    it('HC-14: si los salones están abiertos en el periodo y a este departamento le falta su revisión, la carga no puede salir de borrador aunque lo demás esté validado', function () {
        reviewOf(Catalog::Rooms)->delete();
        CatalogReview::query()->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()
            ->assertSee('4 de 5')
            ->assertSee('Falta 1 para sacar la carga de borrador')
            ->assertDontSee('Todo validado');
    });

    it('CV9, HC-14: con solo Materias abierta en el periodo, el avance y las pestañas cuentan solo Materias', function () {
        CatalogReview::query()->where('catalog', '!=', Catalog::Subjects)->delete();

        reviewPage()
            ->assertSeeHtml('data-testid="tab-subjects"')
            ->assertDontSeeHtml('data-testid="tab-teachers"')->assertDontSeeHtml('data-testid="tab-rooms"')
            ->assertDontSeeHtml('data-testid="tab-study_plans"')->assertDontSeeHtml('data-testid="tab-careers"')
            ->assertSee('0 de 1')->assertDontSee('0 de 5')
            ->assertSee('Falta 1 para sacar la carga de borrador')
            ->assertDontSee('Docentes')->assertDontSee('Salones')->assertDontSee('Planes de estudio')->assertDontSee('Carreras');

        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertSee('1 de 1')->assertSee('Todo validado');
    });

    it('CV9, HC-14: un catálogo abierto en el periodo cuya revisión le falta al departamento sí cuenta como pendiente', function () {
        CatalogReview::query()->whereNotIn('catalog', [Catalog::Subjects, Catalog::Careers])->delete();
        reviewOf(Catalog::Careers)->delete();

        reviewPage()
            ->assertDontSeeHtml('data-testid="tab-careers"')
            ->assertSee('0 de 2')
            ->assertSee('Sin revisión')
            ->assertDontSee('Docentes');
    });

    it('con los cinco validados dice que la carga puede salir de borrador', function () {
        CatalogReview::query()->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertSee('5 de 5')->assertSee('Todo validado');
    });

    it('decisión 6: la revisión de salones es una para todos los campus, sin campus en la credencial', function () {
        Campus::factory()->create(['code' => 'C1', 'name' => 'Campus Norte']);

        reviewPage()
            ->assertSeeHtml('data-testid="card-rooms"')
            ->assertSee('Todos los campus')
            ->assertDontSee('Elige un campus');
    });

    it('con varios puestos responde al perfil activo: como coordinador no entra', function () {
        vacate(Role::JefeDepartamento, $this->sis->id);
        $jefe = User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id, 'valid_from' => today()->toDateString()])
            ->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])
            ->create();

        actingAsRole($jefe, Role::JefeDepartamento);
        reviewPage($jefe)->assertOk()->assertSeeHtml('data-testid="card-rooms"');

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

        reviewPage()->assertSee('Validó '.$this->jefe->name)->assertSee('1 de 5')->assertSee('inactivos');
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
    it('lista las materias con clave, nombre y horas, con búsqueda; las inactivas no se revisan y salen en «Todos»', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSet('segment', 'por-revisar')
            ->assertSee('SCD-1027')->assertSee('Programación Orientada a Objetos')->assertDontSee('SCA-1002')
            ->assertDontSee('AEF-1009')
            ->set('segment', 'todos')->assertSee('SCA-1002')->assertSee('Inactivo')
            ->set('search', 'Redes')->assertSee('SCA-1002')->assertDontSee('SCD-1027');
    });

    it('filtra por observación abierta con «Con observación»', function () {
        CatalogReviewFlag::factory()->on($this->subject)->create(['review_id' => reviewOf(Catalog::Subjects)->id, 'flag' => ReviewFlag::MissingData]);

        reviewPage($this->jefe, ['catalogo' => 'subjects', 'revision' => 'todos'])
            ->assertSee('Faltan datos')->assertSee('SCA-1002')
            ->set('segment', 'con-observacion')->assertSee('SCD-1027')->assertDontSee('SCA-1002');
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

    it('CV2: el jefe corrige los salones de su departamento en cualquier campus y los mueve a un edificio de otro (CV-S10)', function () {
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->campus->id]))->create(['code' => 'K-201', 'capacity' => 38, 'department_id' => $this->sis->id]);
        $c1 = Campus::factory()->create(['code' => 'C1']);
        $building = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'ED-N']);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])
            ->call('edit', $room->id)
            ->assertSet('building_id', $room->building_id)
            ->assertSee('C1 · ED-N')
            ->set('capacity', '40')
            ->set('building_id', $building->id)
            ->call('save')
            ->assertHasNoErrors();

        expect($room->fresh())->capacity->toBe(40)->building_id->toBe($building->id)->campus_id->toBe($c1->id)
            ->and(CatalogReviewMark::query()->where('record_id', $room->id)->exists())->toBeTrue();

        reviewPage($this->jefe, ['catalogo' => 'rooms'])->call('toggleActive', $room->id)->assertHasNoErrors();
        expect($room->fresh()->is_active)->toBeFalse();
    });

    it('CV1: captura el correo institucional de un docente en el recuadro de su fila; el de uno que ocupa otro puesto solo lo cambia el admin (AC-06)', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1', 'institutional_email' => null]);

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSeeHtml('data-testid="institutional-email"')
            ->assertSeeHtml('data-email-open="'.$teacher->id.'"')
            ->call('saveEmail', $teacher->id, 'ana')
            ->assertReturned(['saved' => true, 'error' => null]);

        expect($teacher->fresh()->institutional_email)->toBe('ana@cdjuarez.tecnm.mx');

        $staff = Teacher::factory()->adscribedTo($this->sis)->create(['user_id' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create()->id]);

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertDontSeeHtml('data-email-open="'.$staff->id.'"')
            ->assertSee('solo lo cambia el administrador')
            ->call('saveEmail', $staff->id, 'jefe')
            ->assertForbidden();
        reviewPage($this->jefe, ['catalogo' => 'teachers'])->call('edit', $staff->id)->assertSee('solo lo cambia el administrador');
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

        $component = reviewPage($this->jefe, ['catalogo' => 'teachers', 'revision' => 'todos']);
        $component->set('search', 'Ya Baja')->assertSee('Ya Baja')->assertSeeHtml('Sin dato: docente inactivo')->assertSee('Docente inactivo');

        reviewPage()->assertSeeHtml('data-testid="missing-disponibilidad"');
        expect(app(TeacherTermReadinessQuery::class)->counts($this->sis, $this->term)['no_availability'])->toBe(2);
    });

    it('«Falta» deja a los activos, revisados o no: al elegirlo, y al llegar del tablero, el filtro pasa a «Todos»', function () {
        Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => '00009', 'name' => 'Ya Baja', 'is_active' => false]);
        CatalogReviewMark::factory()->for(reviewOf(Catalog::Teachers), 'review')->on($this->bare)->create();

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSet('segment', 'por-revisar')
            ->set('missing', 'perfil')->assertSet('segment', 'todos')
            ->assertSee('Sin Nada')->assertDontSee('Ya Baja');

        reviewPage($this->jefe, ['catalogo' => 'teachers', 'falta' => 'perfil'])->assertSet('segment', 'todos');
    });

    it('AC-06: la tarjeta cuenta a los activos sin correo institucional y «Falta: Sin correo institucional» los deja', function () {
        $this->ready->update(['institutional_email' => 'todo@cdjuarez.tecnm.mx']);

        reviewPage()->assertSee('sin correo institucional')->assertSeeHtml('data-testid="missing-correo"');

        reviewPage($this->jefe, ['catalogo' => 'teachers', 'falta' => 'correo'])
            ->assertSee('Sin Nada')->assertSee('Solo Perfil')->assertDontSee('Con Todo');
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
            ->assertSeeHtml("wire:click=\"setReviewed('{$this->subject->id}', true)\"")
            ->call('setReviewed', $this->subject->id, true)
            ->assertHasNoErrors();

        expect(CatalogReviewMark::query()->where('record_id', $this->subject->id)->exists())->toBeTrue()
            ->and(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::InReview);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])->assertSeeHtml("wire:click=\"setReviewed('{$this->subject->id}', false)\"")->call('setReviewed', $this->subject->id, false)->assertHasNoErrors();

        expect(CatalogReviewMark::query()->where('record_id', $this->subject->id)->exists())->toBeFalse();
    });

    it('CV7: un envío repetido o de una pantalla vieja no invierte la marca: pide el estado, no lo alterna', function () {
        $page = reviewPage($this->jefe, ['catalogo' => 'subjects']);

        $page->call('setReviewed', $this->subject->id, true)->call('setReviewed', $this->subject->id, true);
        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('setReviewed', $this->subject->id, true);

        expect(CatalogReviewMark::query()->where('record_id', $this->subject->id)->count())->toBe(1)
            ->and(Activity::query()->where('event', 'reviewed')->count())->toBe(1);

        $page->call('setReviewed', $this->subject->id, false)->call('setReviewed', $this->subject->id, false);

        expect(CatalogReviewMark::query()->where('record_id', $this->subject->id)->exists())->toBeFalse()
            ->and(Activity::query()->where('event', 'unreviewed')->count())->toBe(1);
    });

    it('CV7: marca juntos los registros elegidos en el navegador', function () {
        $other = Subject::where('code', 'SCA-1002')->sole();

        $component = reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('markSelected', [$this->subject->id, $other->id])
            ->assertHasNoErrors();

        expect(CatalogReviewMark::query()->pluck('record_id')->all())->toEqualCanonicalizing([$this->subject->id, $other->id])
            ->and(lastToastOf($component)['title'])->toBe('2 registros revisados');
    });

    it('sin elegir nada pide elegir, y un registro ajeno no se marca', function () {
        $foreign = Subject::where('code', 'AEF-1009')->sole();

        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('markSelected', [])->assertHasErrors('selected');
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('markSelected', [$this->subject->id, $foreign->id])
            ->assertHasErrors('record');
        reviewPage($this->jefe, ['catalogo' => 'subjects'])->call('setReviewed', $foreign->id, true)->assertNotFound();

        expect(CatalogReviewMark::count())->toBe(0);
    });

    it('cuenta los activos revisados en la tarjeta, en la pestaña y en el renglón de la lista; los inactivos no cuentan', function () {
        Subject::factory()->create(['code' => 'SCD-1099', 'department_id' => $this->sis->id]);
        CatalogReviewMark::factory()->for(reviewOf(Catalog::Subjects), 'review')->on($this->subject)->create();

        reviewPage()->assertSeeHtml('data-testid="reviewed-count-subjects"')->assertSee('1 de 2')->assertSee('Queda 1 por revisar');
        reviewPage($this->jefe, ['catalogo' => 'subjects'])->assertSee('1 de 2 revisados')->assertSeeHtml('data-testid="tab-reviewed-subjects"');
    });

    it('CV7: Validar queda deshabilitado con su motivo mientras falte revisar un activo, y no valida llamando el método', function () {
        reviewPage()
            ->assertSee('Falta 1 registro por revisar.')
            ->assertDontSeeHtml("wire:click=\"confirm('validate', 'subjects')\"")
            ->call('confirm', 'validate', 'subjects')
            ->assertHasErrors('status')
            ->assertSet('showConfirm', false);

        expect(reviewOf(Catalog::Subjects)->status)->toBe(ReviewStatus::Pending);

        reviewMarkAll(Catalog::Subjects);

        reviewPage()->assertSeeHtml("wire:click=\"confirm('validate', 'subjects')\"");
    });

    it('filtra por revisados y por revisar, con sus cifras: «Por revisar» son los activos sin marca (CV7)', function () {
        $pending = Subject::factory()->create(['code' => 'SCD-1099', 'name' => 'Pendiente', 'department_id' => $this->sis->id]);
        CatalogReviewMark::factory()->for(reviewOf(Catalog::Subjects), 'review')->on($this->subject)->create();

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSet('segment', 'por-revisar')
            ->assertSeeHtml('data-testid="segment-por-revisar"')
            ->assertSeeInOrder(['Por revisar', '1', 'Revisados', '1', 'Con observación', '0', 'Todos', '3'])
            ->assertSee('SCD-1099')->assertDontSee('SCA-1002')->assertDontSee('SCD-1027')
            ->set('segment', 'revisados')->assertSee('SCD-1027')->assertDontSee('SCA-1002')->assertDontSee('SCD-1099')
            ->set('segment', 'nada')->assertSet('segment', 'por-revisar');
    });

    it('sin nada por revisar la lista abre en «Todos», y «Por revisar» vacío dice «Revisaste los N» con Validar catálogo', function () {
        reviewMarkAll(Catalog::Subjects);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])->assertSet('segment', 'todos')->assertSee('SCD-1027');

        reviewPage($this->jefe, ['catalogo' => 'subjects', 'revision' => 'por-revisar'])
            ->assertSeeHtml('data-testid="all-reviewed"')
            ->assertSee('Revisaste la única materia.')
            ->assertSeeHtml("wire:click=\"confirm('validate', 'subjects')\"")
            ->assertSeeHtml('data-testid="validate-subjects-done"');

        Subject::factory()->create(['code' => 'SCD-1099', 'department_id' => $this->sis->id]);
        reviewMarkAll(Catalog::Subjects);

        reviewPage($this->jefe, ['catalogo' => 'subjects', 'revision' => 'por-revisar'])->assertSee('Revisaste las 2 materias.');
        reviewPage(User::factory()->admin()->create(), ['department' => $this->sis->id, 'catalogo' => 'subjects', 'revision' => 'por-revisar'])
            ->assertSee('Revisaste las 2 materias.')->assertSee('Lo valida el jefe del departamento.')
            ->assertDontSeeHtml('data-testid="validate-subjects-done"');
    });

    it('con la revisión validada no se marca: la fila solo muestra el estado', function () {
        reviewOf(Catalog::Subjects)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertDontSeeHtml("wire:click=\"setReviewed('{$this->subject->id}'")
            ->call('setReviewed', $this->subject->id, true)
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
        $page->call('setReviewed', $this->subject->id, true)->assertForbidden();

        $page = reviewPage($this->jefe, ['catalogo' => 'subjects']);
        $this->actingAs($user);
        $page->call('markSelected', [$this->subject->id])->assertForbidden();

        expect(CatalogReviewMark::count())->toBe(0);
    })->with(['admin', 'subdirección', 'coordinador']);

    it('CV7: en salones, el jefe marca los de su departamento de cualquier campus', function () {
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => Campus::factory()->create()->id]))->create(['department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])->call('setReviewed', $room->id, true)->assertHasNoErrors();

        expect(CatalogReviewMark::query()->where('record_id', $room->id)->exists())->toBeTrue();
    });
});

describe('docentes: baja y alta (CV8, CV-S8, CV-S9)', function () {
    it('CV8: el jefe da de baja a un docente de su departamento desde la fila, con confirmación', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1']);

        $component = reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSeeHtml("wire:click=\"dischargeTeacher('{$teacher->id}')\"")
            ->assertSeeHtml('data-testid="discharge-AAAA800101AA1"')
            ->call('dischargeTeacher', $teacher->id)
            ->assertHasNoErrors();

        expect($teacher->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($component)['title'])->toBe('Docente dado de baja');
    });

    it('CV-S8: a un docente que ocupa un puesto la baja se ve deshabilitada con su motivo, y llamarla es 403', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'BBBB800101BB1', 'user_id' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create()->id]);

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertDontSeeHtml("wire:click=\"dischargeTeacher('{$teacher->id}')\"")
            ->assertSee('Ocupa un puesto: lo da de baja el administrador o la Subdirección.')
            ->call('dischargeTeacher', $teacher->id)
            ->assertForbidden();

        expect($teacher->fresh()->is_active)->toBeTrue();
    });

    it('CV-S9: «Agregar docente» busca por RFC o nombre a uno que ya existe, lo dice si no hay, y lo agrega revisado', function () {
        $teacher = Teacher::factory()->inactive()->create(['rfc' => 'CCCC800101CC1', 'name' => 'Ricardo Peña Montoya']);

        $component = reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->call('$set', 'showAddTeacher', true)
            ->set('teacherSearch', 'zzzz800101zz1')->assertSeeHtml('data-testid="no-candidates"')
            ->set('teacherSearch', ' cccc800101cc1 ')->assertSee('Ricardo Peña Montoya')->assertSee('Reactivar y agregar')
            ->set('teacherSearch', 'peña')->assertSeeHtml('data-testid="add-CCCC800101CC1"')
            ->call('addTeacher', $teacher->id)->assertHasNoErrors()
            ->assertSet('showAddTeacher', false)->assertSet('teacherSearch', '');

        expect($teacher->fresh()->is_active)->toBeTrue()
            ->and($teacher->adscriptions()->sole()->department_id)->toBe($this->sis->id)
            ->and(CatalogReviewMark::query()->where('record_id', $teacher->id)->exists())->toBeTrue()
            ->and(lastToastOf($component)['title'])->toBe('Agregaste a Ricardo Peña Montoya');
    });

    it('CV-S9: dice qué pasa con cada docente que encuentra: ya está, se agrega como adicional o queda como principal', function () {
        $here = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'HERE800101AA1', 'name' => 'Norma Peña Aquí']);
        $elsewhere = Teacher::factory()->adscribedTo($this->ele)->create(['rfc' => 'ELSE800101AA1', 'name' => 'Susana Peña Otra']);
        Teacher::factory()->create(['rfc' => 'NONE800101AA1', 'name' => 'Pedro Peña Suelto']);

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->call('$set', 'showAddTeacher', true)
            ->set('teacherSearch', 'pe')
            ->assertSee('Ya está en Sistemas y Computación.')
            ->assertDontSeeHtml('data-testid="add-HERE800101AA1"')
            ->assertSee('Principal en Eléctrica-Electrónica. Se agrega a Sistemas y Computación como adscripción adicional')
            ->assertSee('Sin departamento. Queda con Sistemas y Computación como principal.')
            ->call('addTeacher', $here->id)->assertHasErrors('candidate');

        expect($elsewhere->adscriptions()->count())->toBe(1);
    });

    it('un id de docente que no existe no se agrega', function () {
        reviewPage($this->jefe, ['catalogo' => 'teachers'])->call('addTeacher', 'no-es-un-id')->assertNotFound();
    });

    it('a un docente dado de baja que ocupa un puesto no lo agrega: lo dice y dice quién lo reactiva', function () {
        $teacher = Teacher::factory()->inactive()->create(['rfc' => 'FFFF800101FF1', 'name' => 'Fernanda Puesto', 'user_id' => User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create()->id]);

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->call('$set', 'showAddTeacher', true)
            ->set('teacherSearch', 'FFFF800101FF1')
            ->assertSee('lo reactivan el administrador o la Subdirección')
            ->assertDontSeeHtml('data-testid="add-FFFF800101FF1"')
            ->call('addTeacher', $teacher->id)
            ->assertHasErrors('candidate')
            ->assertSee('lo reactivan el administrador o la Subdirección');

        expect($teacher->fresh()->is_active)->toBeFalse()
            ->and($teacher->adscriptions()->count())->toBe(0);
    });

    it('admin, Subdirección y coordinador no dan de baja ni agregan, ni llamando los métodos directo', function (string $who) {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'DDDD800101DD1']);
        Teacher::factory()->create(['rfc' => 'EEEE800101EE1']);
        $user = match ($who) {
            'admin' => User::factory()->admin()->create(),
            'subdirección' => User::factory()->withRole(Role::Subdireccion, [])->create(),
            'coordinador' => User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create(),
        };

        $page = reviewPage($this->jefe, ['catalogo' => 'teachers']);
        $this->actingAs($user);
        $page->call('dischargeTeacher', $teacher->id)->assertForbidden();

        $page = reviewPage($this->jefe, ['catalogo' => 'teachers']);
        $this->actingAs($user);
        $page->call('addTeacher', Teacher::where('rfc', 'EEEE800101EE1')->sole()->id)->assertForbidden();

        expect($teacher->fresh()->is_active)->toBeTrue()
            ->and(Teacher::where('rfc', 'EEEE800101EE1')->sole()->adscriptions()->count())->toBe(0);
    })->with(['admin', 'subdirección', 'coordinador']);

    it('el jefe de otro departamento no da de baja a un docente de este', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
        $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create();

        reviewPage($other, ['department' => $this->sis->id, 'catalogo' => 'teachers'])->assertForbidden();
        expect($teacher->fresh()->is_active)->toBeTrue();
    });
});

describe('revisión por registro (opción A)', function () {
    it('decisión 12: solo hay pestaña y tarjeta de los catálogos con revisión en el periodo, cada pestaña con «N/M»', function () {
        CatalogReview::query()->where('catalog', Catalog::Rooms)->delete();
        Subject::factory()->create(['code' => 'SCD-1099', 'department_id' => $this->sis->id]);
        CatalogReviewMark::factory()->for(reviewOf(Catalog::Subjects), 'review')->on($this->subject)->create();

        reviewPage()
            ->assertDontSeeHtml('data-testid="tab-rooms"')->assertDontSeeHtml('data-testid="card-rooms"')
            ->assertSeeHtml('data-testid="tab-subjects"')
            ->assertSeeInOrder(['data-testid="tab-reviewed-subjects"', '1/2'], escape: false);
    });

    it('la tarjeta lleva «N de M revisados» con su barra y lo que queda; validada, quién validó y sin «N/M» en su pestaña', function () {
        Subject::factory()->create(['code' => 'SCD-1099', 'department_id' => $this->sis->id]);

        reviewPage()
            ->assertSeeInOrder(['data-testid="card-subjects"', '0 de 2', 'revisados', '<progress', 'Quedan 2 por revisar'], escape: false);

        reviewOf(Catalog::Careers)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        reviewPage()->assertDontSeeHtml('data-testid="tab-reviewed-careers"')->assertSee('Validó '.$this->jefe->name);
    });

    it('CV7: «Validar catálogo» deshabilitado lleva su motivo escrito y enlazado; habilitado, abre la confirmación', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSeeHtml('aria-describedby="why-subjects"')
            ->assertSeeHtml('id="why-subjects"')
            ->assertSee('Falta 1 registro por revisar.');

        reviewMarkAll(Catalog::Subjects);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertDontSeeHtml('id="why-subjects"')
            ->assertSee('Validar catálogo')
            ->call('confirm', 'validate', 'subjects')
            ->assertSet('showConfirm', true);
    });

    it('CV7: la columna «Revisión» dice quién marcó y cuándo, y «Acciones» ofrece quitar la marca con ese dato', function () {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(18, 40));
        $laura = User::factory()->create(['name' => 'Laura Ramos']);
        CatalogReviewMark::factory()->for(reviewOf(Catalog::Subjects), 'review')->on($this->subject)->create(['reviewed_by' => $laura->id, 'reviewed_at' => now()]);

        reviewPage($this->jefe, ['catalogo' => 'subjects', 'revision' => 'todos'])
            ->assertSee('Laura · 10 nov., 18:40')
            ->assertSee('Lo marcó Laura Ramos el 10 nov. 2026, 18:40.')
            ->assertSeeHtml("wire:click=\"setReviewed('{$this->subject->id}', false)\"")
            ->assertSeeHtml('data-testid="unmark-SCD-1027"');
    });

    it('a quien marca, una casilla por registro sin revisar, «Marcar revisado», los atajos y la barra de selección', function () {
        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSeeHtml('data-testid="select-SCD-1027"')
            ->assertSeeHtml('data-testid="mark-SCD-1027"')
            ->assertSeeHtml('data-testid="keyboard-hint"')
            ->assertSeeHtml('data-testid="selection-bar"')
            ->assertSeeHtml('data-row="'.$this->subject->id.'"');
    });

    it('CV-S1: admin y Subdirección ven el avance y las marcas, sin casillas, «Marcar revisado», atajos ni selección', function (string $who) {
        $user = $who === 'admin' ? User::factory()->admin()->create() : User::factory()->withRole(Role::Subdireccion, [])->create();

        reviewPage($user, ['department' => $this->sis->id, 'catalogo' => 'subjects'])
            ->assertSee('Por revisar')
            ->assertSee('Solo el jefe del departamento marca lo revisado.')
            ->assertDontSeeHtml('data-testid="select-SCD-1027"')
            ->assertDontSeeHtml('data-testid="mark-SCD-1027"')
            ->assertDontSeeHtml('data-testid="keyboard-hint"')
            ->assertDontSeeHtml('data-testid="selection-bar"');
    })->with(['admin', 'subdirección']);

    it('CV8: la confirmación de la baja dice qué pasa: sin otro departamento queda inactivo; con otro, sigue allá', function () {
        $alone = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'ALON800101AA1', 'name' => 'Martha Ríos']);
        $mainHere = Teacher::factory()->adscribedTo($this->sis)->adscribedTo($this->ele, primary: false)->create(['rfc' => 'MAIN800101AA1', 'name' => 'Eduardo Carrasco']);
        $mainThere = Teacher::factory()->adscribedTo($this->ele)->adscribedTo($this->sis, primary: false)->create(['rfc' => 'ELSE800101AA1', 'name' => 'Leticia Barraza']);

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSee('¿Dar de baja a Martha Ríos?')
            ->assertSee('No tiene otro departamento: su registro queda inactivo y su cuenta se desactiva.')
            ->assertSee('¿Dar de baja a Eduardo Carrasco de Sistemas y Computación?')
            ->assertSee('Sigue como docente de Eléctrica-Electrónica, que pasa a ser su adscripción principal.')
            ->assertSee('También en Eléctrica-Electrónica')
            ->assertSee('Sigue como docente de Eléctrica-Electrónica, su adscripción principal. Solo deja de aparecer en tu departamento.')
            ->assertSee('Principal en Eléctrica-Electrónica')
            ->call('dischargeTeacher', $mainThere->id)
            ->assertHasNoErrors();

        expect($mainThere->fresh()->is_active)->toBeTrue()
            ->and($mainThere->adscriptions()->pluck('department_id')->all())->toBe([$this->ele->id])
            ->and($alone->fresh()->is_active)->toBeTrue();
    });

    it('CV-S8: el motivo de la baja deshabilitada va escrito en la fila, no en un tooltip', function () {
        Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'BBBB800101BB1', 'user_id' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create()->id]);

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSeeInOrder(['data-testid="discharge-reason-BBBB800101BB1"', 'Ocupa un puesto: lo da de baja el administrador o la Subdirección.'], escape: false);
    });

    it('AC-06: el recuadro arma el correo con el dominio institucional, acepta el correo completo y devuelve el motivo si no se guarda', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1']);
        Teacher::factory()->create(['institutional_email' => 'ocupado@cdjuarez.tecnm.mx']);

        reviewPage($this->jefe, ['catalogo' => 'teachers'])
            ->call('saveEmail', $teacher->id, '  ')->assertReturned(['saved' => false, 'error' => 'Escribe el usuario de su correo institucional.'])
            ->call('saveEmail', $teacher->id, 'ana@gmail.com')->assertReturned(fn (array $result): bool => $result['saved'] === false && str_contains($result['error'], '@cdjuarez.tecnm.mx'))
            ->call('saveEmail', $teacher->id, 'ocupado')->assertReturned(fn (array $result): bool => $result['saved'] === false && str_contains($result['error'], 'ocupado@cdjuarez.tecnm.mx'))
            ->call('saveEmail', $teacher->id, 'Ana.Torres@CDJUAREZ.tecnm.mx')->assertReturned(['saved' => true, 'error' => null]);

        expect($teacher->fresh()->institutional_email)->toBe('ana.torres@cdjuarez.tecnm.mx')
            ->and(CatalogReviewMark::query()->where('record_id', $teacher->id)->exists())->toBeTrue();
    });

    it('AC-06: admin y Subdirección ven la lista sin el recuadro del correo y no lo capturan', function (string $who) {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
        $user = $who === 'admin' ? User::factory()->admin()->create() : User::factory()->withRole(Role::Subdireccion, [])->create();

        reviewPage($user, ['department' => $this->sis->id, 'catalogo' => 'teachers'])
            ->assertDontSeeHtml('data-email-open="'.$teacher->id.'"')
            ->call('saveEmail', $teacher->id, 'ana')
            ->assertForbidden();

        expect($teacher->fresh()->institutional_email)->toBeNull();
    })->with(['admin', 'subdirección']);

    it('AC-06: admin, Subdirección y el jefe de otro departamento no capturan el correo llamando el método sobre la pantalla del jefe', function (string $who) {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
        $user = match ($who) {
            'admin' => User::factory()->admin()->create(),
            'subdirección' => User::factory()->withRole(Role::Subdireccion, [])->create(),
            'jefe de otro departamento' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create(),
        };

        $page = reviewPage($this->jefe, ['catalogo' => 'teachers']);
        $this->actingAs($user);
        $page->call('saveEmail', $teacher->id, 'ana')->assertForbidden();

        expect($teacher->fresh()->institutional_email)->toBeNull();
    })->with(['admin', 'subdirección', 'jefe de otro departamento']);

    it('«Por revisar» no se queda en una página vacía: al marcar los de la última, pasa a la que ahora es la última', function () {
        Subject::factory()->count(60)->sequence(fn ($sequence) => ['code' => sprintf('PEN-%03d', $sequence->index), 'department_id' => $this->sis->id])->create();
        $lastTen = Subject::query()->where('code', 'like', 'PEN-%')->orderByDesc('code')->limit(11)->pluck('id')->all();

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->call('gotoPage', 2)
            ->assertSee('PEN-059')
            ->call('markSelected', $lastTen)
            ->assertDontSee('Ningún registro coincide.')
            ->assertSee('PEN-000')
            ->assertSet('paginators.page', 1);
    });

    it('CV7: un inactivo sin marca no se revisa: sin casilla, sin «Por revisar» y fuera de los pendientes', function () {
        $inactive = Subject::where('code', 'SCA-1002')->sole();

        $html = reviewPage($this->jefe, ['catalogo' => 'subjects', 'revision' => 'todos'])
            ->assertSee('Inactivo: no se revisa')
            ->assertDontSeeHtml('data-testid="select-SCA-1002"')
            ->assertDontSeeHtml('data-testid="mark-SCA-1002"')
            ->assertSee('Elegir los 1 por revisar de esta página')
            ->html();

        expect(str($html)->after('data-row="'.$inactive->id.'"')->before('>')->contains('data-pending'))->toBeFalse()
            ->and(substr_count(str($html)->after('data-testid="record-cards"')->toString(), 'Por revisar'))->toBe(1);

        reviewPage(User::factory()->admin()->create(), ['department' => $this->sis->id, 'catalogo' => 'subjects', 'revision' => 'todos'])
            ->assertSee('Inactivo: no se revisa');
    });

    it('«Elegir los N por revisar» toma los pendientes que dejan la búsqueda y los filtros, no los de toda la revisión', function () {
        Subject::factory()->create(['code' => 'RED-1', 'name' => 'Redes I', 'department_id' => $this->sis->id]);
        Subject::factory()->create(['code' => 'RED-2', 'name' => 'Redes II', 'department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSee('Elegir los 3 por revisar')
            ->set('search', 'Redes')
            ->assertSee('Elegir los 2 por revisar')
            ->assertDontSee('Elegir los 3 por revisar');
    });

    it('validar o reabrir cambia quién marca y la lista arranca de nuevo en el navegador, sin selección', function () {
        reviewMarkAll(Catalog::Subjects);

        reviewPage($this->jefe, ['catalogo' => 'subjects'])
            ->assertSeeHtml('data-can-mark="true"')->assertSeeHtml('wire:key="review-list-subjects-marca"')
            ->call('confirm', 'validate', 'subjects')->call('confirmed')
            ->assertSeeHtml('data-can-mark="false"')->assertSeeHtml('wire:key="review-list-subjects-lee"')
            ->call('confirm', 'reopen', 'subjects')->call('confirmed')
            ->assertSeeHtml('data-can-mark="true"')->assertSeeHtml('wire:key="review-list-subjects-marca"');
    });

    it('decisión 6: los salones se filtran por campus y por edificio', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $north = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'LC', 'name' => 'Laboratorio de cómputo']);
        $south = Building::factory()->create(['campus_id' => $this->campus->id, 'code' => 'E2', 'name' => 'Edificio 2']);
        Room::factory()->for($north)->create(['code' => 'LC-01', 'department_id' => $this->sis->id]);
        Room::factory()->for($south)->create(['code' => 'E2-101', 'department_id' => $this->sis->id]);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])
            ->assertSee('C1 · Campus I')->assertSee('C1 · LC · Laboratorio de cómputo')
            ->set('roomCampus', $c1->id)->assertSee('LC-01')->assertDontSee('E2-101')
            ->set('roomCampus', '')->set('roomBuilding', $south->id)->assertSee('E2-101')->assertDontSee('LC-01')
            ->set('roomBuilding', 'no-es-un-id')->assertDontSee('E2-101')->assertDontSee('LC-01');
    });

    it('CV-S10: el edificio del salón se elige agrupado por campus y el campus lo sigue, diciendo si cambia', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->campus->id, 'code' => 'E2']))->create(['code' => 'Y-104', 'department_id' => $this->sis->id]);
        $north = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'LC', 'name' => 'Laboratorio']);

        reviewPage($this->jefe, ['catalogo' => 'rooms'])
            ->call('edit', $room->id)
            ->assertSeeHtml('<optgroup label="C1 · Campus I">')
            ->assertSeeHtml('data-testid="room-campus"')
            ->assertDontSeeHtml('data-testid="room-moves"')
            ->set('building_id', $north->id)
            ->assertSeeHtml('data-testid="room-moves"')
            ->assertSee('Campus I')
            ->assertSee('con el edificio.')
            ->assertSee('Al guardar, queda revisado.');
    });
});
