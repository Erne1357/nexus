<?php

use App\Modules\Core\Actions\CreateTerm;
use App\Modules\Core\Events\TermCreated;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CreateTerm da de alta un periodo con las reglas de TermRules; nunca lo marca como actual.
*/

function newTerm(array $overrides = []): Term
{
    return app(CreateTerm::class)->handle([
        'code' => '20271',
        'name' => 'Ene-Jun 2027',
        'starts_on' => '2027-01-18',
        'ends_on' => '2027-06-11',
        ...$overrides,
    ]);
}

it('da de alta el periodo y nunca lo marca como actual', function () {
    $term = newTerm(['is_current' => true])->fresh();

    expect($term)->code->toBe('20271')
        ->name->toBe('Ene-Jun 2027')
        ->is_current->toBeFalse()
        ->and($term->starts_on->toDateString())->toBe('2027-01-18')
        ->and($term->ends_on->toDateString())->toBe('2027-06-11');
});

it('rechaza un código repetido o mal formado y fechas invertidas, sin crear nada', function () {
    Term::factory()->create(['code' => '20271']);

    expect(fn () => newTerm())->toThrow(ValidationException::class, 'Ya existe un periodo con el código 20271.')
        ->and(fn () => newTerm(['code' => '20274']))->toThrow(ValidationException::class)
        ->and(fn () => newTerm(['code' => '20272', 'ends_on' => '2027-01-01']))->toThrow(ValidationException::class);

    expect(Term::count())->toBe(1);
});

it('si otro guarda el mismo código entre la validación y la escritura, responde con el error de código', function () {
    Term::creating(fn () => DB::table('terms')->insert(['code' => '20271', 'name' => 'Otra sesión', 'starts_on' => '2027-01-01', 'ends_on' => '2027-02-01']));

    expect(fn () => newTerm())->toThrow(ValidationException::class, 'Ya existe un periodo con el código 20271.');
});

it('registra en la bitácora quién lo dio de alta y con qué datos', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $term = newTerm();
    $entry = Activity::sole();

    expect($entry)->event->toBe('created')->description->toBe('Dio de alta el periodo 20271')
        ->and($entry->subject->is($term))->toBeTrue()
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe(['attributes' => [
            'code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11', 'is_current' => false,
        ]]);
});

it('avisa con TermCreated solo después del commit', function () {
    $heard = [];
    Event::listen(TermCreated::class, function (TermCreated $event) use (&$heard): void {
        $heard[] = $event->term->code;
    });

    try {
        DB::transaction(function (): void {
            newTerm();

            throw new RuntimeException('Falla después.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([]);

    newTerm();

    expect($heard)->toBe(['20271']);
});
