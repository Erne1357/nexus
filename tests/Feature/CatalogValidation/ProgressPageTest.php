<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Jobs\RemindPendingReviewsJob;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\TermCalendarQuery;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Tarea 2.5: el tablero de avance de validación (`/catalog-validation/progress`, catalog-validation::pages.progress),
| variante A «matriz» (DESIGN.md). Lo ven y recuerdan Subdirección y el admin; los demás roles reciben 403
| (supuesto por confirmar). Resolver una observación desde el drawer sigue a CatalogReviewPolicy::resolveFlag.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica y Electrónica']);

    // El periodo abierto en Presencial: C1 y C2 (M4).
    TermOpening::factory()->create(['term_id' => $this->term->id]);
    app(CreateTermReviews::class)->handle($this->term, modalityOf('PRES'));
    app(CreateTermLoads::class)->handle($this->term, modalityOf('PRES'));

    $this->jefeSis = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create(['name' => 'Dra. Ana Soto']);
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion, [])->create();
    $this->admin = User::factory()->admin()->create();
});

function progressPage(?User $user = null, array $query = [])
{
    return Livewire::withQueryParams($query)->actingAs($user ?? test()->subdireccion)->test('catalog-validation::pages.progress');
}

function progressMakeFlag(Department $department, ReviewFlag $flag = ReviewFlag::Other, ?string $comment = 'Las horas deben ser 3.'): CatalogReviewFlag
{
    $subject = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Contabilidad financiera', 'department_id' => $department->id]);
    $review = CatalogReview::query()->where('department_id', $department->id)->where('catalog', Catalog::Subjects)->sole();

    return CatalogReviewFlag::factory()->on($subject)->create(['review_id' => $review->id, 'flag' => $flag, 'comment' => $comment, 'flagged_by' => test()->jefeSis->id]);
}

describe('quién entra', function () {
    it('Subdirección y el admin ven el avance global, la matriz y los totales', function (string $who) {
        progressPage($who === 'admin' ? $this->admin : $this->subdireccion)
            ->assertOk()
            ->assertSee('Avance de validación')
            ->assertSee('0 de 10')
            ->assertSee('Sistemas y Computación')
            ->assertSee('Dra. Ana Soto')
            ->assertSee('Salones')
            ->assertSee('Total del Tec');
    })->with(['admin', 'subdirección']);

    it('la ruta responde con su entrada en el menú', function () {
        $this->actingAs($this->subdireccion)->get(route('catalog-validation.progress'))->assertOk()->assertSee('Avance de validación');
    });

    it('jefe, coordinador, jefe de proyecto docente y docente reciben 403 en la página, en la ruta y en cada método', function (Role $role, array $scope, string $department) {
        $user = User::factory()->withRole($role, $scope)->create();

        progressPage($user)->assertForbidden();
        $this->actingAs($user)->get(route('catalog-validation.progress'))->assertForbidden();

        $page = progressPage($this->subdireccion);
        $this->actingAs($user);
        $page->call('remind')->assertForbidden();
        $page = progressPage($this->subdireccion);
        $this->actingAs($user);
        $page->call('open', $department)->assertForbidden();
    })->with([
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => test()->ele->id], fn () => test()->ele->id],
        'coordinador' => [Role::Coordinador, fn () => ['campus_id' => test()->c2->id, 'career_id' => careerKey(20)], fn () => test()->sis->id],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['campus_id' => test()->c2->id, 'department_id' => test()->sis->id], fn () => test()->sis->id],
        'docente' => [Role::Docente, fn () => ['campus_id' => test()->c2->id], fn () => test()->sis->id],
    ]);

    it('el menú de Académico lo ofrece a Subdirección y no al jefe', function () {
        $this->actingAs($this->subdireccion)->get(route('catalog-validation.review'))->assertSee('catalog-validation/progress', false);
        $this->actingAs($this->jefeSis)->get(route('catalog-validation.review'))->assertDontSee('catalog-validation/progress', false);
    });

    it('un invitado va al login', function () {
        $this->get(route('catalog-validation.progress'))->assertRedirect(route('login'));
    });
});

