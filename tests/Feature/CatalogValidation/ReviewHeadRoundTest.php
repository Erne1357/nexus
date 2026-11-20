<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogOwner;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Navigation\RecentPages;
use App\Modules\Core\Support\ValidationReturn;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Ajustes de la revisión del jefe que salieron de la simulación del 13 de noviembre de 2026 (tarea 1.21, paquete A),
| con las decisiones del usuario del 14 de noviembre de 2026: quién validó se ve sin recargar; «Guardar» sin cambios
| marca el registro (CV-S7, D9); «Quitar de <departamento>» va en «Acciones» con su consecuencia y un motivo opcional
| que queda en la bitácora (CV8, D8, D10); los textos nombran el departamento, no su clave, y cuentan en singular o
| plural; cada campo «Tipo» lleva su nombre; las materias dicen en qué planes están; los catálogos sin registros se
| validan en un paso (CV7, D5); el correo institucional se captura en la fila también con la revisión validada (CV1,
| CV-S2, AC-06; D4, D11); admin y Subdirección ven la pantalla en lectura, sin controles deshabilitados; y el menú
| nombra la pantalla según quien la abre.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->sis = Department::factory()->create(['code' => '110100', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => '110200', 'name' => 'Eléctrica-Electrónica']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle($this->term, Catalog::cases());

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->escolares = schoolServicesHead(['name' => 'Sara Escolar']);
    $this->subject = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Química', 'department_id' => $this->sis->id]);
});

/**
 * La revisión del periodo: la del departamento (SIS, si no se dice) en docentes y salones, la de Servicios Escolares en
 * materias, planes y carreras (CV15).
 */
function headReview(Catalog $catalog, ?Department $department = null): CatalogReview
{
    return CatalogReview::query()
        ->where('term_id', test()->term->id)
        ->tap(fn ($query) => CatalogReviewQuery::ownedBy($query, $catalog->owner() === CatalogOwner::SchoolServices ? null : ($department ?? test()->sis)->id))
        ->where('catalog', $catalog)
        ->sole();
}

function headPage(?User $user = null, array $query = [])
{
    return Livewire::withQueryParams($query)->actingAs($user ?? test()->jefe)->test('catalog-validation::pages.review');
}

function headViewer(string $who): User
{
    return $who === 'admin' ? User::factory()->admin()->create() : User::factory()->withRole(Role::Subdireccion, [])->create();
}

function headValidate(Catalog $catalog, ?User $by = null): CatalogReview
{
    $review = headReview($catalog);
    markAllReviewed($review, test()->jefe);
    $review->update(['status' => ReviewStatus::Validated, 'validated_by' => ($by ?? test()->jefe)->id, 'validated_at' => now()]);

    return $review;
}

describe('quién validó', function () {
    it('justo después de Validar dice quién validó, sin recargar', function (array $query) {
        markAllReviewed(headReview(Catalog::Subjects), $this->escolares);

        headPage($this->escolares, $query)
            ->call('confirm', 'validate', 'subjects')
            ->call('confirmed')
            ->assertHasNoErrors()
            ->assertSee('Validó '.$this->escolares->name)
            ->assertDontSee('un usuario ya dado de baja');
    })->with(['tablero' => [[]], 'lista' => [['catalogo' => 'subjects']]]);

    it('después de reabrir y volver a validar dice quién validó la última vez', function () {
        headValidate(Catalog::Subjects, User::factory()->create(['name' => 'Otra Persona']));

        headPage($this->escolares, ['catalogo' => 'subjects'])
            ->call('confirm', 'reopen', 'subjects')->call('confirmed')
            ->assertDontSee('Validó Otra Persona')
            ->call('confirm', 'validate', 'subjects')->call('confirmed')
            ->assertSee('Validó '.$this->escolares->name)
            ->assertDontSee('un usuario ya dado de baja');
    });
});

