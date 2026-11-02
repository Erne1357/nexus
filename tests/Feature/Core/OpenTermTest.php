<?php

use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
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
        ->and($entry->properties->toArray())->toBe(['campus_id' => $campus->id, 'report' => ['3 cosas nuevas']]);
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
        ->and($entry->properties->toArray())->toBe(['campus_id' => $campus->id, 'report' => []]);
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