describe('salones en una sola columna', function () {
    it('una columna «Salones» con la revisión de salones del departamento, una para todos los campus (decisión 6)', function () {
        $html = progressPage()->html();

        expect(substr_count($html, 'data-testid="column-rooms"'))->toBe(1)
            ->and(substr_count($html, 'data-testid="cell-SIS-rooms"'))->toBe(1)
            ->and($html)->not->toContain('room-badge-');
    });

    it('con un campus filtrado la columna sigue siendo la misma', function () {
        expect(substr_count(progressPage()->set('campus', $this->c1->id)->html(), 'data-testid="cell-SIS-rooms"'))->toBe(1);
    });

    it('el total de Salones cuenta los departamentos', function () {
        CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Rooms)->update(['status' => ReviewStatus::Validated, 'validated_at' => now()]);

        expect(progressPage()->html())->toMatch('/data-testid="total-rooms".*?1 de 2/s');
    });

    it('trae una leyenda de símbolos debajo de la matriz', function () {
        progressPage()->assertSeeHtml('data-testid="progress-legend"');
    });
});

describe('accesibilidad y renglones', function () {
    it('en el celular la fecha del último cambio va visible en los catálogos que faltan', function () {
        CatalogReview::query()->where('department_id', $this->ele->id)->where('catalog', Catalog::Teachers)->update(['status' => ReviewStatus::InReview]);

        $html = progressPage()->html();

        expect(preg_match('/data-testid="progress-cards".*?<\/ul>/s', $html, $cards))->toBe(1)
            ->and($cards[0])->toContain('hoy');
    });

    it('«Carga:» sale con las cargas que se pintan, separadas por un punto medio', function () {
        $html = progressPage()->html();

        expect($html)->toContain('data-testid="loads-SIS"')->and(preg_match('/loads-SIS.*?C1.*?·.*?C2/s', $html))->toBe(1);
    });

    it('con una sola carga no hay separador, y sin ninguna no hay «Carga:»', function () {
        $page = progressPage()->set('campus', $this->c1->id);
        expect(preg_match('/data-testid="loads-SIS".*?<\/p>/s', $page->html(), $line))->toBe(1)
            ->and($line[0])->not->toContain('·');

        DB::table('academic_loads')->delete();
        $html = progressPage()->html();

        expect($html)->not->toContain('data-testid="loads-SIS"')->not->toContain('Carga:');
    });
});

describe('recordar a todos', function () {
    it('el número de jefes tiene texto accesible propio y, sin nadie por recordar, el botón queda deshabilitado con su motivo', function () {
        expect(progressPage()->html())->toContain('1 jefe por recordar');

        $html = progressPage()->set('search', 'nadie')->html();

        expect($html)->toMatch('/data-testid="remind-all"[^>]*disabled|disabled[^>]*data-testid="remind-all"/')
            ->and($html)->toContain('Ningún departamento visible tiene jefe con catálogos pendientes.');
    });

    it('pinta cuántos jefes se recordarán en la insignia del botón', function () {
        expect(progressPage()->html())->toMatch('/data-testid="remind-all".*?badge[^>]*>\s*1\s*</s');
    });
});

describe('conteos', function () {
    it('CV7: cada catálogo sin validar dice cuántos registros van revisados, también Salones', function () {
        $subject = Subject::factory()->create(['department_id' => $this->sis->id]);
        Subject::factory()->create(['department_id' => $this->sis->id]);
        $review = CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Subjects)->sole();
        CatalogReviewMark::factory()->for($review, 'review')->on($subject)->create();
        $room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->c2->id]))->create(['department_id' => $this->sis->id]);

        progressPage()
            ->assertSeeHtml('1/2<span class="sr-only"> revisados</span>')
            ->assertSeeHtml('0/1<span class="sr-only"> revisados</span>')
            ->assertSee('Pendiente · 0 de 1 revisados');

        expect($room->department_id)->toBe($this->sis->id);
    });

    it('cuenta las validadas y en revisión del periodo activo, y las observaciones abiertas', function () {
        CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', '!=', Catalog::Rooms)->update(['status' => ReviewStatus::Validated, 'validated_at' => now()]);
        CatalogReview::query()->where('department_id', $this->ele->id)->where('catalog', Catalog::Teachers)->update(['status' => ReviewStatus::InReview]);
        progressMakeFlag($this->sis);

        progressPage()
            ->assertSee('4 de 10')
            ->assertSee('1 en revisión')
            ->assertSee('5 pendientes')
            ->assertSeeInOrder(['Observaciones abiertas', '1', 'en 1 departamento'])
            ->assertSee('4 de 5');
    });

    it('muestra la ventana de validación de catálogos con los días que quedan, y nada si no existe', function () {
        progressPage()->assertDontSee('Validación de catálogos');

        $this->travelTo(now()->setDate(2026, 11, 12)->startOfDay());
        TermStage::factory()->create(['term_id' => $this->term->id, 'stage' => Stage::CatalogValidation, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-19']);
        // La primera vista guardó el calendario sin etapas por un día (TermCalendarQuery); la factory no avisa como
        // la pantalla de etapas, así que se olvida a mano. Sin esto la prueba dependía de que el viaje en el tiempo
        // pasara de ese día y falló desde el 11 de noviembre de 2026.
        Cache::forget(TermCalendarQuery::key($this->term->id));

        progressPage()->assertSee('Validación de catálogos')->assertSee('10–19 nov')->assertSee('quedan 7 días');
    });

    it('muestra el estado de la carga de cada departamento por campus', function () {
        progressPage()->assertSee('Borrador');
    });

    it('sin periodo activo o sin campus abierto dice por qué no hay matriz', function () {
        TermOpening::query()->delete();

        progressPage()->assertOk()->assertSee('no está abierto en ningún campus');
    });
});

