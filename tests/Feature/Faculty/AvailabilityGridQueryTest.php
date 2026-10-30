<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\AvailabilityGridQuery;

/*
| La cuadrícula día × hora de la disponibilidad de un docente en un periodo (S12, supuesto por confirmar): los
| días, el horario y el bloque salen del perfil del campus activo o, sin él, del perfil presencial por omisión.
| Cada celda dice su tipo; el resumen cuenta las horas disponibles, preferidas y no disponibles contra las horas
| base del periodo. «Copiar del periodo anterior» toma el semestre anterior, sin contar verano (confirmado el
| 30 de octubre de 2026).
*/

beforeEach(function () {
    $this->teacher = Teacher::factory()->create();
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->grids = app(AvailabilityGridQuery::class);
});

function availabilityRow(Teacher $teacher, Term $term, int $day, string $from, string $to, AvailabilityKind $kind = AvailabilityKind::Unavailable): TeacherAvailability
{
    return TeacherAvailability::factory()->for($teacher)->for($term)->create(['day' => $day, 'starts_at' => $from, 'ends_at' => $to, 'kind' => $kind]);
}

/**
 * Los tipos de la cuadrícula como texto: un renglón por día, `.` disponible, `u` no disponible y `p` preferido.
 *
 * @return list<string>
 */
function gridKinds(array $grid): array
{
    return array_map(fn (array $cells): string => implode('', array_map(fn (array $cell): string => match ($cell['kind']) {
        AvailabilityKind::Unavailable => 'u',
        AvailabilityKind::Preferred => 'p',
        null => '.',
    }, $cells)), $grid['cells']);
}

it('arma la cuadrícula del perfil presencial: lunes a viernes, de 7:00 a 21:00 en bloques de una hora', function () {
    $grid = $this->grids->for($this->teacher, $this->term, CampusProfile::fromArray([]));

    expect(array_column($grid['days'], 'name'))->toBe(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'])
        ->and(array_column($grid['days'], 'short'))->toBe(['Lun', 'Mar', 'Mié', 'Jue', 'Vie'])
        ->and(array_column($grid['blocks'], 'starts_at'))->toHaveCount(14)
        ->and($grid['blocks'][0])->toBe(['starts_at' => '07:00', 'ends_at' => '08:00', 'label' => '7:00', 'end_label' => '8:00'])
        ->and(end($grid['blocks'])['ends_at'])->toBe('21:00')
        ->and($grid['blockMinutes'])->toBe(60)
        ->and($grid['schedule'])->toBe('lunes a viernes, 7:00 a 21:00')
        ->and(gridKinds($grid))->toBe(array_fill(0, 5, '..............'));
});

it('usa los días, el horario y el bloque del perfil que recibe', function () {
    $profile = CampusProfile::fromArray(['days' => [1, 2, 3, 4, 5, 6], 'day_start' => '08:00', 'day_end' => '14:00', 'block_minutes' => 30]);

    $grid = $this->grids->for($this->teacher, $this->term, $profile);

    expect(array_column($grid['days'], 'day'))->toBe([1, 2, 3, 4, 5, 6])
        ->and(end($grid['days'])['name'])->toBe('Sábado')
        ->and($grid['blocks'])->toHaveCount(12)
        ->and($grid['blocks'][1]['starts_at'])->toBe('08:30')
        ->and($grid['blockMinutes'])->toBe(30);
});

it('marca cada celda con su tipo y el texto del rango en la segunda celda', function () {
    availabilityRow($this->teacher, $this->term, 1, '07:00', '08:00');
    availabilityRow($this->teacher, $this->term, 1, '09:00', '13:00', AvailabilityKind::Preferred);
    availabilityRow($this->teacher, $this->term, 5, '14:00', '21:00');

    $grid = $this->grids->for($this->teacher, $this->term, CampusProfile::fromArray([]));

    expect(gridKinds($grid))->toBe([
        'u.pppp........',
        '..............',
        '..............',
        '..............',
        '.......uuuuuuu',
    ])
        ->and($grid['cells'][0][2])->toMatchArray(['text' => 'Preferido', 'mono' => false, 'continues' => false])
        ->and($grid['cells'][0][3])->toMatchArray(['text' => '9:00–13:00', 'mono' => true, 'continues' => true])
        ->and($grid['cells'][0][4])->toMatchArray(['text' => null, 'continues' => true])
        ->and($grid['cells'][0][0])->toMatchArray(['text' => 'No disponible', 'continues' => false])
        ->and($grid['cells'][0][1])->toMatchArray(['kind' => null, 'text' => null, 'continues' => false]);
});

it('da los rangos de cada día y el resumen de horas contra las horas base del periodo', function () {
    availabilityRow($this->teacher, $this->term, 1, '07:00', '08:00');
    availabilityRow($this->teacher, $this->term, 1, '09:00', '13:00', AvailabilityKind::Preferred);
    availabilityRow($this->teacher, $this->term, 5, '14:00', '21:00');
    TeacherPosition::factory()->for($this->teacher)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->teacher)->asignatura(10)->create();
    TeacherPosition::factory()->for($this->teacher)->medioTiempo()->create(['valid_to' => '2026-12-31']);

    $grid = $this->grids->for($this->teacher, $this->term, CampusProfile::fromArray([]));

    expect($grid['ranges'][0])->toBe([
        ['starts_at' => '07:00', 'ends_at' => '08:00', 'kind' => AvailabilityKind::Unavailable, 'label' => '7:00–8:00'],
        ['starts_at' => '09:00', 'ends_at' => '13:00', 'kind' => AvailabilityKind::Preferred, 'label' => '9:00–13:00'],
    ])
        ->and($grid['ranges'][1])->toBe([])
        ->and(array_column($grid['days'], 'available'))->toBe([13, 14, 14, 14, 7])
        ->and($grid['summary'])->toBe(['available' => 62, 'preferred' => 4, 'unavailable' => 8, 'total' => 70, 'baseHours' => 50, 'positions' => 2]);
});

