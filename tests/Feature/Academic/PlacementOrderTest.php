<?php

use App\Modules\Academic\Actions\PlaceSubject;
use App\Modules\Academic\Actions\ReorderPlacements;
use App\Modules\Academic\Actions\ResolvePendingPlacement;
use App\Modules\Academic\Actions\UpdatePlacement;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Queries\PlanGridEditorQuery;
use App\Modules\Core\Models\User;
use App\Modules\Students\Queries\PlanGridQuery;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Orden de las materias dentro de su semestre (D21, confirmado el 5 de noviembre de 2026): `position` en
| `study_plan_subjects`, como el renglón de la retícula oficial. Las Queries ordenan por posición (las sin posición
| al final, por clave); ReorderPlacements reescribe el orden de un semestre; poner o mover una materia la deja al final.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224', 'semesters_count' => 9]);
    $this->user = User::factory()->admin()->create();
    $this->subject = fn (string $code): Subject => Subject::factory()->create(['code' => $code, 'name' => "Materia {$code}"]);
});

/**
 * Los ids de las materias de un semestre como los pinta el editor, por clave.
 *
 * @return list<string>
 */
function editorOrder(StudyPlan $plan, int $semester): array
{
    return array_column(app(PlanGridEditorQuery::class)->forPlan($plan)['semesters'][$semester], 'code');
}

describe('orden en las Queries', function () {
    it('el editor ordena por posición, las sin posición al final y por clave', function () {
        placeInPlan($this->plan, ($this->subject)('CCC-1'))->update(['position' => 1]);
        placeInPlan($this->plan, ($this->subject)('AAA-1'))->update(['position' => 2]);
        placeInPlan($this->plan, ($this->subject)('ZZZ-1'));
        placeInPlan($this->plan, ($this->subject)('BBB-1'));

        expect(editorOrder($this->plan, 1))->toBe(['CCC-1', 'AAA-1', 'BBB-1', 'ZZZ-1']);
    });

    it('la retícula del alumno (PlanGridQuery) también respeta la posición', function () {
        $first = placeInPlan($this->plan, ($this->subject)('AAA-1'));
        $second = placeInPlan($this->plan, ($this->subject)('BBB-1'));
        $first->update(['position' => 2]);
        $second->update(['position' => 1]);

        expect(array_keys(app(PlanGridQuery::class)->forPlan($this->plan->id)->semesterOf))->toBe([$second->subject_id, $first->subject_id]);
    });
});

