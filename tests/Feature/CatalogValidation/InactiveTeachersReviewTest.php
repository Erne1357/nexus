<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CorrectRecord;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Actions\DischargeReviewTeacher;
use App\Modules\CatalogValidation\Actions\FlagRecord;
use App\Modules\CatalogValidation\Actions\MarkRecordsReviewed;
use App\Modules\CatalogValidation\Actions\SetReviewTeacherEmail;
use App\Modules\CatalogValidation\Actions\UnmarkRecordReviewed;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\CatalogValidation\Queries\CatalogRecordsQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| CV14 (confirmado el 20 de noviembre de 2026): en la revisión de docentes la lista muestra por omisión solo a los
| activos y el filtro «Estado» deja ver a los inactivos; los inactivos no se validan, solo se ven. CV7 sigue: no cuentan
| en «N de M revisados» ni bloquean Validar. Las Actions lo rechazan aunque la pantalla no lo ofrezca.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    Campus::factory()->create(['code' => 'C2']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle($this->term, Catalog::cases());
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->review = CatalogReview::query()->where('term_id', $this->term->id)->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->sole();
    $this->activo = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'AAAA800101AA1', 'name' => 'Ana Activa', 'institutional_email' => 'ana@cdjuarez.tecnm.mx']);
    $this->inactivo = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'BBBB800101BB1', 'name' => 'Ivo Inactivo', 'is_active' => false]);
});

function inactiveReviewPage(array $query = [])
{
    return Livewire::withQueryParams(['catalogo' => 'teachers', ...$query])->actingAs(test()->jefe)->test('catalog-validation::pages.review');
}

function inactiveRejection(Closure $action): ?string
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors())->flatten()->first();
    }

    return null;
}

describe('filtro «Estado» (CV14)', function () {
    it('CV14: por omisión la lista de docentes muestra solo a los activos', function () {
        inactiveReviewPage()->set('segment', 'todos')->assertSee('Ana Activa')->assertDontSee('Ivo Inactivo');
    });

    it('CV14: «Solo inactivos» deja ver a los inactivos y «Activos e inactivos» a todos', function () {
        inactiveReviewPage()->set('status', 'inactivos')->assertSet('segment', 'todos')->assertSee('Ivo Inactivo')->assertDontSee('Ana Activa');
        inactiveReviewPage()->set('status', 'todos')->assertSee('Ivo Inactivo')->assertSee('Ana Activa');
        inactiveReviewPage(['estado' => 'inactivos'])->assertSee('Ivo Inactivo');
    });

    it('CV14: un valor de «Estado» desconocido vuelve a solo activos', function () {
        inactiveReviewPage()->set('status', 'otro')->assertSet('status', '')->assertSee('Ana Activa')->assertDontSee('Ivo Inactivo');
    });

    it('CV14: las cifras del filtro segmentado siguen al estado elegido', function () {
        $counts = app(CatalogRecordsQuery::class);
        expect($counts->segmentCounts($this->review, true)['all'])->toBe(1)
            ->and($counts->segmentCounts($this->review, false)['all'])->toBe(1)
            ->and($counts->segmentCounts($this->review)['all'])->toBe(2);
    });

    it('CV14 y CV7: el filtro solo aplica a docentes; los demás catálogos no cambian', function () {
        $subjects = Livewire::withQueryParams(['catalogo' => 'subjects', 'estado' => 'inactivos'])->actingAs($this->jefe)->test('catalog-validation::pages.review');

        expect($subjects->html())->not->toContain('data-testid="filter-status"');
    });

    it('CV14: Quitar filtros regresa a solo activos', function () {
        inactiveReviewPage()->set('status', 'inactivos')->call('clearFilters')->assertSet('status', '');
    });
});

