<?php

use App\Modules\Academic\Actions\CreateSubject;
use App\Modules\Academic\Actions\UpdateSubject;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\SubjectCreated;
use App\Modules\Academic\Events\SubjectDeactivating;
use App\Modules\Academic\Events\SubjectUpdated;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de Materia. Crear y editar hacen cumplir la clave normalizada y única, también entre las
| borradas; horas y créditos no negativos; el tipo de SubjectKind o vacío, y un departamento que
| existe. Desactivar deja que Students lo vete con SubjectDeactivating (alumnos que la necesitan) y
| un cambio de créditos o de tipo avisa con CurriculumChanged para recalcular el avance.
*/

beforeEach(function () {
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->subject = Subject::factory()->create([
        'code' => 'ACF-0901',
        'name' => 'Cálculo Diferencial',
        'department_id' => $this->sis->id,
        'theory_hours' => 3,
        'practice_hours' => 2,
        'credits' => 5,
        'kind' => SubjectKind::Basica,
        'needs_lab' => false,
    ])->fresh();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function newSubject(array $overrides = []): Subject
{
    return app(CreateSubject::class)->handle([
        'code' => 'SCD-1027',
        'name' => 'Tópicos Avanzados de Programación',
        'department_id' => test()->sis->id,
        'theory_hours' => 2,
        'practice_hours' => 3,
        'credits' => 5,
        'kind' => 'especialidad',
        'needs_lab' => true,
        ...$overrides,
    ]);
}

/**
 * Los errores de validación que lanza `$action`, o falla si no lanza.
 *
 * @return array<string, list<string>>
 */
function subjectErrorsOf(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar con un error de validación.');
}

describe('alta', function () {
    it('da de alta una materia activa con la clave en mayúsculas y sin espacios', function () {
        $subject = newSubject(['code' => ' scd-1027 ']);

        expect($subject->fresh())
            ->code->toBe('SCD-1027')
            ->name->toBe('Tópicos Avanzados de Programación')
            ->department_id->toBe($this->sis->id)
            ->theory_hours->toBe(2)
            ->practice_hours->toBe(3)
            ->credits->toBe(5)
            ->kind->toBe(SubjectKind::Especialidad)
            ->needs_lab->toBeTrue()
            ->is_active->toBeTrue();
    });

    it('acepta el tipo vacío: la exportación del SII no lo trae', function (?string $kind) {
        expect(newSubject(['kind' => $kind])->fresh()->kind)->toBeNull();
    })->with(['vacío' => '', 'nulo' => null]);

    it('registra en la bitácora quién dio de alta la materia y con qué datos, y avisa con SubjectCreated', function () {
        Event::fake([SubjectCreated::class, CurriculumChanged::class]);
        $this->actingAs($admin = User::factory()->admin()->create());

        $subject = newSubject();

        $entry = Activity::sole();

        expect($entry)
            ->event->toBe('created')
            ->description->toBe('Dio de alta la materia SCD-1027')
            ->and($entry->subject->is($subject))->toBeTrue()
            ->and($entry->causer->is($admin))->toBeTrue()
            ->and($entry->attribute_changes->toArray())->toBe([
                'attributes' => [
                    'code' => 'SCD-1027',
                    'name' => 'Tópicos Avanzados de Programación',
                    'department_id' => $this->sis->id,
                    'theory_hours' => 2,
                    'practice_hours' => 3,
                    'credits' => 5,
                    'kind' => 'especialidad',
                    'needs_lab' => true,
                    'is_active' => true,
                ],
            ]);
        Event::assertDispatched(SubjectCreated::class, fn (SubjectCreated $event): bool => $event->subject->is($subject));
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('rechaza una clave repetida con un error en code, también contra una borrada', function (bool $trashed) {
        if ($trashed) {
            $this->subject->delete();
        }

        expect(subjectErrorsOf(fn () => newSubject(['code' => 'acf-0901']))['code'][0])
            ->toBe('Ya existe una materia con la clave ACF-0901, aunque esté desactivada o borrada.')
            ->and(Subject::withTrashed()->count())->toBe(1);
    })->with(['activa' => false, 'borrada' => true]);

    it('rechaza una clave que queda vacía al normalizar', function () {
        expect(subjectErrorsOf(fn () => newSubject(['code' => "\u{00A0} "]))['code'][0])->toContain('La clave no puede quedar vacía');
    });

    it('rechaza horas o créditos negativos con un error en su campo', function (string $field, string $message) {
        expect(subjectErrorsOf(fn () => newSubject([$field => -1])))->toBe([$field => [$message]])
            ->and(Subject::count())->toBe(1);
    })->with([
        'horas de teoría' => ['theory_hours', 'Las horas de teoría no pueden ser negativas.'],
        'horas de práctica' => ['practice_hours', 'Las horas de práctica no pueden ser negativas.'],
        'créditos' => ['credits', 'Los créditos no pueden ser negativos.'],
    ]);

    it('rechaza un tipo que no es de SubjectKind con un error en kind', function () {
        expect(subjectErrorsOf(fn () => newSubject(['kind' => 'optativa'])))->toBe(['kind' => ['El tipo de materia no es válido.']]);
    });

    it('rechaza un departamento que no existe, está borrado o no es uuid con un error en department_id', function (string $departmentId) {
        expect(subjectErrorsOf(fn () => newSubject(['department_id' => $departmentId])))
            ->toBe(['department_id' => ['El departamento no existe o está borrado.']])
            ->and(Subject::count())->toBe(1);
    })->with([
        'no existe' => fn () => fakeKey(99),
        'borrado' => fn () => tap(Department::factory()->create())->delete()->id,
        'no es uuid' => 'no-es-uuid',
    ]);

    it('acepta un departamento inactivo: la importación también', function () {
        $inactive = Department::factory()->inactive()->create();

        expect(newSubject(['department_id' => $inactive->id])->department_id)->toBe($inactive->id);
    });
});

describe('edición', function () {
    it('edita los datos y acepta su propia clave', function () {
        $ele = Department::factory()->create();

        app(UpdateSubject::class)->handle($this->subject, [
            'code' => 'acf-0901',
            'name' => 'Cálculo',
            'department_id' => $ele->id,
            'theory_hours' => 4,
            'practice_hours' => 1,
            'needs_lab' => true,
        ]);

        expect($this->subject->fresh())
            ->code->toBe('ACF-0901')
            ->name->toBe('Cálculo')
            ->department_id->toBe($ele->id)
            ->theory_hours->toBe(4)
            ->practice_hours->toBe(1)
            ->needs_lab->toBeTrue();
    });

    it('rechaza la clave de otra materia', function () {
        Subject::factory()->create(['code' => 'ACF-0902']);

        expect(subjectErrorsOf(fn () => app(UpdateSubject::class)->handle($this->subject, ['code' => 'acf-0902']))['code'][0])
            ->toContain('Ya existe una materia con la clave ACF-0902');
    });

    it('rechaza créditos negativos, un tipo inválido o un departamento que no existe sin escribir', function (array $data, string $field) {
        expect(subjectErrorsOf(fn () => app(UpdateSubject::class)->handle($this->subject, $data)))->toHaveKey($field)
            ->and($this->subject->fresh())->credits->toBe(5)->kind->toBe(SubjectKind::Basica)->department_id->toBe($this->sis->id)
            ->and(Activity::count())->toBe(0);
    })->with([
        'créditos' => [['credits' => -2], 'credits'],
        'tipo' => [['kind' => 'optativa'], 'kind'],
        'departamento' => [fn () => ['department_id' => fakeKey(98)], 'department_id'],
    ]);

    it('sin cambios no escribe, no registra ni avisa', function () {
        Event::fake([SubjectUpdated::class, CurriculumChanged::class, SubjectDeactivating::class]);
        $updatedAt = $this->subject->updated_at;
        $this->travel(1)->minute();

        app(UpdateSubject::class)->handle($this->subject, [
            'code' => 'acf-0901',
            'name' => 'Cálculo Diferencial',
            'department_id' => $this->sis->id,
            'theory_hours' => 3,
            'practice_hours' => 2,
            'credits' => 5,
            'kind' => 'basica',
            'needs_lab' => false,
            'is_active' => true,
        ]);

        expect($this->subject->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
            ->and(Activity::count())->toBe(0);
        Event::assertNothingDispatched();
    });

    it('deja en la bitácora el valor anterior y el nuevo de lo que cambió, y avisa con SubjectUpdated', function () {
        Event::fake([SubjectUpdated::class]);

        app(UpdateSubject::class)->handle($this->subject, ['name' => 'Cálculo', 'kind' => '']);

        $entry = Activity::sole();

        expect($entry)
            ->event->toBe('updated')
            ->description->toBe('Actualizó la materia ACF-0901')
            ->and($entry->attribute_changes->toArray())->toBe([
                'attributes' => ['name' => 'Cálculo', 'kind' => null],
                'old' => ['name' => 'Cálculo Diferencial', 'kind' => 'basica'],
            ]);
        Event::assertDispatched(SubjectUpdated::class, fn (SubjectUpdated $event): bool => $event->subject->is($this->subject) && $event->changed === ['name', 'kind']);
    });

    it('un cambio de créditos o de tipo avisa con CurriculumChanged de la materia', function (array $data) {
        Event::fake([CurriculumChanged::class]);

        app(UpdateSubject::class)->handle($this->subject, $data);

        Event::assertDispatchedTimes(CurriculumChanged::class, 1);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->subjectId === $this->subject->id && $event->studyPlanId === null);
    })->with([
        'créditos' => [['credits' => 6]],
        'tipo' => [['kind' => 'especialidad']],
        'tipo a vacío' => [['kind' => '']],
        'los dos' => [['credits' => 4, 'kind' => 'complementaria']],
    ]);

    it('un cambio de clave, nombre, departamento, horas, laboratorio o estado no avisa con CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);

        app(UpdateSubject::class)->handle($this->subject, [
            'code' => 'ACF-0999',
            'name' => 'Cálculo',
            'department_id' => Department::factory()->create()->id,
            'theory_hours' => 4,
            'practice_hours' => 1,
            'needs_lab' => true,
        ]);
        app(UpdateSubject::class)->handle($this->subject, ['is_active' => false]);
        app(UpdateSubject::class)->handle($this->subject, ['is_active' => true]);

        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('CurriculumChanged pide al menos el plan o la materia', function () {
        expect(fn () => new CurriculumChanged)->toThrow(InvalidArgumentException::class)
            ->and(new CurriculumChanged(studyPlanId: fakeKey(1)))->studyPlanId->toBe(fakeKey(1))->subjectId->toBeNull();
    });
});

describe('desactivar', function () {
    it('desactiva y activa dejando en la bitácora el valor anterior', function () {
        app(UpdateSubject::class)->handle($this->subject, ['is_active' => false]);
        app(UpdateSubject::class)->handle($this->subject, ['is_active' => true]);

        [$off, $on] = Activity::orderBy('id')->get()->all();

        expect($off->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]])
            ->and($on->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => true], 'old' => ['is_active' => false]]);
    });

    it('al desactivar despacha SubjectDeactivating con la materia; al activar o editar, no', function () {
        Event::fake([SubjectDeactivating::class]);

        app(UpdateSubject::class)->handle($this->subject, ['name' => 'Cálculo']);
        Event::assertNotDispatched(SubjectDeactivating::class);

        app(UpdateSubject::class)->handle($this->subject, ['is_active' => false]);
        Event::assertDispatchedTimes(SubjectDeactivating::class, 1);
        Event::assertDispatched(SubjectDeactivating::class, fn (SubjectDeactivating $event): bool => $event->subject->is($this->subject));

        app(UpdateSubject::class)->handle($this->subject, ['is_active' => true]);
        Event::assertDispatchedTimes(SubjectDeactivating::class, 1);
    });

    it('rechaza con los motivos del veto en is_active, sin desactivar, registrar ni avisar', function () {
        Event::fake([SubjectUpdated::class]);
        Event::listen(SubjectDeactivating::class, fn (SubjectDeactivating $event) => $event->block('La necesitan 3 alumnos.'));
        Event::listen(SubjectDeactivating::class, fn (SubjectDeactivating $event) => $event->block('Tiene grupos.'));

        expect(subjectErrorsOf(fn () => app(UpdateSubject::class)->handle($this->subject, ['name' => 'Cálculo', 'is_active' => false])))
            ->toBe(['is_active' => ['La necesitan 3 alumnos.', 'Tiene grupos.']])
            ->and($this->subject->fresh())->is_active->toBeTrue()->name->toBe('Cálculo Diferencial')
            ->and(Activity::count())->toBe(0);
        Event::assertNotDispatched(SubjectUpdated::class);
    });
});
