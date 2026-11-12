<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/*
| CreateLoadsOnTermOpened: al abrir un periodo en una modalidad (M4) crea las cargas que falten (CreateTermLoads; MS15,
| supuesto por confirmar) y lo dice en el reporte de la apertura.
*/

beforeEach(function () {
    $this->presencial = modalityOf('PRES');
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

it('M4: crea las cargas de la modalidad que se abre y reporta cuántas, en plural', function () {
    Department::factory()->count(3)->create();
    Campus::factory()->create(['code' => 'C1']);
    $event = new TermOpened(Term::factory()->create(), $this->presencial);

    event($event);

    expect($event->lines())->toContain('6 cargas académicas nuevas')
        ->and(AcademicLoad::query()->pluck('modality_id')->unique()->all())->toBe([$this->presencial->id]);
});

it('reporta «1 carga académica nueva» en singular', function () {
    Department::factory()->create();
    $event = new TermOpened(Term::factory()->create(), $this->presencial);

    event($event);

    expect($event->lines())->toContain('1 carga académica nueva');
});

it('no reporta nada si no faltaba ninguna', function () {
    $term = Term::factory()->create();
    Department::factory()->create();
    event(new TermOpened($term, $this->presencial));
    $event = new TermOpened($term, $this->presencial);

    event($event);

    expect(array_values(array_filter($event->lines(), fn (string $line): bool => str_contains($line, 'carga'))))->toBe([])
        ->and(AcademicLoad::count())->toBe(1);
});