describe('guardar sin cambios (CV-S7, D9)', function () {
    it('CV-S7: «Guardar» sin cambios marca el registro como revisado, y el pie y el aviso lo dicen', function () {
        $component = headPage($this->escolares, ['catalogo' => 'subjects'])
            ->call('edit', $this->subject->id)
            ->assertSee('Al guardar, queda revisado.')
            ->call('save')
            ->assertHasNoErrors();

        expect(CatalogReviewMark::query()->where('record_id', $this->subject->id)->exists())->toBeTrue()
            ->and(lastToastOf($component)['title'])->toBe('Sin cambios: queda revisado');
    });

    it('CV-S7: con un cambio, el aviso dice que se actualizó', function () {
        $component = headPage($this->escolares, ['catalogo' => 'subjects'])->call('edit', $this->subject->id)->set('name', 'Química general')->call('save');

        expect(lastToastOf($component)['title'])->toBe('Materia actualizada');
    });
});

describe('quitar de <departamento> (CV8, D8, D10)', function () {
    it('CV8: «Quitar de Sistemas y Computación» va en «Acciones» de la fila, no como botón a su lado', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1', 'name' => 'Ana Beltrán']);

        $html = headPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSee('Quitar de Sistemas y Computación')
            ->assertDontSee('Dar de baja')
            ->assertSeeHtml("wire:click=\"askDischarge('{$teacher->id}')\"")
            ->html();

        $item = domOf($html)->querySelector('[data-testid="discharge-AAAA800101AA1"]');

        expect($item?->getAttribute('role'))->toBe('menuitem')
            ->and($item?->closest('[popover]'))->not->toBeNull();
    });

    it('CV8: la confirmación dice la consecuencia según le quede o no otro departamento', function () {
        $only = Teacher::factory()->adscribedTo($this->sis)->create(['name' => 'Ana Beltrán']);
        $shared = Teacher::factory()->adscribedTo($this->sis)->adscribedTo($this->ele, primary: false)->create(['name' => 'Beatriz Olivas']);

        headPage($this->jefe, ['catalogo' => 'teachers'])
            ->call('askDischarge', $only->id)
            ->assertSet('showDischarge', true)
            ->assertSee('¿Quitar a Ana Beltrán de Sistemas y Computación?')
            ->assertSee('Es su único departamento: queda dado de baja y su cuenta se inhabilita.')
            ->call('askDischarge', $shared->id)
            ->assertSee('¿Quitar a Beatriz Olivas de Sistemas y Computación?')
            ->assertSee('Sigue como docente de Eléctrica-Electrónica')
            ->assertDontSee('queda dado de baja y su cuenta se inhabilita');
    });

    it('CV8: el motivo es opcional; «Otro» pide escribirlo, y queda en la bitácora con la llave reason', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();

        $component = headPage($this->jefe, ['catalogo' => 'teachers'])
            ->call('askDischarge', $teacher->id)
            ->set('dischargeKind', 'other')
            ->call('dischargeTeacher')
            ->assertHasErrors('dischargeText');

        expect($teacher->fresh()->is_active)->toBeTrue();

        $component->set('dischargeText', 'Se cambia de institución')->call('dischargeTeacher')->assertHasNoErrors()->assertSet('showDischarge', false);

        expect($teacher->fresh()->is_active)->toBeFalse()
            ->and(Activity::query()->where('event', 'discharged')->sole()->properties['reason'])->toBe(['kind' => 'other', 'text' => 'Se cambia de institución'])
            ->and(lastToastOf($component)['title'])->toBe('Docente dado de baja');
    });

    it('CV8: sin motivo también se quita, y con otro departamento el aviso nombra el departamento', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->adscribedTo($this->ele, primary: false)->create();

        $component = headPage($this->jefe, ['catalogo' => 'teachers'])->call('askDischarge', $teacher->id)->call('dischargeTeacher')->assertHasNoErrors();

        expect(Activity::query()->where('event', 'discharged')->sole()->properties['reason'])->toBeNull()
            ->and(lastToastOf($component)['title'])->toBe('Docente quitado de Sistemas y Computación');
    });

    it('si otra sesión validó la revisión mientras el modal estaba abierto, lo dice en el modal y no quita', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
        $page = headPage($this->jefe, ['catalogo' => 'teachers'])->call('askDischarge', $teacher->id);
        headValidate(Catalog::Teachers);

        $page->call('dischargeTeacher')->assertHasErrors('discharge')->assertSet('showDischarge', true)->assertSeeHtml('data-testid="discharge-error"');

        expect($teacher->fresh()->is_active)->toBeTrue();
    });

    it('cambiar de pestaña cierra el modal y olvida al docente', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();

        headPage($this->jefe, ['catalogo' => 'teachers'])
            ->call('askDischarge', $teacher->id)
            ->set('catalog', 'subjects')
            ->assertSet('showDischarge', false)
            ->assertSet('dischargeId', null)
            ->set('catalog', 'teachers')
            ->assertSet('showDischarge', false);
    });

    it('un motivo que no es de la lista se rechaza', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();

        headPage($this->jefe, ['catalogo' => 'teachers'])->call('askDischarge', $teacher->id)->set('dischargeKind', 'vacaciones')->call('dischargeTeacher')->assertHasErrors('dischargeKind');

        expect($teacher->fresh()->is_active)->toBeTrue();
    });

    it('admin y Subdirección no piden ni hacen la baja llamando los métodos', function (string $who) {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
        $viewer = headViewer($who);

        headPage($viewer, ['department' => $this->sis->id, 'catalogo' => 'teachers'])->call('askDischarge', $teacher->id)->assertForbidden();

        $page = headPage($this->jefe, ['catalogo' => 'teachers'])->call('askDischarge', $teacher->id);
        $this->actingAs($viewer);
        $page->call('dischargeTeacher')->assertForbidden();

        expect($teacher->fresh()->is_active)->toBeTrue();
    })->with(['admin', 'subdirección']);
});

