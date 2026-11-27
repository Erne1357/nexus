<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Actions\AppointCareerCoordinator;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| MS19 (supuesto por confirmar): nombrar a un coordinador sobre varias carreras crea un nombramiento por carrera, con
| la misma modalidad y el mismo campus (o ninguno), en una sola operación; terminar uno no toca los otros. M5
| (confirmado el 12 de noviembre de 2026): el coordinador va sobre carreras y una modalidad, con el campus opcional.
*/

beforeEach(function () {
    $this->travelTo('2026-11-12 10:00:00');

    $this->actingAs(User::factory()->admin()->create(['name' => 'Ada Admin']));
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->careers = collect(['ISC', 'IGE', 'IND'])->map(function (string $code): Career {
        $career = Career::factory()->create(['code' => $code]);
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $this->c2->id]);

        return $career;
    });
    $this->activityBase = (int) Activity::max('id');
});

function appointCoordinator(User $user, ?Campus $campus, array $careerIds, string $modality = 'PRES'): array
{
    return app(AppointCareerCoordinator::class)->handle($user, modalityOf($modality), $campus, $careerIds, CarbonImmutable::parse('2026-11-12'));
}

it('MS19: tres carreras crean tres nombramientos con la misma modalidad y el mismo campus', function () {
    $assignments = appointCoordinator($this->rosa, $this->c2, $this->careers->pluck('id')->all());

    expect($assignments)->toHaveCount(3)
        ->and(RoleAssignment::query()->where('user_id', $this->rosa->id)->get())
        ->each(fn ($assignment) => $assignment
            ->role->toBe(Role::Coordinador)
            ->modality_id->toBe(modalityOf('PRES')->id)
            ->campus_id->toBe($this->c2->id))
        ->and(RoleAssignment::query()->where('user_id', $this->rosa->id)->pluck('career_id')->all())->toEqualCanonicalizing($this->careers->pluck('id')->all())
        ->and(activitySince()->where('event', 'created')->count())->toBe(3);
});

it('MS19: sin campus, los nombramientos son de todos los campus de la modalidad', function () {
    appointCoordinator($this->rosa, null, $this->careers->pluck('id')->all());

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->pluck('campus_id')->unique()->all())->toBe([null]);
});

it('MS19: terminar uno de los nombramientos no toca los otros', function () {
    [$first, $second, $third] = appointCoordinator($this->rosa, $this->c2, $this->careers->pluck('id')->all());

    app(EndRoleAssignment::class)->handle($second, CarbonImmutable::parse('2026-12-31'));

    expect($first->fresh()->valid_to)->toBeNull()
        ->and($second->fresh()->valid_to->toDateString())->toBe('2026-12-31')
        ->and($third->fresh()->valid_to)->toBeNull();
});

it('MS19: si una carrera no sirve, no se nombra ninguna', function () {
    $elsewhere = Career::factory()->create(['code' => 'IEL']);

    expect(fn () => appointCoordinator($this->rosa, $this->c2, [...$this->careers->pluck('id')->all(), $elsewhere->id]))
        ->toThrow(ValidationException::class, 'Esa carrera no se ofrece en C2.');

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse()
        ->and(activitySince()->count())->toBe(0);
});

it('MS19: pide al menos una carrera, no repite una carrera dos veces y rechaza una modalidad inactiva', function () {
    expect(fn () => appointCoordinator($this->rosa, $this->c2, []))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toBe(['career' => ['Elige al menos una carrera.']]));

    expect(appointCoordinator($this->rosa, $this->c2, [$this->careers[0]->id, $this->careers[0]->id]))->toHaveCount(1);

    expect(fn () => appointCoordinator(User::factory()->create(), $this->c2, [$this->careers[1]->id], 'EAD'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toBe(['modality' => ['La modalidad EAD está inactiva.']]));
});