it('cuenta medias horas con bloques de 30 minutos', function () {
    availabilityRow($this->teacher, $this->term, 1, '07:00', '07:30');

    $grid = $this->grids->for($this->teacher, $this->term, CampusProfile::fromArray(['block_minutes' => 30]));

    expect($grid['summary'])->toMatchArray(['available' => 69.5, 'unavailable' => 0.5, 'total' => 70])
        ->and($grid['days'][0]['available'])->toBe(13.5);
});

it('un bloque que solo en parte cae en un rango toma su tipo, y el no disponible gana al preferido', function () {
    availabilityRow($this->teacher, $this->term, 1, '07:30', '08:15', AvailabilityKind::Preferred);
    availabilityRow($this->teacher, $this->term, 1, '08:15', '08:30');

    $grid = $this->grids->for($this->teacher, $this->term, CampusProfile::fromArray([]));

    expect(gridKinds($grid)[0])->toBe('pu............');
});

it('no cuenta lo borrado, lo de otro periodo ni lo que queda fuera del perfil', function () {
    availabilityRow($this->teacher, $this->term, 1, '07:00', '08:00')->delete();
    availabilityRow($this->teacher, Term::factory()->create(), 2, '07:00', '08:00');
    availabilityRow($this->teacher, $this->term, 6, '08:00', '10:00');
    availabilityRow($this->teacher, $this->term, 3, '21:00', '22:00');

    $grid = $this->grids->for($this->teacher, $this->term, CampusProfile::fromArray([]));

    expect(gridKinds($grid))->toBe(array_fill(0, 5, '..............'))
        ->and($grid['summary']['unavailable'])->toBe(0);
});

describe('perfil (S12)', function () {
    it('es el del campus activo', function () {
        $ead = Campus::factory()->create(['settings' => ['days' => [1, 2, 3, 4, 5, 6], 'day_start' => '08:00', 'day_end' => '14:00']]);

        expect($this->grids->profile($ead)->days)->toBe([1, 2, 3, 4, 5, 6])
            ->and($this->grids->profile($ead)->dayStart)->toBe('08:00');
    });

    it('sin campus activo es el presencial por omisión', function () {
        expect($this->grids->profile(null)->toArray())->toBe(CampusProfile::DEFAULTS);
    });
});

describe('periodo anterior', function () {
    it('es el semestre anterior, sin contar verano', function () {
        $agoDic = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-24', 'ends_on' => '2026-12-11']);
        Term::factory()->create(['code' => '20262', 'name' => 'Verano 2026', 'starts_on' => '2026-06-22', 'ends_on' => '2026-08-07']);
        Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026', 'starts_on' => '2026-01-26', 'ends_on' => '2026-06-12']);
        $verano = Term::factory()->create(['code' => '20272', 'name' => 'Verano 2027', 'starts_on' => '2027-06-21', 'ends_on' => '2027-08-06']);

        expect($this->grids->previousTerm($this->term)->is($agoDic))->toBeTrue()
            ->and($this->grids->previousTerm($verano)->is($this->term))->toBeTrue();
    });

    it('no hay si es el primer periodo', function () {
        Term::factory()->create(['code' => '20262', 'name' => 'Verano 2026', 'starts_on' => '2026-06-22', 'ends_on' => '2026-08-07']);

        expect($this->grids->previousTerm($this->term))->toBeNull();
    });

    it('dice si el docente tiene disponibilidad capturada en un periodo', function () {
        $agoDic = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-24', 'ends_on' => '2026-12-11']);
        availabilityRow($this->teacher, $this->term, 1, '07:00', '08:00');
        availabilityRow($this->teacher, $agoDic, 1, '07:00', '08:00')->delete();

        expect($this->grids->hasAvailability($this->teacher, $this->term, CampusProfile::fromArray([])))->toBeTrue()
            ->and($this->grids->hasAvailability($this->teacher, $agoDic, CampusProfile::fromArray([])))->toBeFalse();
    });

    it('solo cuenta la disponibilidad que cae en los días y el horario del perfil', function () {
        availabilityRow($this->teacher, $this->term, 6, '08:00', '10:00');
        availabilityRow($this->teacher, $this->term, 1, '21:00', '22:00');
        availabilityRow($this->teacher, $this->term, 2, '05:00', '07:00');

        expect($this->grids->hasAvailability($this->teacher, $this->term, CampusProfile::fromArray([])))->toBeFalse()
            ->and($this->grids->hasAvailability($this->teacher, $this->term, CampusProfile::fromArray(['days' => [1, 2, 3, 4, 5, 6]])))->toBeTrue()
            ->and($this->grids->hasAvailability($this->teacher, $this->term, CampusProfile::fromArray(['day_end' => '22:00'])))->toBeTrue();
    });
});