describe('un docente inactivo solo se ve (CV14)', function () {
    it('CV14: su fila no ofrece marcar, observar, cambiar el correo ni quitar; sí la ficha y la disponibilidad', function () {
        $html = inactiveReviewPage(['estado' => 'inactivos'])->html();

        expect($html)
            ->toContain('record-BBBB800101BB1')
            ->not->toContain('mark-BBBB800101BB1')
            ->not->toContain('flag-BBBB800101BB1')
            ->not->toContain('email-BBBB800101BB1')
            ->not->toContain('discharge-BBBB800101BB1')
            ->not->toContain('select-BBBB800101BB1');
    });

    it('CV14: el activo conserva todas sus acciones', function () {
        $html = inactiveReviewPage()->set('segment', 'todos')->html();

        expect($html)->toContain('mark-AAAA800101AA1')->toContain('flag-AAAA800101AA1')->toContain('discharge-AAAA800101AA1');
    });

    it('CV14: el panel del inactivo es de lectura: sin formulario de observaciones y con su motivo', function () {
        inactiveReviewPage(['estado' => 'inactivos'])->call('edit', $this->inactivo->id)
            ->assertSee('Un docente inactivo no se valida: solo se puede ver.')
            ->assertDontSeeHtml('data-testid="flag-form"');
    });

    it('CV14: el servidor rechaza marcar a un inactivo, aunque la pantalla no lo ofrezca', function () {
        $page = inactiveReviewPage(['estado' => 'todos'])->call('setReviewed', $this->inactivo->id, true)->assertHasErrors('record');

        $page->call('markSelected', [$this->inactivo->id, $this->activo->id])->assertHasErrors('record');
        expect(CatalogReviewMark::query()->count())->toBe(0);
    });

    it('CV14: el servidor rechaza el correo de un inactivo', function () {
        inactiveReviewPage(['estado' => 'todos'])->call('saveEmail', $this->inactivo->id, 'ivo')->assertReturned(fn (array $result): bool => $result['saved'] === false);

        expect($this->inactivo->fresh()->institutional_email)->toBeNull();
    });

    it('CV14: la Action rechaza quitarlo del departamento', function () {
        expect(inactiveRejection(fn () => app(DischargeReviewTeacher::class)->handle($this->review, $this->inactivo, $this->jefe)))->toContain('inactivos');
    });

    it('CV14: las Actions rechazan marcar, desmarcar, observar y corregir a un inactivo y no escriben nada', function () {
        CatalogReviewMark::query()->create(['catalog_review_id' => $this->review->id, 'record_id' => $this->inactivo->id, 'reviewed_by' => $this->jefe->id, 'reviewed_at' => now()]);
        $message = 'Los docentes inactivos no se validan: solo se pueden ver.';

        expect(inactiveRejection(fn () => app(MarkRecordsReviewed::class)->handle($this->review, [$this->inactivo->id], $this->jefe)))->toBe($message)
            ->and(inactiveRejection(fn () => app(UnmarkRecordReviewed::class)->handle($this->review, $this->inactivo, $this->jefe)))->toBe($message)
            ->and(inactiveRejection(fn () => app(FlagRecord::class)->handle($this->review, $this->inactivo, ReviewFlag::Other, 'x', $this->jefe)))->toBe($message)
            ->and(inactiveRejection(fn () => app(CorrectRecord::class)->handle($this->review, $this->inactivo, ['institutional_email' => 'ivo@cdjuarez.tecnm.mx'], $this->jefe)))->toBe($message)
            ->and(inactiveRejection(fn () => app(SetReviewTeacherEmail::class)->handle($this->review, $this->inactivo, 'ivo@cdjuarez.tecnm.mx', $this->jefe)))->toBe($message)
            ->and(CatalogReviewFlag::query()->count())->toBe(0)
            ->and(CatalogReviewMark::query()->count())->toBe(1)
            ->and($this->inactivo->fresh()->institutional_email)->toBeNull();
    });

    it('CV14: marcar juntos a un activo y a un inactivo no marca a ninguno', function () {
        expect(inactiveRejection(fn () => app(MarkRecordsReviewed::class)->handle($this->review, [$this->activo->id, $this->inactivo->id], $this->jefe)))->not->toBeNull()
            ->and(CatalogReviewMark::query()->count())->toBe(0);
    });

    it('CV14: al activo se le sigue marcando', function () {
        expect(app(MarkRecordsReviewed::class)->handle($this->review, [$this->activo->id], $this->jefe))->toBe(1);
    });

    it('CV14 y CV7: el inactivo no cuenta como pendiente ni bloquea Validar', function () {
        expect(app(CatalogContentsQuery::class)->unreviewedCount($this->review))->toBe(1);

        app(MarkRecordsReviewed::class)->handle($this->review, [$this->activo->id], $this->jefe);

        expect(app(CatalogContentsQuery::class)->unreviewedCount($this->review))->toBe(0);
    });

    it('CV14: la ficha de otro departamento sigue sin abrirse desde la lista de otro jefe (alcance)', function () {
        $otro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

        Livewire::withQueryParams(['catalogo' => 'teachers', 'department' => $this->sis->id])->actingAs($otro)->test('catalog-validation::pages.review')->assertForbidden();
    });
});
