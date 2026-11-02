<?php

use App\Modules\Core\Actions\UpdateTerm;
use App\Modules\Core\Events\TermUpdated;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateTerm cambia código, nombre y fechas, solo lo que recibe, y nunca is_current.
*/

it('cambia solo las claves que recibe y deja en la bitácora el valor anterior y el nuevo', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $term = Term::factory()->create(['code' => '20271', 'name' => 'Viejo', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);

    app(UpdateTerm::class)->handle($term, ['name' => 'Ene-Jun 2027', 'ends_on' => '2027-06-20']);

    $entry = Activity::sole();

    expect($term->fresh())->name->toBe('Ene-Jun 2027')->code->toBe('20271')
        ->and($term->fresh()->ends_on->toDateString())->toBe('2027-06-20')
        ->and($entry->event)->toBe('updated')
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['name' => 'Ene-Jun 2027', 'ends_on' => '2027-06-20'],
            'old' => ['name' => 'Viejo', 'ends_on' => '2027-06-11'],
        ]);
});

it('no toca is_current aunque se lo pasen', function () {
    $term = Term::factory()->create();

    app(UpdateTerm::class)->handle($term, ['is_current' => true, 'name' => 'Otro']);

    expect($term->fresh()->is_current)->toBeFalse();
});

it('si nada cambió no escribe, no registra ni avisa', function () {
    Event::fake([TermUpdated::class]);
    $term = Term::factory()->create(['code' => '20271', 'name' => 'Igual']);

    app(UpdateTerm::class)->handle($term, ['code' => '20271', 'name' => 'Igual', 'starts_on' => $term->starts_on->toDateString()]);

    expect(Activity::count())->toBe(0);
    Event::assertNotDispatched(TermUpdated::class);
});

it('avisa con TermUpdated con las columnas que cambiaron', function () {
    Event::fake([TermUpdated::class]);
    $term = Term::factory()->create();

    app(UpdateTerm::class)->handle($term, ['name' => 'Nuevo']);

    Event::assertDispatched(TermUpdated::class, fn (TermUpdated $event) => $event->changed === ['name']);
});

it('rechaza el código de otro periodo, conserva el propio y valida las fechas contra las guardadas', function () {
    Term::factory()->create(['code' => '20272']);
    $term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);

    expect(fn () => app(UpdateTerm::class)->handle($term, ['code' => '20272']))
        ->toThrow(ValidationException::class, 'Ya existe un periodo con el código 20272.')
        ->and(fn () => app(UpdateTerm::class)->handle($term, ['ends_on' => '2027-01-01']))
        ->toThrow(ValidationException::class, 'La fecha de fin no puede ser anterior');

    app(UpdateTerm::class)->handle($term, ['code' => '20271', 'name' => 'Bien']);

    expect($term->fresh()->name)->toBe('Bien');
});

it('usa el valor confirmado como anterior aunque el modelo llegue viejo', function () {
    $term = Term::factory()->create(['name' => 'A']);
    $stale = Term::find($term->id);
    Term::whereKey($term->id)->update(['name' => 'B']);

    app(UpdateTerm::class)->handle($stale, ['name' => 'C']);

    expect(Activity::sole()->attribute_changes['old'])->toBe(['name' => 'B']);
});
