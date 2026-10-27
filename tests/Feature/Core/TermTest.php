<?php

use App\Modules\Core\Models\Term;
use Illuminate\Database\UniqueConstraintViolationException;

it('no permite dos periodos con el mismo código', function () {
    Term::factory()->create(['code' => '2027-1']);

    expect(fn () => Term::factory()->create(['code' => '2027-1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('solo permite un periodo actual', function () {
    Term::factory()->current()->create();

    expect(fn () => Term::factory()->current()->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('permite varios periodos que no son el actual', function () {
    Term::factory()->count(2)->create();

    expect(Term::count())->toBe(2);
});

it('encuentra el periodo actual con su scope', function () {
    Term::factory()->create();
    $current = Term::factory()->current()->create();

    expect(Term::current()->sole()->is($current))->toBeTrue();
});
