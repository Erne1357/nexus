<?php

use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Actions\SetTeacherAvailability;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Guardar la disponibilidad de un docente en un periodo (S12, supuesto por confirmar): las celdas marcadas se
| revisan contra el perfil de la cuadrícula (días, horario y bloque) y se guardan como rangos unidos; las filas
| anteriores de esos días y ese horario se quitan con borrado lógico. Lo de otros días u horas del mismo periodo
| (capturado con el perfil de otro campus) y lo de otros periodos no se toca. Guardar lo mismo no escribe; un
| cambio deja una entrada de bitácora con el antes y el después y avisa tras el commit.
*/

beforeEach(function () {
    $this->teacher = Teacher::factory()->create(['employee_number' => '1000001']);
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->profile = CampusProfile::fromArray([]);
    $this->activityBase = (int) Activity::max('id');
});

/**
 * @param  list<array{0: int, 1: string, 2?: string}>  $cells  día, hora y tipo (`unavailable` si falta)
 */
function setAvailability(Teacher $teacher, Term $term, array $cells, ?CampusProfile $profile = null): void
{
    app(SetTeacherAvailability::class)->handle($teacher, $term, array_map(fn (array $cell): array => [
        'day' => $cell[0],
        'starts_at' => $cell[1],
        'kind' => $cell[2] ?? 'unavailable',
    ], $cells), $profile ?? CampusProfile::fromArray([]));
}

/**
 * Las filas sin borrar del docente en el periodo, por día y hora, como `día hh:mm-hh:mm tipo`.
 *
 * @return list<string>
 */
function availabilityRows(Teacher $teacher, Term $term): array
{
    return TeacherAvailability::query()->where('teacher_id', $teacher->id)->where('term_id', $term->id)
        ->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (TeacherAvailability $row): string => $row->day.' '.substr($row->starts_at, 0, 5).'-'.substr($row->ends_at, 0, 5).' '.$row->kind->value)
        ->all();
}

function availabilityErrorsOf(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar con un error de validación.');
}

it('guarda las celdas como rangos unidos por día y tipo', function () {
    setAvailability($this->teacher, $this->term, [
        [1, '08:00'], [1, '07:00'], [1, '09:00', 'preferred'], [1, '10:00', 'preferred'], [3, '16:00'],
    ]);

    expect(availabilityRows($this->teacher, $this->term))->toBe([
        '1 07:00-09:00 unavailable',
        '1 09:00-11:00 preferred',
        '3 16:00-17:00 unavailable',
    ]);
});

it('acepta el tipo como enum o como su valor', function () {
    app(SetTeacherAvailability::class)->handle($this->teacher, $this->term, [
        ['day' => 2, 'starts_at' => '07:00', 'kind' => AvailabilityKind::Preferred],
    ], $this->profile);

    expect(availabilityRows($this->teacher, $this->term))->toBe(['2 07:00-08:00 preferred']);
});

it('reemplaza las filas anteriores con borrado lógico', function () {
    setAvailability($this->teacher, $this->term, [[1, '07:00'], [1, '08:00']]);
    $old = TeacherAvailability::query()->sole();

    setAvailability($this->teacher, $this->term, [[1, '07:00', 'preferred']]);

    expect(availabilityRows($this->teacher, $this->term))->toBe(['1 07:00-08:00 preferred'])
        ->and($old->fresh()->trashed())->toBeTrue()
        ->and(TeacherAvailability::withTrashed()->count())->toBe(2);
});

it('sin celdas quita todo lo del horario', function () {
    setAvailability($this->teacher, $this->term, [[1, '07:00'], [2, '07:00', 'preferred']]);
    setAvailability($this->teacher, $this->term, []);

    expect(availabilityRows($this->teacher, $this->term))->toBe([])
        ->and(TeacherAvailability::onlyTrashed()->count())->toBe(2);
});

it('guardar dos veces lo mismo no escribe, no deja bitácora ni avisa', function () {
    $cells = [[1, '07:00'], [1, '08:00'], [4, '12:00', 'preferred']];
    setAvailability($this->teacher, $this->term, $cells);
    $ids = TeacherAvailability::query()->pluck('id')->all();
    $this->activityBase = (int) Activity::max('id');
    Event::fake([AvailabilityChanged::class]);

    setAvailability($this->teacher, $this->term, array_reverse($cells));

    expect(TeacherAvailability::withTrashed()->pluck('id')->all())->toEqualCanonicalizing($ids)
        ->and(activitySince()->count())->toBe(0);
    Event::assertNotDispatched(AvailabilityChanged::class);
});

it('cambiar un rango solo toca las filas que cambian', function () {
    setAvailability($this->teacher, $this->term, [[1, '07:00'], [2, '07:00']]);
    $tuesday = TeacherAvailability::query()->where('day', 2)->sole();

    setAvailability($this->teacher, $this->term, [[1, '07:00'], [1, '08:00'], [2, '07:00']]);

    expect(availabilityRows($this->teacher, $this->term))->toBe(['1 07:00-09:00 unavailable', '2 07:00-08:00 unavailable'])
        ->and($tuesday->fresh()->trashed())->toBeFalse();
});

