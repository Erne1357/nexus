<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| OpenTerm abre un periodo en una modalidad (M4, confirmado el 12 de noviembre de 2026): Presencial se abre una sola
| vez para C1 y C2. Guarda la apertura la primera vez y despacha TermOpened dentro de su transacción, para que los
| módulos de la derecha creen lo suyo (las cargas; MS15, supuesto por confirmar). Desde CV9 las revisiones de catálogo
| no nacen al abrir: las abre el admin. Abrir es todo o nada e idempotente: volver a abrir solo crea lo que falte.
| Nunca cambia `is_current`.
*/

function openTerm(Term $term, Modality $modality): array
{
    return app(OpenTerm::class)->handle($term, $modality);
}

beforeEach(function () {
    $this->opener = User::factory()->admin()->create();
    $this->actingAs($this->opener);
    $this->presencial = modalityOf('PRES');
});

it('M4: abre el periodo en una modalidad activa: guarda quién y cuándo, y despacha TermOpened con esa modalidad', function () {
    Carbon::setTestNow('2026-11-01 10:15:00');
    $term = Term::factory()->create(['code' => '20271']);
    $heard = [];
    Event::listen(TermOpened::class, function (TermOpened $event) use (&$heard): void {
        $heard[] = [$event->term->code, $event->modality->code];
    });

    openTerm($term, $this->presencial);

    $opening = TermOpening::sole();

    expect($heard)->toBe([['20271', 'PRES']])
        ->and($opening->term_id)->toBe($term->id)
        ->and($opening->modality_id)->toBe($this->presencial->id)
        ->and($opening->opened_by)->toBe($this->opener->id)
        ->and($opening->opened_at->toDateTimeString())->toBe('2026-11-01 10:15:00');
});

it('devuelve las líneas que reportan los listeners, en orden', function () {
    Event::listen(TermOpened::class, fn (TermOpened $event) => $event->report('2 cosas nuevas'));
    Event::listen(TermOpened::class, fn (TermOpened $event) => $event->report('1 cosa más'));

    expect(openTerm(Term::factory()->create(), $this->presencial))->toBe(['2 cosas nuevas', '1 cosa más']);
});

it('deja en la bitácora del periodo la apertura, con la modalidad y el reporte', function () {
    $term = Term::factory()->create(['code' => '20271']);
    Event::listen(TermOpened::class, fn (TermOpened $event) => $event->report('3 cosas nuevas'));

    openTerm($term, $this->presencial);

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('opened')
        ->description->toBe('Abrió el periodo 20271 en Presencial')
        ->and($entry->subject->is($term))->toBeTrue()
        ->and($entry->causer->is($this->opener))->toBeTrue()
        ->and($entry->properties->toArray())->toBe(['modality_id' => $this->presencial->id, 'report' => ['3 cosas nuevas'], 'acting_as' => 'admin']);
});

it('volver a abrir no repite la apertura ni cambia quién y cuándo, y registra que completó la apertura', function () {
    $term = Term::factory()->create(['code' => '20271']);
    openTerm($term, $this->presencial);
    $first = TermOpening::sole();

    Carbon::setTestNow(now()->addDay());
    $this->actingAs($other = User::factory()->admin()->create());

    expect(openTerm($term, $this->presencial))->toBe([])
        ->and(TermOpening::sole()->only(['id', 'opened_by', 'opened_at']))->toEqual($first->only(['id', 'opened_by', 'opened_at']));

    $entry = Activity::query()->latest('id')->first();

    expect($entry->description)->toBe('Completó la apertura del periodo 20271 en Presencial')
        ->and($entry->causer->is($other))->toBeTrue()
        ->and($entry->properties->toArray())->toBe(['modality_id' => $this->presencial->id, 'report' => [], 'acting_as' => 'admin']);
});

it('vuelve a despachar TermOpened al completar una apertura, para crear lo que falte', function () {
    $term = Term::factory()->create();
    openTerm($term, $this->presencial);

    $heard = 0;
    Event::listen(TermOpened::class, function () use (&$heard): void {
        $heard++;
    });
    openTerm($term, $this->presencial);

    expect($heard)->toBe(1);
});

it('abrir en otra modalidad es otra apertura del mismo periodo', function () {
    $term = Term::factory()->create();
    $other = Modality::factory()->create();

    openTerm($term, $this->presencial);
    openTerm($term, $other);

    expect(TermOpening::query()->where('term_id', $term->id)->pluck('modality_id')->sort()->values()->all())
        ->toEqual(collect([$this->presencial->id, $other->id])->sort()->values()->all());
});

it('no cambia qué periodo es el actual', function () {
    $current = Term::factory()->current()->create();
    $next = Term::factory()->create();

    openTerm($next, $this->presencial);

    expect($current->fresh()->is_current)->toBeTrue()
        ->and($next->fresh()->is_current)->toBeFalse();
});

