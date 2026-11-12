<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| OpenTerm abre un periodo en un campus (D1, confirmado el 1 de noviembre de 2026): guarda la apertura
| la primera vez y despacha TermOpened dentro de su transacción, para que los módulos de la derecha
| creen lo suyo (S-A6, supuesto por confirmar). Abrir es todo o nada e idempotente: volver a abrir
| solo crea lo que falte. Nunca cambia `is_current` (S-A4).
*/

function openTerm(Term $term, Campus $campus): array
{
    return app(OpenTerm::class)->handle($term, $campus);
}

beforeEach(function () {
    $this->opener = User::factory()->admin()->create();
    $this->actingAs($this->opener);
});

it('guarda la apertura con quién y cuándo, y despacha TermOpened con el periodo y el campus', function () {
    Carbon::setTestNow('2026-11-01 10:15:00');
    $term = Term::factory()->create(['code' => '20271']);
    $campus = Campus::factory()->create(['code' => 'C2']);
    $heard = [];
    Event::listen(TermOpened::class, function (TermOpened $event) use (&$heard): void {
        $heard[] = [$event->term->code, $event->campus->code];
    });

    openTerm($term, $campus);

    $opening = TermOpening::sole();

    expect($heard)->toBe([['20271', 'C2']])
        ->and($opening->term_id)->toBe($term->id)
        ->and($opening->campus_id)->toBe($campus->id)
        ->and($opening->opened_by)->toBe($this->opener->id)
        ->and($opening->opened_at->toDateTimeString())->toBe('2026-11-01 10:15:00');
});

it('devuelve las líneas que reportan los listeners, en orden', function () {
    Event::listen(TermOpened::class, fn (TermOpened $event) => $event->report('2 cosas nuevas'));
    Event::listen(TermOpened::class, fn (TermOpened $event) => $event->report('1 cosa más'));

    expect(openTerm(Term::factory()->create(), Campus::factory()->create()))->toBe(['2 cosas nuevas', '1 cosa más']);
});

it('deja en la bitácora del periodo la apertura, con el campus y el reporte', function () {
    $term = Term::factory()->create(['code' => '20271']);
    $campus = Campus::factory()->create(['code' => 'C2']);
    Event::listen(TermOpened::class, fn (TermOpened $event) => $event->report('3 cosas nuevas'));

    openTerm($term, $campus);

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('opened')
        ->description->toBe('Abrió el periodo 20271 en C2')
        ->and($entry->subject->is($term))->toBeTrue()
        ->and($entry->causer->is($this->opener))->toBeTrue()
        ->and($entry->properties->toArray())->toBe(['campus_id' => $campus->id, 'report' => ['3 cosas nuevas'], 'acting_as' => 'admin']);
});

it('volver a abrir no repite la apertura ni cambia quién y cuándo, y registra que completó la apertura', function () {
    $term = Term::factory()->create(['code' => '20271']);
    $campus = Campus::factory()->create(['code' => 'C2']);
    openTerm($term, $campus);
    $first = TermOpening::sole();

    Carbon::setTestNow(now()->addDay());
    $this->actingAs($other = User::factory()->admin()->create());

    expect(openTerm($term, $campus))->toBe([])
        ->and(TermOpening::sole()->only(['id', 'opened_by', 'opened_at']))->toEqual($first->only(['id', 'opened_by', 'opened_at']));

    $entry = Activity::query()->latest('id')->first();

    expect($entry->description)->toBe('Completó la apertura del periodo 20271 en C2')
        ->and($entry->causer->is($other))->toBeTrue()
        ->and($entry->properties->toArray())->toBe(['campus_id' => $campus->id, 'report' => [], 'acting_as' => 'admin']);
});

it('vuelve a despachar TermOpened al completar una apertura, para crear lo que falte', function () {
    $term = Term::factory()->create();
    $campus = Campus::factory()->create();
    openTerm($term, $campus);

    $heard = 0;
    Event::listen(TermOpened::class, function () use (&$heard): void {
        $heard++;
    });
    openTerm($term, $campus);

    expect($heard)->toBe(1);
});

it('no cambia qué periodo es el actual', function () {
    $current = Term::factory()->current()->create();
    $next = Term::factory()->create();

    openTerm($next, Campus::factory()->create());

    expect($current->fresh()->is_current)->toBeTrue()
        ->and($next->fresh()->is_current)->toBeFalse();
});