describe('filtros', function () {
    it('filtra por campus: un campus sin salones no cuenta la de salones', function () {
        $this->c1->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);

        progressPage()->set('campus', $this->c1->id)->assertSee('0 de 8')->assertDontSee('0 de 10');
    });

    it('filtra por estado y por búsqueda de departamento o jefe', function () {
        CatalogReview::query()->where('department_id', $this->sis->id)->update(['status' => ReviewStatus::Validated, 'validated_at' => now()]);

        progressPage()->set('status', 'pending')->assertSee('Eléctrica y Electrónica')->assertDontSee('Sistemas y Computación');
        progressPage()->set('status', 'validated')->assertSee('Sistemas y Computación')->assertDontSee('Eléctrica y Electrónica');
        progressPage()->set('search', 'soto')->assertSee('Sistemas y Computación')->assertDontSee('Eléctrica y Electrónica');
    });
});

describe('recordar', function () {
    it('«Recordar a todos» manda a la cola un job con los departamentos con jefe y pendientes, y lo dice', function () {
        Queue::fake();

        $page = progressPage()->call('remind');

        Queue::assertPushed(RemindPendingReviewsJob::class, fn (RemindPendingReviewsJob $job) => $job->departmentIds === [$this->sis->id]
            && $job->actorId === $this->subdireccion->id
            && $job->campusIds !== []);
        expect(lastToastOf($page)['title'])->toBe('Enviando recordatorios a 1 departamento');
    });

    it('«Recordar a todos» respeta la búsqueda, el estado y el campus', function () {
        $otro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create(['name' => 'Ing. Raúl Terrazas']);
        Queue::fake();

        progressPage()->set('search', 'terrazas')->call('remind');
        Queue::assertPushed(RemindPendingReviewsJob::class, fn (RemindPendingReviewsJob $job) => $job->departmentIds === [$this->ele->id]);

        Queue::fake();
        $page = progressPage()->set('search', 'nadie')->call('remind');
        Queue::assertNothingPushed();
        expect(lastToastOf($page)['title'])->toBe('Nadie por recordar');

        Queue::fake();
        progressPage()->set('search', '')->set('campus', $this->c1->id)->call('remind');
        Queue::assertPushed(RemindPendingReviewsJob::class, fn (RemindPendingReviewsJob $job) => $job->campusIds === [$this->c1->id] && count($job->departmentIds) === 2);
    });

    it('«Recordar» de una fila avisa en línea y dice a cuántos jefes, o «Nadie por recordar»', function () {
        Notification::fake();

        $page = progressPage()->call('remind', $this->sis->id);
        Notification::assertSentTo($this->jefeSis, CatalogReviewsPending::class);
        expect(lastToastOf($page)['title'])->toBe('Se avisó a 1 jefe');

        $page = progressPage()->call('remind', $this->ele->id);
        expect(lastToastOf($page)['title'])->toBe('Nadie por recordar');
        progressPage()->call('remind', 'no-es-uuid')->assertNotFound();
    });

    it('el último recordatorio aparece en el drawer', function () {
        Notification::fake();

        progressPage()->call('remind', $this->sis->id)->call('open', $this->sis->id)->assertSee('Último recordatorio')->assertSee('hoy');
    });

    it('con un campus filtrado solo avisa en ese campus', function () {
        Notification::fake();

        $page = progressPage()->set('campus', $this->c1->id)->call('remind', $this->sis->id);

        expect(lastToastOf($page)['title'])->toBe('Se avisó a 1 jefe');
        expect(Activity::query()->where('event', 'reminded')->sole()->properties['campuses'])->toBe(['C1']);
    });

    it('el drawer de un departamento que el filtro dejó fuera se abre igual', function () {
        progressPage()->set('search', 'sistemas')->call('open', $this->ele->id)->assertSee('Eléctrica y Electrónica')->assertSee('Catálogos');
    });
});