describe('el departamento por su nombre y el plural', function () {
    it('el modal de Validar y su aviso nombran el departamento, no su clave', function () {
        markAllReviewed(headReview(Catalog::Teachers), $this->jefe);

        $component = headPage()
            ->call('confirm', 'validate', 'teachers')
            ->assertSeeHtml('Vas a validar <strong>Docentes de Sistemas y Computación</strong>')
            ->assertDontSee('Docentes de 110100')
            ->call('confirmed');

        expect(lastToastOf($component)['description'])->toBe('Docentes de Sistemas y Computación');
    });

    it('CV15: en Servicios Escolares el modal y el aviso dicen «de todo el Tec»', function () {
        markAllReviewed(headReview(Catalog::Subjects), $this->escolares);

        $component = headPage($this->escolares)
            ->call('confirm', 'validate', 'subjects')
            ->assertSeeHtml('Vas a validar <strong>Materias de todo el Tec</strong>')
            ->assertSee('la carga académica de ningún departamento')
            ->call('confirmed');

        expect(lastToastOf($component)['description'])->toBe('Materias de todo el Tec');
    });

    it('con una observación abierta y un registro dice «Queda 1 observación abierta» y «1 registro activo»', function () {
        $review = headReview(Catalog::Rooms);
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->campus->id]))->create(['department_id' => $this->sis->id]);
        markAllReviewed($review, $this->jefe);
        CatalogReviewFlag::factory()->on($room)->create(['review_id' => $review->id]);

        headPage()
            ->call('confirm', 'validate', 'rooms')
            ->assertSeeHtml('<span class="font-mono">1</span> registro activo y <span class="font-mono">0</span> inactivos')
            ->assertSeeHtml('Queda <span class="font-mono">1</span> observación abierta; no impide validar.')
            ->assertDontSee('observaciones abiertas; no impiden');
    });
});

describe('cada «Tipo» con su nombre', function () {
    it('el drawer de una materia dice «Tipo de materia» y «Tipo de observación»; el de un salón, «Tipo de salón»', function () {
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->campus->id]))->create(['department_id' => $this->sis->id]);

        headPage($this->escolares, ['catalogo' => 'subjects'])->call('edit', $this->subject->id)->assertSee('Tipo de materia')->assertSee('Tipo de observación');
        headPage($this->jefe, ['catalogo' => 'rooms'])->call('edit', $room->id)->assertSee('Tipo de salón')->assertSee('Tipo de observación');
    });
});