it('M4: no abre en una modalidad inactiva o borrada, y no escribe nada', function (Modality $modality) {
    $heard = false;
    Event::listen(TermOpened::class, function () use (&$heard): void {
        $heard = true;
    });

    try {
        openTerm(Term::factory()->create(), $modality);
        $this->fail('Debió rechazar la modalidad.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['modality' => ['La modalidad M999 está inactiva o borrada: no se puede abrir el periodo en ella.']]);
    }

    expect($heard)->toBeFalse()
        ->and(TermOpening::count())->toBe(0)
        ->and(Activity::count())->toBe(0);
})->with([
    'inactiva' => fn () => Modality::factory()->inactive()->create(['code' => 'M999']),
    'borrada' => fn () => tap(Modality::factory()->create(['code' => 'M999']))->delete(),
    'desactivada después de cargarla' => fn () => tap(Modality::factory()->create(['code' => 'M999']), fn (Modality $modality) => Modality::query()->whereKey($modality->id)->update(['is_active' => false])),
]);

it('es todo o nada: si un listener falla, no queda la apertura ni la bitácora', function () {
    Event::listen(TermOpened::class, fn () => throw new RuntimeException('Falla un módulo.'));

    expect(fn () => openTerm(Term::factory()->create(), $this->presencial))->toThrow(RuntimeException::class, 'Falla un módulo.');

    expect(TermOpening::count())->toBe(0)
        ->and(Activity::count())->toBe(0);
});

it('pide una sesión: sin usuario no sabe quién abre', function () {
    auth()->logout();

    expect(fn () => openTerm(Term::factory()->create(), $this->presencial))
        ->toThrow(AuthorizationException::class, 'Inicia sesión para abrir el periodo.');

    expect(TermOpening::count())->toBe(0);
});

describe('con el módulo que lo escucha (AcademicLoad)', function () {
    beforeEach(function () {
        $this->term = Term::factory()->create(['code' => '20271']);
        $this->c2 = Campus::factory()->create(['code' => 'C2']);
        Department::factory()->create(['code' => 'D1']);
        Department::factory()->create(['code' => 'D2']);
        Department::factory()->create(['code' => 'D3']);
        Department::factory()->inactive()->create(['code' => 'D8']);
        Department::factory()->create(['code' => 'D9'])->delete();
    });

    it('M4: abrir Presencial crea la carga en borrador de cada departamento activo en cada campus presencial y, desde CV9, ninguna revisión', function () {
        Campus::factory()->create(['code' => 'C1']);

        expect(openTerm($this->term, $this->presencial))->toBe(['6 cargas académicas nuevas']);

        $loads = AcademicLoad::query()->with(['department', 'campus'])->get();

        expect($loads->map(fn (AcademicLoad $load): string => "{$load->campus->code}-{$load->department->code}")->sort()->values()->all())
            ->toBe(['C1-D1', 'C1-D2', 'C1-D3', 'C2-D1', 'C2-D2', 'C2-D3'])
            ->and($loads->every(fn (AcademicLoad $load): bool => $load->modality_id === $this->presencial->id && $load->status === LoadStatus::Draft))->toBeTrue()
            ->and(CatalogReview::count())->toBe(0)
            ->and(Activity::sole()->properties['report'])->toBe(['6 cargas académicas nuevas']);
    });

    it('foco 5: abrir dos veces no duplica: una apertura y una carga por departamento y campus', function () {
        Campus::factory()->create(['code' => 'C1']);
        openTerm($this->term, $this->presencial);

        expect(openTerm($this->term, $this->presencial))->toBe([])
            ->and(TermOpening::count())->toBe(1)
            ->and(AcademicLoad::count())->toBe(6)
            ->and(AcademicLoad::query()->select('campus_id', 'department_id')->distinct()->get())->toHaveCount(6)
            ->and(CatalogReview::count())->toBe(0);
    });

    it('completar la apertura crea solo lo del departamento nuevo', function () {
        openTerm($this->term, $this->presencial);
        Department::factory()->create(['code' => 'D4']);

        expect(openTerm($this->term, $this->presencial))->toBe(['1 carga académica nueva']);
    });

    it('no crea cargas en los campus de otra modalidad ni en los inactivos', function () {
        $other = Modality::factory()->create();
        Campus::factory()->create(['code' => 'C8', 'modality_id' => $other->id]);
        Campus::factory()->inactive()->create(['code' => 'C7']);

        openTerm($this->term, $this->presencial);

        expect(AcademicLoad::query()->with('campus')->get()->pluck('campus.code')->unique()->values()->all())->toBe(['C2']);
    });

    it('una modalidad sin campus activos se abre sin cargas que crear', function () {
        expect(openTerm($this->term, Modality::factory()->create()))->toBe([])
            ->and(TermOpening::count())->toBe(1)
            ->and(AcademicLoad::count())->toBe(0);
    });

    it('es todo o nada: si un listener falla después, no quedan apertura, cargas ni revisiones', function () {
        $createdBeforeFailing = null;
        Event::listen(TermOpened::class, function () use (&$createdBeforeFailing): void {
            $createdBeforeFailing = AcademicLoad::count();

            throw new RuntimeException('Falla un módulo.');
        });

        expect(fn () => openTerm($this->term, $this->presencial))->toThrow(RuntimeException::class);

        expect($createdBeforeFailing)->toBe(3)
            ->and(TermOpening::count())->toBe(0)
            ->and(AcademicLoad::count())->toBe(0)
            ->and(Activity::count())->toBe(0);
    });

    it('una modalidad inactiva no crea cargas', function () {
        expect(fn () => openTerm($this->term, Modality::factory()->inactive()->create()))->toThrow(ValidationException::class);

        expect(AcademicLoad::count())->toBe(0);
    });
});