describe('drawer', function () {
    it('muestra los catálogos con quién y cuándo validó y las observaciones abiertas', function () {
        CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefeSis->id, 'validated_at' => now()]);
        progressMakeFlag($this->sis);

        progressPage()->call('open', $this->sis->id)
            ->assertSet('showDrawer', true)
            ->assertSee('Validó Dra. Ana Soto')
            ->assertSee('ACF-0901')->assertSee('Contabilidad financiera')->assertSee('Las horas deben ser 3.')
            ->assertSee('Marcar como resuelta');
    });

    it('un salón sin nombre se muestra con su clave y no como un registro que ya no está', function () {
        $building = Building::factory()->create(['campus_id' => $this->c2->id]);
        $room = Room::factory()->create(['building_id' => $building->id, 'code' => 'NEE1', 'name' => null, 'department_id' => $this->sis->id]);
        $review = CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Rooms)->sole();
        CatalogReviewFlag::factory()->on($room)->create(['review_id' => $review->id, 'flag' => ReviewFlag::Other, 'comment' => 'Revisar la clave.', 'flagged_by' => $this->jefeSis->id]);

        progressPage()->call('open', $this->sis->id)->assertSee('NEE1')->assertDontSee('Registro ya no disponible');
    });

    it('agrupa las observaciones por catálogo y registro', function () {
        $flag = progressMakeFlag($this->sis);
        CatalogReviewFlag::factory()->on($flag->flaggable)->create(['review_id' => $flag->review_id, 'flag' => ReviewFlag::Duplicate, 'comment' => 'Otra más.', 'flagged_by' => $this->jefeSis->id]);

        $html = progressPage()->call('open', $this->sis->id)->html();

        expect(substr_count($html, 'data-testid="drawer-record-'))->toBe(1)
            ->and($html)->toContain('Las horas deben ser 3.')->toContain('Otra más.');
    });

    it('el pie del drawer no tiene «Cerrar» y los estados se llaman igual que en la matriz', function () {
        $html = progressPage()->call('open', $this->sis->id)->html();

        expect(preg_match('/<footer.*?<\/footer>/s', $html, $footer))->toBe(1)
            ->and($footer[0])->toContain('Recordar')->and($footer[0])->not->toContain('Cerrar')
            ->and($html)->not->toContain('Sin abrir');
    });

    it('agrupa por revisión: los salones de todos los campus van en un solo grupo', function () {
        $review = CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Rooms)->sole();

        foreach ([$this->c1, $this->c2] as $i => $campus) {
            $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id, 'code' => 'SAL'.$i, 'department_id' => $this->sis->id]);
            CatalogReviewFlag::factory()->on($room)->create(['review_id' => $review->id, 'flag' => ReviewFlag::Other, 'comment' => 'Revisar.', 'flagged_by' => $this->jefeSis->id]);
        }

        $html = progressPage()->call('open', $this->sis->id)->html();

        expect(substr_count($html, 'data-testid="drawer-review-flags-'))->toBe(1)
            ->and($html)->toContain('SAL0')->toContain('SAL1');
    });

    it('la fecha de validación se ve en el drawer sin tooltip', function () {
        CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefeSis->id, 'validated_at' => now()]);

        progressPage()->call('open', $this->sis->id)->assertSee(now()->timezone(config('app.timezone'))->translatedFormat('j M Y'));
    });

    it('un id que no es de un departamento activo no abre nada', function () {
        progressPage()->call('open', 'no-es-uuid')->assertNotFound();
        progressPage()->call('open', fakeKey(999))->assertNotFound();
    });

    it('Subdirección y el admin resuelven una observación desde el drawer', function (string $who) {
        $flag = progressMakeFlag($this->sis);
        $user = $who === 'admin' ? $this->admin : $this->subdireccion;

        $page = progressPage($user)->call('open', $this->sis->id)->call('resolveFlag', $flag->id);

        expect($flag->fresh()->resolved_by)->toBe($user->id)
            ->and(lastToastOf($page)['title'])->toBe('Observación resuelta');
    })->with(['admin', 'subdirección']);

    it('no resuelve con un id ajeno ni un usuario sin permiso', function () {
        $flag = progressMakeFlag($this->sis);

        progressPage()->call('resolveFlag', 'no-es-uuid')->assertNotFound();

        $page = progressPage();
        $this->actingAs($this->jefeSis);
        $page->call('resolveFlag', $flag->id)->assertForbidden();
        expect($flag->fresh()->resolved_at)->toBeNull();
    });
});
