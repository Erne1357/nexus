<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Scheduling\Actions\Parallels\ArmParallelGroups;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Notifications\ParallelsArranged;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Scheduling\ParallelsWorld;

/*
| Avisos al armar paralelos (tarea 8.8): sin acuerdo que aceptar, se avisa por la campana a los coordinadores de cada carrera
| cuyos lugares cambiaron y al jefe de su departamento cuando no es el que imparte (confirmado el 17 de noviembre de 2026),
| con sus grupos y lugares antes y después.
*/

beforeEach(function () {
    $this->world = ParallelsWorld::create();
    $this->isic = $this->world->career('ISIC');
    $this->iind = $this->world->career('IIND');
    $this->itic = $this->world->career('ITIC');
    $this->lmat = $this->world->career('LMAT', $this->world->department);
    $modality = ['modality_id' => $this->world->modality->id];
    $this->coordinators = collect([$this->isic, $this->iind, $this->itic, $this->lmat])
        ->mapWithKeys(fn (Career $career): array => [$career->code => User::factory()->withRole(Role::Coordinador, [...$modality, 'career_id' => $career->id])->create()]);
    $this->jefeIsic = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->isic->department_id])->create();
    $this->jefeIind = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->iind->department_id])->create();
    $this->jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
    $this->jefeItic = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->itic->department_id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    [$this->first] = $this->world->groupsOf($this->world->request($this->isic, 30, 2), 2);
    $this->world->groupsOf($this->world->request($this->itic, 35, 1));
    $this->world->request($this->iind, 3, 1);
    $this->world->request($this->lmat, 2, 1);
});

function armParallelsForNotices(): void
{
    $world = test()->world;

    app(ArmParallelGroups::class)->handle($world->term, $world->modality, $world->department, $world->subject, $world->campus, [
        [test()->isic->id => 30, test()->iind->id => 3, test()->lmat->id => 2],
        [test()->itic->id => 35],
    ], test()->jefeCb);
}

it('avisa a los coordinadores de las carreras que cambiaron y a los jefes de sus departamentos cuando son otros', function () {
    Notification::fake();

    armParallelsForNotices();

    Notification::assertSentTo([$this->coordinators['ISIC'], $this->coordinators['IIND'], $this->coordinators['LMAT'], $this->jefeIsic, $this->jefeIind], ParallelsArranged::class);
    Notification::assertNotSentTo([$this->coordinators['ITIC'], $this->jefeCb, $this->subdireccion], ParallelsArranged::class);
    Notification::assertSentToTimes($this->jefeIsic, ParallelsArranged::class, 1);
});

it('dice con qué grupo, qué cupo y qué cambió, y lleva al editor con el grupo', function () {
    Notification::fake();

    armParallelsForNotices();

    Notification::assertSentTo($this->coordinators['ISIC'], ParallelsArranged::class, function (ParallelsArranged $notice): bool {
        $message = $notice->message($this->coordinators['ISIC']);

        return $message->title === 'Ciencias Básicas armó los paralelos de ACF-0902 Cálculo Integral en C1'
            && $message->body === 'ISIC: 30 lugares en el grupo 1. Antes: 35 en el 1 y 35 en el 2.'
            && $message->route === 'scheduling.editor'
            && $message->parameters === ['grupo' => $this->first->id]
            && $message->modalityId === $this->world->modality->id;
    });
    Notification::assertSentTo($this->coordinators['IIND'], ParallelsArranged::class, fn (ParallelsArranged $notice): bool => $notice->message($this->coordinators['IIND'])->body === 'IIND: 3 lugares en el grupo 1.');
});

it('la carrera dueña de un grupo que se cancela y se queda sin lugares también recibe el aviso', function () {
    Notification::fake();
    $world = $this->world;

    app(ArmParallelGroups::class)->handle($world->term, $world->modality, $world->department, $world->subject, $world->campus, [
        [$this->isic->id => 30, $this->iind->id => 3, $this->lmat->id => 2],
    ], $this->jefeCb);

    Notification::assertSentTo($this->coordinators['ITIC'], ParallelsArranged::class, fn (ParallelsArranged $notice): bool => $notice->message($this->coordinators['ITIC'])->body === 'ITIC se queda sin lugares. Antes: 35 en el 3.');
    Notification::assertSentTo($this->jefeItic, ParallelsArranged::class);
});

it('avisa a la carrera que se queda sin lugares, sin grupo que abrir', function () {
    $notice = new ParallelsArranged($this->world->term->id, $this->world->modality->id, 'Ciencias Básicas', 'ACF-0902 Cálculo Integral', 'C1', 'IIND', [['code' => '1', 'seats' => 3], ['code' => '4', 'seats' => 2]], [], null);
    $message = $notice->message($this->coordinators['IIND']);

    expect($message->body)->toBe('IIND se queda sin lugares. Antes: 3 en el 1 y 2 en el 4.')
        ->and($message->parameters)->toBe([]);
});

it('deja de abrirse si el grupo se canceló', function () {
    $message = new NotificationMessage(title: 'x', body: 'y', icon: 'o-arrows-pointing-in', route: 'scheduling.editor', parameters: ['grupo' => $this->first->id]);
    $context = app(Actor::class)->forUser($this->coordinators['ISIC']);

    expect(ParallelsArranged::unavailableReason($context, $message))->toBeNull();

    Group::query()->whereKey($this->first->id)->update(['status' => 'cancelled']);

    expect(ParallelsArranged::unavailableReason($context, $message))->toBe('Ese grupo se canceló.');
});
