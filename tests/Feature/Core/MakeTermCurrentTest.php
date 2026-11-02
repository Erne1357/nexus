<?php

use App\Modules\Core\Actions\MakeTermCurrent;
use App\Modules\Core\Events\CurrentTermChanged;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
| MakeTermCurrent: un solo periodo actual, con el índice único parcial como último muro.
*/

it('marca el periodo y quita la marca al anterior en la misma transacción', function () {
    $old = Term::factory()->current()->create(['code' => '20263']);
    $new = Term::factory()->create(['code' => '20271']);

    app(MakeTermCurrent::class)->handle($new);

    expect($new->fresh()->is_current)->toBeTrue()
        ->and($old->fresh()->is_current)->toBeFalse()
        ->and(Term::current()->count())->toBe(1);
});

it('funciona sin periodo anterior y cualquier periodo puede volver a ser el actual', function () {
    $first = Term::factory()->create();
    $second = Term::factory()->create();

    app(MakeTermCurrent::class)->handle($first);
    app(MakeTermCurrent::class)->handle($second);
    app(MakeTermCurrent::class)->handle($first);

    expect(Term::current()->sole()->is($first))->toBeTrue();
});

it('es idempotente: si ya era el actual no escribe, no registra ni avisa', function () {
    Event::fake([CurrentTermChanged::class]);
    $term = Term::factory()->current()->create();

    app(MakeTermCurrent::class)->handle($term);

    expect(Activity::count())->toBe(0);
    Event::assertNotDispatched(CurrentTermChanged::class);
});

it('registra en la bitácora quién lo marcó y cuál era el anterior', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Term::factory()->current()->create(['code' => '20263']);
    $new = Term::factory()->create(['code' => '20271']);

    app(MakeTermCurrent::class)->handle($new);

    $entry = Activity::sole();

    expect($entry->description)->toBe('Marcó el periodo 20271 como actual, en lugar del 20263')
        ->and($entry->subject->is($new))->toBeTrue()
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['is_current' => true],
            'old' => ['is_current' => false, 'previous_current' => '20263'],
        ]);
});

it('avisa con CurrentTermChanged con el anterior, solo después del commit', function () {
    $heard = [];
    Event::listen(CurrentTermChanged::class, function (CurrentTermChanged $event) use (&$heard): void {
        $heard[] = [$event->term->code, $event->previous?->code];
    });
    Term::factory()->current()->create(['code' => '20263']);
    $new = Term::factory()->create(['code' => '20271']);

    try {
        DB::transaction(function () use ($new): void {
            app(MakeTermCurrent::class)->handle($new);

            throw new RuntimeException('Falla después.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([])
        ->and(Term::current()->sole()->code)->toBe('20263');

    app(MakeTermCurrent::class)->handle($new);

    expect($heard)->toBe([['20271', '20263']]);
});

it('la base rechaza dos periodos actuales a la vez (terms_single_current_unique)', function () {
    Term::factory()->current()->create();

    expect(fn () => Term::factory()->current()->create())->toThrow(UniqueConstraintViolationException::class);
});