it('rechaza un campus inactivo o borrado sin escribir nada', function (Campus $campus) {
    $heard = false;
    Event::listen(TermOpened::class, function () use (&$heard): void {
        $heard = true;
    });

    try {
        openTerm(Term::factory()->create(), $campus);
        $this->fail('Debió rechazar el campus.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['campus' => ['El campus C9 está inactivo o borrado: no se puede abrir el periodo en él.']]);
    }

    expect($heard)->toBeFalse()
        ->and(TermOpening::count())->toBe(0)
        ->and(Activity::count())->toBe(0);
})->with([
    'inactivo' => fn () => Campus::factory()->inactive()->create(['code' => 'C9']),
    'borrado' => fn () => tap(Campus::factory()->create(['code' => 'C9']))->delete(),
    'desactivado después de cargarlo' => fn () => tap(Campus::factory()->create(['code' => 'C9']), fn (Campus $campus) => Campus::query()->whereKey($campus->id)->update(['is_active' => false])),
]);

it('es todo o nada: si un listener falla, no queda la apertura ni la bitácora', function () {
    Event::listen(TermOpened::class, fn () => throw new RuntimeException('Falla un módulo.'));

    expect(fn () => openTerm(Term::factory()->create(), Campus::factory()->create()))->toThrow(RuntimeException::class, 'Falla un módulo.');

    expect(TermOpening::count())->toBe(0)
        ->and(Activity::count())->toBe(0);
});

it('pide una sesión: sin usuario no sabe quién abre', function () {
    auth()->logout();

    expect(fn () => openTerm(Term::factory()->create(), Campus::factory()->create()))
        ->toThrow(AuthorizationException::class, 'Inicia sesión para abrir el periodo.');

    expect(TermOpening::count())->toBe(0);
});

describe('con los módulos que lo escuchan (AcademicLoad y CatalogValidation)', function () {
    beforeEach(function () {
        $this->term = Term::factory()->create(['code' => '20271']);
        $this->c2 = Campus::factory()->create(['code' => 'C2']);
        Department::factory()->create(['code' => 'D1']);
        Department::factory()->create(['code' => 'D2']);
        Department::factory()->create(['code' => 'D3']);
        Department::factory()->inactive()->create(['code' => 'D8']);
        Department::factory()->create(['code' => 'D9'])->delete();
    });

    it('abrir en C2 crea la carga en borrador de cada departamento activo y, desde CV9, ninguna revisión, y lo reporta', function () {
        expect(openTerm($this->term, $this->c2))->toBe(['3 cargas académicas nuevas']);

        $loads = AcademicLoad::query()->with('department')->get();
        $reviews = CatalogReview::query()->with('department')->get();

        expect($loads->map(fn (AcademicLoad $load): string => $load->department->code)->sort()->values()->all())->toBe(['D1', 'D2', 'D3'])
            ->and($loads->every(fn (AcademicLoad $load): bool => $load->campus_id === $this->c2->id && $load->status === LoadStatus::Draft))->toBeTrue()
            ->and($reviews)->toBeEmpty()
            ->and(Activity::sole()->properties['report'])->toBe(['3 cargas académicas nuevas']);
    });

    it('volver a abrir en C2 no crea nada y devuelve el reporte vacío', function () {
        openTerm($this->term, $this->c2);

        expect(openTerm($this->term, $this->c2))->toBe([])
            ->and(AcademicLoad::count())->toBe(3)
            ->and(CatalogReview::count())->toBe(0);
    });

    it('completar la apertura crea solo lo del departamento nuevo', function () {
        openTerm($this->term, $this->c2);
        Department::factory()->create(['code' => 'D4']);

        expect(openTerm($this->term, $this->c2))->toBe(['1 carga académica nueva']);
    });

    it('abrir después en C1 crea sus cargas', function () {
        openTerm($this->term, $this->c2);
        $c1 = Campus::factory()->create(['code' => 'C1']);

        expect(openTerm($this->term, $c1))->toBe(['3 cargas académicas nuevas'])
            ->and(AcademicLoad::query()->where('campus_id', $c1->id)->count())->toBe(3)
            ->and(CatalogReview::count())->toBe(0);
    });

    it('en un campus cuyo perfil no usa salones solo crea sus cargas', function () {
        $online = Campus::factory()->create(['code' => 'ENL', 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);

        expect(openTerm($this->term, $online))->toBe(['3 cargas académicas nuevas'])
            ->and(CatalogReview::query()->exists())->toBeFalse();
    });

    it('es todo o nada: si un listener falla después, no quedan apertura, cargas ni revisiones', function () {
        $createdBeforeFailing = [];
        Event::listen(TermOpened::class, function () use (&$createdBeforeFailing): void {
            $createdBeforeFailing = [AcademicLoad::count()];

            throw new RuntimeException('Falla un módulo.');
        });

        expect(fn () => openTerm($this->term, $this->c2))->toThrow(RuntimeException::class);

        expect($createdBeforeFailing)->toBe([3])
            ->and(TermOpening::count())->toBe(0)
            ->and(AcademicLoad::count())->toBe(0)
            ->and(CatalogReview::count())->toBe(0)
            ->and(Activity::count())->toBe(0);
    });

    it('un campus inactivo no crea cargas ni revisiones', function () {
        $closed = Campus::factory()->inactive()->create();

        expect(fn () => openTerm($this->term, $closed))->toThrow(ValidationException::class);

        expect(AcademicLoad::count())->toBe(0)
            ->and(CatalogReview::count())->toBe(0);
    });
});