describe('materias con su plan', function () {
    it('cada materia dice en qué planes está, para distinguir las que se llaman igual', function () {
        $other = Subject::factory()->create(['code' => 'AEC-1058', 'name' => 'Química', 'department_id' => $this->sis->id]);
        Subject::factory()->create(['code' => 'ZZZ-0001', 'name' => 'Química', 'department_id' => $this->sis->id]);
        placeInPlan(StudyPlan::factory()->create(['code' => 'IMCT-2005-284']), $this->subject);
        placeInPlan(StudyPlan::factory()->create(['code' => 'IELC-2010-211']), $other);
        placeInPlan(StudyPlan::factory()->create(['code' => 'IELE-2010-209']), $other);

        $html = headPage($this->escolares, ['catalogo' => 'subjects'])->html();
        $row = fn (string $code): string => textOf(domOf($html)->querySelector('[data-testid="subject-plans-'.$code.'"]'));

        expect($row('SCD-1027'))->toBe('IMCT-2005-284')
            ->and($row('AEC-1058'))->toBe('IELC-2010-211 · IELE-2010-209')
            ->and($row('ZZZ-0001'))->toBe('En ningún plan');
    });
});

describe('catálogos sin registros (CV7, D5)', function () {
    it('CV7: el tablero ofrece validar juntos los catálogos abiertos sin registros activos y la confirmación los nombra', function () {
        Subject::factory()->inactive()->create(['department_id' => $this->sis->id]);
        markAllReviewed(headReview(Catalog::Subjects), $this->escolares);

        $component = headPage($this->escolares)
            ->assertSee('2 catálogos sin registros')
            ->assertSeeHtml('data-testid="validate-empty"')
            ->call('confirmEmpty')
            ->assertSet('showEmpty', true)
            ->assertSee('Planes de estudio y Carreras')
            ->call('validateEmpty')
            ->assertHasNoErrors()
            ->assertSet('showEmpty', false)
            ->assertDontSeeHtml('data-testid="validate-empty"');

        foreach ([Catalog::StudyPlans, Catalog::Careers] as $catalog) {
            expect(headReview($catalog)->status)->toBe(ReviewStatus::Validated)
                ->and(Activity::query()->where('event', 'validated')->where('subject_id', headReview($catalog)->id)->exists())->toBeTrue();
        }

        expect(headReview(Catalog::Subjects)->status)->toBe(ReviewStatus::Pending)
            ->and(lastToastOf($component)['title'])->toBe('2 catálogos validados');
    });

    it('CV7: valida solo los que nombró la confirmación, y si uno recibió un registro activo mientras tanto no valida ninguno y lo dice', function () {
        $page = headPage()->call('confirmEmpty')->assertSee('Docentes y Salones');
        Teacher::factory()->adscribedTo($this->sis)->create();

        $page->call('validateEmpty')->assertHasErrors('status')->assertSee('La revisión de Docentes de Sistemas y Computación ya tiene registros activos');

        expect(CatalogReview::query()->where('status', ReviewStatus::Validated)->exists())->toBeFalse();
    });

    it('con uno solo dice «1 catálogo sin registros» y «Validarlo»; los ya validados no cuentan', function () {
        headValidate(Catalog::Teachers);

        $html = headPage()->assertSee('1 catálogo sin registros')->html();

        expect(textOf(domOf($html)->querySelector('[data-testid="validate-empty"]')))->toBe('Validarlo');
    });

    it('sin catálogos vacíos no lo ofrece', function () {
        foreach ([Catalog::Teachers, Catalog::Rooms] as $catalog) {
            headValidate($catalog);
        }

        headPage()->assertDontSeeHtml('data-testid="empty-reviews"')->assertDontSeeHtml('data-testid="validate-empty"');
    });

    it('Subdirección y el admin no lo ven ni validan los vacíos llamando los métodos', function (string $who) {
        $viewer = headViewer($who);

        headPage($viewer, ['department' => $this->sis->id])->assertDontSeeHtml('data-testid="empty-reviews"')->call('confirmEmpty')->assertForbidden();

        $page = headPage()->call('confirmEmpty');
        $this->actingAs($viewer);
        $page->call('validateEmpty')->assertForbidden();

        expect(CatalogReview::query()->where('status', ReviewStatus::Validated)->exists())->toBeFalse();
    })->with(['admin', 'subdirección']);
});

