<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Actions\JustifyGroups;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Staffing\ClosingWorld;

/*
| La justificación en lote de HC-15 (confirmado el 10 de noviembre de 2026): quien cierra la carga elige varios grupos y
| escribe un motivo para todos. Cada grupo queda con su justificación, quién y cuándo, y su bitácora, con las reglas de
| JustifyGroup (la carga se edita; solo grupos sin docente o sin salón) y la policy `justify` por grupo. Lo que no
| aplica se devuelve con su motivo. Hasta 200 grupos por vez.
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->jefe = $this->world->head();
});

/**
 * @param  list<Group>  $groups
 * @return array{justified: list<string>, skipped: list<array{code: string, reason: string}>}
 */
function justifyGroupsOf(array $groups, string $text = 'Contratación en trámite', ?array $ids = null): array
{
    return app(JustifyGroups::class)->handle($ids ?? array_map(fn (Group $group): string => $group->id, $groups), $text, test()->jefe);
}

it('justifica varios grupos con un motivo: cada uno con quién, cuándo y su bitácora', function () {
    $this->freezeTime();
    $groups = [$this->world->group(teacher: false), $this->world->group(withRoom: false), $this->world->group(teacher: false, withRoom: false)];

    $result = justifyGroupsOf($groups, '  Docente de nuevo ingreso, contratación en trámite  ');

    expect($result['skipped'])->toBe([])
        ->and($result['justified'])->toHaveCount(3);

    foreach ($groups as $group) {
        expect($group->fresh()->only(['close_justification', 'close_justified_by']))->toBe([
            'close_justification' => 'Docente de nuevo ingreso, contratación en trámite',
            'close_justified_by' => $this->jefe->id,
        ])->and($group->fresh()->close_justified_at->toDateTimeString())->toBe(now()->toDateTimeString());
    }

    expect(Activity::query()->where('event', 'justified')->pluck('subject_id')->sort()->values()->all())
        ->toBe(collect($groups)->pluck('id')->sort()->values()->all());
});

it('devuelve con su motivo los que no aplican y justifica los demás: uno completo y uno de una carga cerrada', function () {
    $pending = $this->world->group(teacher: false);
    $complete = $this->world->group();
    modalityOf('EAD')->update(['is_active' => true]);
    $closed = $this->world->group(teacher: false, load: $this->world->loadIn(Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I', 'modality_id' => modalityOf('EAD')->id]), LoadStatus::Closed));

    $result = justifyGroupsOf([$pending, $complete, $closed]);

    expect($result['justified'])->toBe([$pending->id])
        ->and($result['skipped'])->toBe([
            ['code' => $complete->code, 'reason' => 'El grupo ya tiene docente y salón: no necesita justificación.'],
            ['code' => $closed->code, 'reason' => 'La carga de Sistemas y Computación en EAD está en cerrada: no se editan justificaciones.'],
        ])
        ->and($complete->fresh()->close_justification)->toBeNull()
        ->and($closed->fresh()->close_justification)->toBeNull();
});

it('la policy va por grupo: uno de otro departamento en la lista no se justifica', function () {
    $own = $this->world->group(teacher: false);
    $foreign = $this->world->group(teacher: false, load: $this->world->loadIn($this->world->campus, department: Department::factory()->create(['code' => 'EE', 'name' => 'Eléctrica y Electrónica'])));

    $result = justifyGroupsOf([$own, $foreign]);

    expect($result['justified'])->toBe([$own->id])
        ->and($result['skipped'])->toBe([['code' => $foreign->code, 'reason' => 'Lo justifica quien cierra la carga de Eléctrica y Electrónica.']])
        ->and($foreign->fresh()->close_justification)->toBeNull();
});

it('un deadlock aborta el lote completo con un mensaje claro: no sigue con los demás grupos', function () {
    $first = $this->world->group(teacher: false);
    $second = $this->world->group(teacher: false);
    $attempts = 0;

    Group::updating(function () use (&$attempts): void {
        $attempts++;
        $deadlock = new QueryException('pgsql', 'update ...', [], new PDOException('deadlock detected', 0));
        $deadlock->errorInfo = ['40P01', 7, 'deadlock detected'];

        throw $deadlock;
    });

    expect(fn () => justifyGroupsOf([$first, $second]))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toBe(['groups' => ['Otra persona está cambiando estas cargas: no se justificó ningún grupo. Intenta de nuevo.']]),
    );
    expect($attempts)->toBe(1)
        ->and($second->fresh()->close_justification)->toBeNull();
});

it('un error de base que no es de regla no se toma como rechazo: aborta el lote', function () {
    $first = $this->world->group(teacher: false);
    $second = $this->world->group(teacher: false);

    Group::updating(fn () => throw new QueryException('pgsql', 'update ...', [], new PDOException('otro')));

    expect(fn () => justifyGroupsOf([$first, $second]))->toThrow(QueryException::class);
    expect($second->fresh()->close_justification)->toBeNull();
});

it('el motivo es obligatorio', function () {
    $group = $this->world->group(teacher: false);

    expect(fn () => justifyGroupsOf([$group], '   '))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toBe(['justification' => ['Escribe la justificación.']]),
    );
});

it('justifica hasta 200 grupos por vez', function () {
    $group = $this->world->group(teacher: false);
    $ids = [$group->id, ...array_map(fn (): string => (string) Str::uuid7(), range(1, 200))];

    expect(fn () => justifyGroupsOf([], ids: $ids))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toBe(['groups' => ['Justifica hasta 200 grupos por vez.']]),
    );
    expect($group->fresh()->close_justification)->toBeNull();

    expect(justifyGroupsOf([], ids: array_slice($ids, 0, 200))['justified'])->toBe([$group->id]);
});
