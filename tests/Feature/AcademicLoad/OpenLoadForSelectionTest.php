<?php

use App\Modules\AcademicLoad\Actions\Transitions\OpenLoadForSelection;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| OpenLoadForSelection (SEL-11): abre la carga a la asignación desde borrador, propuesta o ajustes sin pasar por HC-14.
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
});

it('SEL-11: abre a asignación desde borrador, propuesta y ajustes con su historial y bitácora', function (LoadStatus $from) {
    $load = AcademicLoad::factory()->inStatus($from)->create();

    app(OpenLoadForSelection::class)->handle($load, $this->user);

    expect($load->fresh()->status)->toBe(LoadStatus::Staffing);
    $row = $load->transitions()->firstOrFail();
    expect($row->from_status)->toBe($from)
        ->and($row->to_status)->toBe(LoadStatus::Staffing)
        ->and($row->comment)->toBe('Orden de selección');
    expect(Activity::query()->where('event', 'transitioned')->where('subject_id', $load->id)->count())->toBe(1);
})->with([LoadStatus::Draft, LoadStatus::Proposed, LoadStatus::Adjusting]);

it('no hace nada si la carga ya está en asignación', function () {
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create();

    app(OpenLoadForSelection::class)->handle($load, $this->user);

    expect($load->transitions()->count())->toBe(0);
});

it('rechaza una carga cerrada, aprobada, publicada o en re-análisis', function (LoadStatus $from) {
    $load = AcademicLoad::factory()->inStatus($from)->create();

    expect(fn () => app(OpenLoadForSelection::class)->handle($load, $this->user))->toThrow(ValidationException::class);
    expect($load->fresh()->status)->toBe($from);
})->with([LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published, LoadStatus::Reanalysis]);

it('opensForSelection es verdadero solo para borrador, propuesta y ajustes', function () {
    $open = collect(LoadStatus::cases())->filter->opensForSelection()->values()->all();

    expect($open)->toBe([LoadStatus::Draft, LoadStatus::Proposed, LoadStatus::Adjusting]);
});
