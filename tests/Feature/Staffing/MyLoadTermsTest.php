<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Queries\MyLoadTerms;
use Carbon\CarbonImmutable;

/*
| La regla de periodos de Mi carga y del Resumen del docente (tareas 3.10 y 1.85; TP-03, TP-04, #755, MN-05): en curso
| es el de consulta de la credencial; en planeación, el activo; el segundo se ofrece solo con grupos de Nexus y con la
| carga visible (supuesto por confirmar).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'America/Ciudad_Juarez'));
    $this->planning = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->running = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->dept = Department::factory()->create(['code' => 'SC']);
    $this->teacher = Teacher::factory()->adscribedTo($this->dept)->create();
    $this->user = User::factory()->withRole(Role::Docente)->create();
    $this->teacher->update(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

function termsGroup(Term $term): Group
{
    return Group::factory()->create(['term_id' => $term->id, 'campus_id' => Campus::factory()->create()->id, 'teacher_id' => test()->teacher->id]);
}

function termsVisible(bool $visible): void
{
    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING), $visible);
    app(Actor::class)->refresh();
}

it('TP-03: el periodo en curso es el de consulta y el de planeación es el activo', function () {
    $terms = app(MyLoadTerms::class);
    $actor = app(Actor::class);

    expect($terms->inProgress($actor)?->code)->toBe('20263')
        ->and($terms->planning($actor)?->code)->toBe('20271');
});

it('ofrece el periodo en planeación si es distinto del en curso y el docente tiene grupos de Nexus en él', function () {
    termsGroup($this->planning);

    expect(app(MyLoadTerms::class)->offersPlanning(app(Actor::class), $this->teacher))->toBeTrue();
});

it('no lo ofrece si la carga está oculta para él (MN-05)', function () {
    termsGroup($this->planning);
    termsVisible(false);

    expect(app(MyLoadTerms::class)->offersPlanning(app(Actor::class), $this->teacher))->toBeFalse();
});

it('no lo ofrece sin grupos de Nexus en el periodo en planeación', function () {
    termsGroup($this->running);

    expect(app(MyLoadTerms::class)->offersPlanning(app(Actor::class), $this->teacher))->toBeFalse();
});

it('no lo ofrece si el periodo en planeación es el mismo que el en curso', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00', 'America/Ciudad_Juarez'));
    app(Actor::class)->refresh();
    termsGroup($this->planning);

    expect(app(MyLoadTerms::class)->offersPlanning(app(Actor::class), $this->teacher))->toBeFalse();
});

it('el periodo que se ve es el pedido solo si se ofrece; si no, el en curso', function () {
    $terms = app(MyLoadTerms::class);
    $actor = app(Actor::class);

    expect($terms->selected($actor, $this->teacher, $this->planning->id)?->code)->toBe('20263');

    termsGroup($this->planning);

    expect($terms->selected($actor, $this->teacher, $this->planning->id)?->code)->toBe('20271')
        ->and($terms->selected($actor, $this->teacher, '')?->code)->toBe('20263')
        ->and($terms->selected($actor, null, $this->planning->id)?->code)->toBe('20263')
        ->and($terms->selected($actor, $this->teacher, $this->running->id)?->code)->toBe('20263');
});
