<?php

use App\Modules\Academic\Actions\CreateSpecialty;
use App\Modules\Academic\Actions\RemoveSpecialty;
use App\Modules\Academic\Actions\UpdateSpecialty;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\SpecialtyChanged;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de las especialidades de un plan (reglas-de-negocio.md, "Especialidades"). Crear exige una clave única
| dentro del plan, también contra las quitadas, y un nombre; renombrar y desactivar se permiten siempre; quitar
| (borrado lógico) solo una especialidad sin materias en la retícula (confirmado el 29 de octubre de 2026). Ninguna cambia el
| avance de los alumnos: no avisan CurriculumChanged.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224']);
    $this->specialty = Specialty::factory()->for($this->plan)->create(['code' => 'ISIC-ISW-2020', 'name' => 'Ingeniería de Software'])->fresh();
});

function specialtyErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar.');
}

function newSpecialty(StudyPlan $plan, array $overrides = []): Specialty
{
    return app(CreateSpecialty::class)->handle($plan, ['code' => ' isic-cda 2022 ', 'name' => 'Ciencia de Datos', ...$overrides]);
}

describe('CreateSpecialty', function () {
    it('da de alta una especialidad activa con la clave normalizada, bitácora y aviso, sin CurriculumChanged', function () {
        Event::fake([SpecialtyChanged::class, CurriculumChanged::class]);

        $specialty = newSpecialty($this->plan);

        expect($specialty->fresh())
            ->study_plan_id->toBe($this->plan->id)->code->toBe('ISIC-CDA2022')->name->toBe('Ciencia de Datos')->is_active->toBeTrue();

        $activity = Activity::where('subject_id', $specialty->id)->sole();
        expect($activity)->description->toBe('Dio de alta la especialidad ISIC-CDA2022 del plan ISIC-2010-224')->event->toBe('created')
            ->and($activity->attribute_changes->toArray())->toBe(['attributes' => ['code' => 'ISIC-CDA2022', 'name' => 'Ciencia de Datos', 'is_active' => true]]);
        Event::assertDispatched(SpecialtyChanged::class, fn (SpecialtyChanged $event): bool => $event->specialty->is($specialty));
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('rechaza una clave repetida en el plan, también contra una especialidad quitada', function () {
        expect(specialtyErrors(fn () => newSpecialty($this->plan, ['code' => 'isic-isw-2020'])))
            ->toBe(['code' => ['Ya existe una especialidad con la clave ISIC-ISW-2020 en este plan, aunque esté quitada.']]);

        $this->specialty->delete();

        expect(specialtyErrors(fn () => newSpecialty($this->plan, ['code' => 'ISIC-ISW-2020'])))->toHaveKey('code')
            ->and(Specialty::withTrashed()->count())->toBe(1);
    });

    it('acepta la misma clave en otro plan', function () {
        $other = StudyPlan::factory()->create();

        expect(newSpecialty($other, ['code' => 'ISIC-ISW-2020'])->study_plan_id)->toBe($other->id);
    });

    it('rechaza una clave o un nombre vacíos con error en su campo', function (string $field, mixed $value) {
        expect(specialtyErrors(fn () => newSpecialty($this->plan, [$field => $value])))->toHaveKey($field)
            ->and(Specialty::count())->toBe(1);
    })->with([
        'clave vacía' => ['code', '   '],
        'nombre vacío' => ['name', ' '],
        'nombre null' => ['name', null],
    ]);
});

describe('UpdateSpecialty', function () {
    it('renombra con bitácora de lo anterior y lo nuevo, y avisa sin CurriculumChanged', function () {
        Event::fake([SpecialtyChanged::class, CurriculumChanged::class]);

        app(UpdateSpecialty::class)->handle($this->specialty, ['name' => 'Software Empresarial', 'code' => 'NO-CAMBIA']);

        $activity = Activity::where('subject_id', $this->specialty->id)->sole();
        expect($this->specialty->fresh())->name->toBe('Software Empresarial')->code->toBe('ISIC-ISW-2020')
            ->and($activity->description)->toBe('Actualizó la especialidad ISIC-ISW-2020 del plan ISIC-2010-224')
            ->and($activity->attribute_changes->toArray())->toBe(['attributes' => ['name' => 'Software Empresarial'], 'old' => ['name' => 'Ingeniería de Software']]);
        Event::assertDispatched(SpecialtyChanged::class);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('desactiva aunque tenga materias en la retícula, sin CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);
        placeInPlan($this->plan, Subject::factory()->create(), 7, $this->specialty);

        app(UpdateSpecialty::class)->handle($this->specialty, ['is_active' => false]);

        expect($this->specialty->fresh()->is_active)->toBeFalse();
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('si nada cambió no escribe, no registra ni avisa', function () {
        Event::fake([SpecialtyChanged::class]);

        app(UpdateSpecialty::class)->handle($this->specialty, ['name' => 'Ingeniería de Software', 'is_active' => true]);

        expect(Activity::count())->toBe(0);
        Event::assertNotDispatched(SpecialtyChanged::class);
    });

    it('rechaza un nombre vacío y no cambia nada', function () {
        expect(specialtyErrors(fn () => app(UpdateSpecialty::class)->handle($this->specialty, ['name' => '  '])))->toHaveKey('name')
            ->and($this->specialty->fresh()->name)->toBe('Ingeniería de Software');
    });
});

describe('RemoveSpecialty (S1)', function () {
    it('quita una especialidad sin materias con bitácora y aviso, sin CurriculumChanged', function () {
        Event::fake([SpecialtyChanged::class, CurriculumChanged::class]);
        placeInPlan($this->plan, Subject::factory()->create(), 7, $this->specialty)->delete();

        app(RemoveSpecialty::class)->handle($this->specialty);

        $activity = Activity::where('subject_id', $this->specialty->id)->sole();
        expect($this->specialty->fresh()->trashed())->toBeTrue()
            ->and($activity->description)->toBe('Quitó la especialidad ISIC-ISW-2020 del plan ISIC-2010-224')
            ->and($activity->event)->toBe('deleted')
            ->and($activity->attribute_changes->toArray())->toBe(['old' => ['code' => 'ISIC-ISW-2020', 'name' => 'Ingeniería de Software', 'is_active' => true]]);
        Event::assertDispatched(SpecialtyChanged::class);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('no quita una especialidad con materias en la retícula y dice cuántas', function (int $subjects, string $message) {
        foreach (range(1, $subjects) as $semester) {
            placeInPlan($this->plan, Subject::factory()->create(), $semester, $this->specialty);
        }

        expect(specialtyErrors(fn () => app(RemoveSpecialty::class)->handle($this->specialty)))->toBe(['specialty' => [$message]])
            ->and($this->specialty->fresh()->trashed())->toBeFalse()
            ->and(Activity::count())->toBe(0);
    })->with([
        'una' => [1, 'Tiene 1 materia en la retícula; una especialidad con materias no se quita.'],
        'varias' => [3, 'Tiene 3 materias en la retícula; una especialidad con materias no se quita.'],
    ]);
});