describe('correo institucional en la fila (CV1, CV-S2, AC-06; D4, D11)', function () {
    it('CV-S2: con la revisión de docentes validada el jefe sigue capturando el correo en la fila, y la revisión sigue validada', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1', 'institutional_email' => null]);
        headValidate(Catalog::Teachers);

        headPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSeeHtml('data-testid="add-email-AAAA800101AA1"')
            ->assertSee('Al guardar le llega su invitación a Nexus')
            ->call('saveEmail', $teacher->id, 'ana')
            ->assertReturned(['saved' => true, 'error' => null]);

        expect($teacher->fresh()->institutional_email)->toBe('ana@cdjuarez.tecnm.mx')
            ->and(headReview(Catalog::Teachers)->status)->toBe(ReviewStatus::Validated);
    });

    it('CV1: con la revisión abierta, guardar el correo lo marca como revisado', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['institutional_email' => null]);

        headPage($this->jefe, ['catalogo' => 'teachers'])
            ->assertSee('al guardar queda revisado')
            ->call('saveEmail', $teacher->id, 'ana')
            ->assertReturned(['saved' => true, 'error' => null]);

        expect(CatalogReviewMark::query()->where('record_id', $teacher->id)->exists())->toBeTrue();
    });

    it('AC-06: Subdirección no captura el correo, tampoco con la revisión validada', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1', 'institutional_email' => null]);
        headValidate(Catalog::Teachers);
        $subdireccion = headViewer('subdirección');

        headPage($subdireccion, ['department' => $this->sis->id, 'catalogo' => 'teachers'])
            ->assertDontSeeHtml('data-testid="add-email-AAAA800101AA1"')
            ->assertDontSee('Agregar correo institucional')
            ->call('saveEmail', $teacher->id, 'ana')
            ->assertForbidden();

        expect($teacher->fresh()->institutional_email)->toBeNull();
    });

    it('el enlace «N sin correo institucional» del tablero lleva a la lista filtrada por «sin correo», donde se captura', function () {
        Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1', 'name' => 'Ana Sincorreo', 'institutional_email' => null]);
        Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'BBBB800101BB1', 'name' => 'Beto Concorreo', 'institutional_email' => 'beto@cdjuarez.tecnm.mx']);
        headValidate(Catalog::Teachers);

        $link = domOf(headPage()->html())->querySelector('[data-testid="missing-correo"]');

        expect(textOf($link))->toBe('1 sin correo institucional')
            ->and($link->getAttribute('href'))->toBe(route('catalog-validation.review', ['department' => $this->sis->id, 'catalogo' => 'teachers', 'falta' => 'correo']));

        headPage($this->jefe, ['catalogo' => 'teachers', 'falta' => 'correo'])
            ->assertSee('Ana Sincorreo')
            ->assertDontSee('Beto Concorreo')
            ->assertSeeHtml('data-testid="add-email-AAAA800101AA1"');
    });

    it('el aviso de Validar dice que el correo se captura en la fila', function () {
        Teacher::factory()->adscribedTo($this->sis)->create(['institutional_email' => null, 'user_id' => User::factory()->create(['password' => null])->id]);
        markAllReviewed(headReview(Catalog::Teachers), $this->jefe);

        headPage()->call('confirm', 'validate', 'teachers')->assertSee('Captúralo en su fila de la lista')->assertDontSee('Captúralo en su ficha');
    });
});