it('no toca la disponibilidad del docente en otro periodo ni la de otro docente', function () {
    $other = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-24', 'ends_on' => '2026-12-11']);
    $otherTeacher = Teacher::factory()->create();
    setAvailability($this->teacher, $other, [[1, '07:00']]);
    setAvailability($otherTeacher, $this->term, [[1, '07:00']]);

    setAvailability($this->teacher, $this->term, [[1, '07:00', 'preferred']]);
    setAvailability($this->teacher, $this->term, []);

    expect(availabilityRows($this->teacher, $other))->toBe(['1 07:00-08:00 unavailable'])
        ->and(availabilityRows($otherTeacher, $this->term))->toBe(['1 07:00-08:00 unavailable']);
});

it('conserva lo de los días y horas que no están en el perfil (S12)', function () {
    $ead = CampusProfile::fromArray(['days' => [1, 2, 3, 4, 5, 6], 'day_start' => '07:00', 'day_end' => '22:00']);
    setAvailability($this->teacher, $this->term, [[6, '08:00'], [6, '09:00'], [1, '20:00'], [1, '21:00'], [1, '07:00']], $ead);

    setAvailability($this->teacher, $this->term, [[1, '20:00', 'preferred']]);

    expect(availabilityRows($this->teacher, $this->term))->toBe([
        '1 20:00-21:00 preferred',
        '1 21:00-22:00 unavailable',
        '6 08:00-10:00 unavailable',
    ]);
});

it('vuelve a unir lo que se conserva fuera del horario con lo que no cambió, así guardar lo mismo no escribe', function () {
    $ead = CampusProfile::fromArray(['day_start' => '07:00', 'day_end' => '22:00']);
    setAvailability($this->teacher, $this->term, [[1, '20:00'], [1, '21:00']], $ead);
    $row = TeacherAvailability::query()->sole();

    setAvailability($this->teacher, $this->term, [[1, '20:00']]);

    expect(availabilityRows($this->teacher, $this->term))->toBe(['1 20:00-22:00 unavailable'])
        ->and($row->fresh()->trashed())->toBeFalse();
});

it('deja una entrada de bitácora con el antes y el después y avisa tras el commit', function () {
    setAvailability($this->teacher, $this->term, [[1, '07:00']]);
    $this->activityBase = (int) Activity::max('id');
    Event::fake([AvailabilityChanged::class]);

    setAvailability($this->teacher, $this->term, [[1, '07:00'], [1, '08:00', 'preferred']]);
    $activity = activitySince()->sole();

    expect($activity->subject_id)->toBe($this->teacher->id)
        ->and($activity->event)->toBe('updated')
        ->and($activity->properties['term_id'])->toBe($this->term->id)
        ->and($activity->attribute_changes['old']['availability'])->toBe([
            ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'kind' => 'unavailable'],
        ])
        ->and($activity->attribute_changes['attributes']['availability'])->toBe([
            ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'kind' => 'unavailable'],
            ['day' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00', 'kind' => 'preferred'],
        ]);
    Event::assertDispatched(AvailabilityChanged::class, fn (AvailabilityChanged $event): bool => $event->teacher->is($this->teacher) && $event->term->is($this->term));
});

describe('rechaza', function () {
    it('una celda de un día fuera del perfil', function () {
        $errors = availabilityErrorsOf(fn () => setAvailability($this->teacher, $this->term, [[6, '08:00']]));

        expect($errors['cells'][0])->toContain('sábado');
    });

    it('una celda antes o después del horario del perfil, o que no empieza en un bloque', function (string $time) {
        $errors = availabilityErrorsOf(fn () => setAvailability($this->teacher, $this->term, [[1, $time]]));

        expect($errors['cells'][0])->toContain('7:00 a 21:00');
    })->with(['06:00', '21:00', '20:30', '07:15']);

    it('una hora que no es hora, un tipo que no existe o un día que no es número', function (array $cell) {
        $errors = availabilityErrorsOf(fn () => app(SetTeacherAvailability::class)->handle($this->teacher, $this->term, [$cell], $this->profile));

        expect($errors)->toHaveKey('cells');
    })->with([
        'hora' => [['day' => 1, 'starts_at' => '7am', 'kind' => 'unavailable']],
        'tipo' => [['day' => 1, 'starts_at' => '07:00', 'kind' => 'available']],
        'día' => [['day' => 'lunes', 'starts_at' => '07:00', 'kind' => 'unavailable']],
        'incompleta' => [['day' => 1, 'kind' => 'unavailable']],
    ]);

    it('una celda repetida', function () {
        $errors = availabilityErrorsOf(fn () => setAvailability($this->teacher, $this->term, [[1, '07:00'], [1, '07:00', 'preferred']]));

        expect($errors['cells'][0])->toContain('dos veces');
    });

    it('sin escribir nada si una sola celda es inválida', function () {
        setAvailability($this->teacher, $this->term, [[1, '07:00']]);

        availabilityErrorsOf(fn () => setAvailability($this->teacher, $this->term, [[2, '07:00'], [6, '07:00']]));

        expect(availabilityRows($this->teacher, $this->term))->toBe(['1 07:00-08:00 unavailable']);
    });
});

it('con bloques de 30 minutos guarda medias horas', function () {
    $profile = CampusProfile::fromArray(['block_minutes' => 30]);

    setAvailability($this->teacher, $this->term, [[1, '07:00'], [1, '07:30'], [1, '09:30', 'preferred']], $profile);

    expect(availabilityRows($this->teacher, $this->term))->toBe(['1 07:00-08:00 unavailable', '1 09:30-10:00 preferred']);
});