describe('ReorderPlacements', function () {
    it('reescribe las posiciones 1..n del semestre y deja bitácora con el orden viejo y el nuevo, sin avisar del avance', function () {
        $a = placeInPlan($this->plan, ($this->subject)('AAA-1'));
        $b = placeInPlan($this->plan, ($this->subject)('BBB-1'));
        $c = placeInPlan($this->plan, ($this->subject)('CCC-1'));
        $other = placeInPlan($this->plan, ($this->subject)('DDD-1'), 2);
        Event::fake([CurriculumChanged::class]);

        app(ReorderPlacements::class)->handle($this->plan, 1, [$c->id, $a->id, $b->id], $this->user);

        expect(editorOrder($this->plan, 1))->toBe(['CCC-1', 'AAA-1', 'BBB-1'])
            ->and([$c->fresh()->position, $a->fresh()->position, $b->fresh()->position])->toBe([1, 2, 3])
            ->and($other->fresh()->position)->toBeNull();

        $activity = Activity::query()->where('description', 'like', 'Reordenó%')->sole();

        expect($activity->causer_id)->toBe($this->user->id)
            ->and($activity->attribute_changes['old']['order'])->toBe(['AAA-1', 'BBB-1', 'CCC-1'])
            ->and($activity->attribute_changes['attributes']['order'])->toBe(['CCC-1', 'AAA-1', 'BBB-1']);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('rechaza ids de más, de menos, repetidos o de otro semestre, sin tocar nada', function (string $case) {
        $a = placeInPlan($this->plan, ($this->subject)('AAA-1'));
        $b = placeInPlan($this->plan, ($this->subject)('BBB-1'));
        $other = placeInPlan($this->plan, ($this->subject)('DDD-1'), 2);

        expect(fn () => app(ReorderPlacements::class)->handle($this->plan, 1, match ($case) {
            'de menos' => [$a->id],
            'de más (otro semestre)' => [$a->id, $b->id, $other->id],
            'repetido' => [$a->id, $a->id],
            'inexistente' => [$a->id, '019f0000-0000-7000-8000-000000000000'],
        }, $this->user))->toThrow(ValidationException::class)
            ->and(StudyPlanSubject::query()->whereNotNull('position')->count())->toBe(0)
            ->and(Activity::query()->where('description', 'like', 'Reordenó%')->count())->toBe(0);
    })->with(['de menos', 'de más (otro semestre)', 'repetido', 'inexistente']);

    it('rechaza las materias de otro plan', function () {
        $a = placeInPlan($this->plan, ($this->subject)('AAA-1'));
        $foreign = placeInPlan(StudyPlan::factory()->create(), ($this->subject)('FFF-1'));

        expect(fn () => app(ReorderPlacements::class)->handle($this->plan, 1, [$a->id, $foreign->id], $this->user))->toThrow(ValidationException::class);
    });

    it('si el orden no cambia, no escribe ni registra', function () {
        $a = placeInPlan($this->plan, ($this->subject)('AAA-1'));
        $b = placeInPlan($this->plan, ($this->subject)('BBB-1'));
        app(ReorderPlacements::class)->handle($this->plan, 1, [$a->id, $b->id], $this->user);
        $before = Activity::count();

        app(ReorderPlacements::class)->handle($this->plan, 1, [$a->id, $b->id], $this->user);

        expect(Activity::count())->toBe($before);
    });
});

describe('al final del semestre', function () {
    it('PlaceSubject pone la materia nueva al final, también tras las que no tenían posición', function () {
        placeInPlan($this->plan, ($this->subject)('ZZZ-1'));
        placeInPlan($this->plan, ($this->subject)('YYY-1'))->update(['position' => 1]);

        $placed = app(PlaceSubject::class)->handle($this->plan, ($this->subject)('AAA-1'), 1);

        expect(editorOrder($this->plan, 1))->toBe(['YYY-1', 'ZZZ-1', 'AAA-1'])
            ->and($placed->fresh()->position)->toBe(3);
    });

    it('UpdatePlacement al mover de semestre la deja al final del nuevo', function () {
        placeInPlan($this->plan, ($this->subject)('MMM-1'), 2);
        $moving = placeInPlan($this->plan, ($this->subject)('AAA-1'));

        app(UpdatePlacement::class)->handle($moving, ['semester' => 2]);

        expect(editorOrder($this->plan, 2))->toBe(['MMM-1', 'AAA-1']);
    });

    it('UpdatePlacement sin cambiar de semestre no mueve la posición', function () {
        $a = placeInPlan($this->plan, ($this->subject)('AAA-1'));
        $a->update(['position' => 4]);

        app(UpdatePlacement::class)->handle($a, ['is_required' => false]);

        expect($a->fresh()->position)->toBe(4);
    });

    it('ResolvePendingPlacement la pone al final del semestre', function () {
        placeInPlan($this->plan, ($this->subject)('MMM-1'), 3);
        $pending = StudyPlanSubjectPending::factory()->create(['study_plan_id' => $this->plan->id, 'subject_id' => ($this->subject)('AAA-1')->id]);

        $placement = app(ResolvePendingPlacement::class)->handle($pending, 3, $this->user);

        expect(editorOrder($this->plan, 3))->toBe(['MMM-1', 'AAA-1'])
            ->and($placement->fresh()->position)->toBe(2);
    });
});