describe('solo lectura de admin y Subdirección (CV-S1)', function () {
    it('no ven Validar, Reabrir, «Sin registros» ni sus motivos deshabilitados en el tablero', function (string $who) {
        headValidate(Catalog::Careers);

        headPage(headViewer($who), ['department' => ReviewOwner::SCHOOL_SERVICES])
            ->assertSeeHtml('data-testid="card-careers"')
            ->assertDontSeeHtml('data-testid="validate-subjects"')
            ->assertDontSeeHtml('data-testid="reopen-careers"')
            ->assertDontSee('Solo el jefe del departamento valida.')
            ->assertDontSee('Solo el jefe del departamento reabre.');
    })->with(['admin', 'subdirección']);

    it('en la lista no ven Validar, Marcar, Desactivar ni Agregar observación deshabilitados', function (string $who) {
        headPage(headViewer($who), ['department' => ReviewOwner::SCHOOL_SERVICES, 'catalogo' => 'subjects'])
            ->assertDontSeeHtml('data-testid="validate-subjects"')
            ->assertDontSee('Solo el jefe del departamento')
            ->assertDontSee('Agregar observación')
            ->assertDontSee('Desactivar')
            ->assertSeeHtml("wire:click=\"edit('{$this->subject->id}')\"");
    })->with(['admin', 'subdirección']);

    it('en docentes no ven Quitar, Agregar docente ni el correo deshabilitados, y sí la ficha y las observaciones', function (string $who) {
        $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1', 'institutional_email' => null]);

        headPage(headViewer($who), ['department' => $this->sis->id, 'catalogo' => 'teachers'])
            ->assertDontSee('Quitar de Sistemas y Computación')
            ->assertDontSee('Agregar docente')
            ->assertDontSee('Agregar correo institucional')
            ->assertSeeHtml('data-testid="observations-AAAA800101AA1"')
            ->assertSeeHtml('href="'.e(route('faculty.teachers.show', ['teacher' => $teacher->id, ...ValidationReturn::params($this->sis->id, 'teachers')])).'"');
    })->with(['admin', 'subdirección']);

    it('la cabecera le habla a quien mira; al jefe, a quien revisa', function (string $who) {
        headPage(headViewer($who), ['department' => $this->sis->id])
            ->assertSee('Consulta cómo va la revisión de los catálogos de cada departamento')
            ->assertDontSee('Revisa cada registro');

        headPage()->assertSee('Revisa cada registro de los catálogos del departamento')->assertDontSee('Consulta cómo va');
    })->with(['admin', 'subdirección']);

    it('CV-S14: un departamento que se desactivó con su validación abierta sigue abriéndose, para que su jefe la termine', function () {
        $this->sis->update(['is_active' => false]);

        headPage()->assertOk()->assertSee('Sistemas y Computación');
    });

    it('CV-S14: en el selector, el departamento desactivado sigue mientras tenga revisiones en el periodo', function (string $who) {
        $this->sis->update(['is_active' => false]);

        headPage(headViewer($who))->assertSee('110100 · Sistemas y Computación')->assertSee('110200 · Eléctrica-Electrónica');
    })->with(['admin', 'subdirección']);

    it('CV-S14: el selector de departamento solo trae los activos, y uno inactivo sin revisiones no se abre', function (string $who) {
        $closed = Department::factory()->create(['code' => '999999', 'name' => 'Departamento cerrado', 'is_active' => false]);
        $viewer = headViewer($who);

        headPage($viewer)->assertSee('Sistemas y Computación')->assertDontSee('Departamento cerrado');
        headPage($viewer, ['department' => $closed->id])->assertForbidden();
    })->with(['admin', 'subdirección']);
});

describe('el nombre en el menú', function () {
    it('el jefe ve «Validar catálogos»; admin y Subdirección, que solo miran, «Validación de catálogos»', function (string $who, string $label, string $other) {
        $user = match ($who) {
            'jefe' => test()->jefe,
            default => headViewer($who),
        };
        $this->actingAs($user);

        $labels = array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)));

        expect($labels)->toContain($label)->not->toContain($other);
    })->with([
        'jefe' => ['jefe', 'Validar catálogos', 'Validación de catálogos'],
        'admin' => ['admin', 'Validación de catálogos', 'Validar catálogos'],
        'subdirección' => ['subdirección', 'Validación de catálogos', 'Validar catálogos'],
    ]);

    it('en la pantalla, el menú marca como actual la entrada que ve quien la abre y la anota como su última página del área', function (string $who, string $label) {
        $user = $who === 'jefe' ? test()->jefe : headViewer($who);

        $current = domOf($this->actingAs($user)->get(route('catalog-validation.review', ['department' => $this->sis->id]))->assertOk())
            ->querySelectorAll('#nexus-menu [aria-current="page"]');

        expect($current)->toHaveCount(1)
            ->and(textOf($current->item(0)))->toBe($label)
            ->and(app(RecentPages::class)->last(NavigationArea::Academico))->toBe(route('catalog-validation.review', ['department' => $this->sis->id], false));
    })->with([
        'jefe' => ['jefe', 'Validar catálogos'],
        'admin' => ['admin', 'Validación de catálogos'],
        'subdirección' => ['subdirección', 'Validación de catálogos'],
    ]);
});
