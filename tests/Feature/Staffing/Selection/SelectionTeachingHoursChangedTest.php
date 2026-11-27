<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Events\TeachingHoursChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Jobs\RecomputeScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\Selection\CancelSelection;
use App\Modules\Staffing\Actions\Selection\ImportSelectionCsv;
use App\Modules\Staffing\Actions\Selection\ResolveSelectionEntry;
use App\Modules\Staffing\Jobs\ComputeRankings;
use App\Modules\Staffing\Jobs\RefreshTeacherLoads;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
| HC-08 (tarea 1.50): las horas del CSV de la selección son el respaldo de las horas frente a grupo de quien no tiene
| plazas que las den, así que importar el CSV, resolver un renglón y cancelar la selección avisan con
| TeachingHoursChanged, y con él se recalculan los choques guardados (Scheduling), la carga por docente y el ranking
| (Staffing).
*/

const HOURS_CSV_HEADER = "Nombre completo;horas;auto ev;cumpl asig;C1;C2;antiguedad\n";

beforeEach(function () {
    $this->actor = User::factory()->admin()->create();
    $this->selection = Selection::factory()->create(['department_id' => Department::factory()->create()->id]);
    $this->ana = Teacher::factory()->adscribedTo($this->selection->department)->create(['name' => 'Ana Pérez']);
});

it('HC-08: importar el CSV, resolver un renglón y cancelar la selección avisan que cambiaron las horas frente a grupo', function () {
    Event::fake([TeachingHoursChanged::class]);

    app(ImportSelectionCsv::class)->handle($this->selection, HOURS_CSV_HEADER."Ana Pérez;30;si;si;si;si;10\nNombre Desconocido;20;si;no;si;si;3\n", $this->actor);

    Event::assertDispatched(TeachingHoursChanged::class, fn (TeachingHoursChanged $event): bool => $event->termId === $this->selection->term_id && $event->teacherIds === [$this->ana->id]);

    $beto = Teacher::factory()->adscribedTo($this->selection->department)->create(['name' => 'Beto Ruiz']);
    app(ResolveSelectionEntry::class)->handle($this->selection->entries()->whereNull('teacher_id')->sole(), $beto, $this->actor);

    Event::assertDispatched(TeachingHoursChanged::class, fn (TeachingHoursChanged $event): bool => $event->teacherIds === [$beto->id]);

    app(CancelSelection::class)->handle($this->selection->fresh(), $this->actor);

    Event::assertDispatched(TeachingHoursChanged::class, fn (TeachingHoursChanged $event): bool => collect($event->teacherIds)->sort()->values()->all() === collect([$this->ana->id, $beto->id])->sort()->values()->all());
    Event::assertDispatchedTimes(TeachingHoursChanged::class, 3);
});

it('HC-08: un CSV sin docentes empatados no avisa', function () {
    Event::fake([TeachingHoursChanged::class]);

    app(ImportSelectionCsv::class)->handle($this->selection, HOURS_CSV_HEADER."Nombre Desconocido;20;si;no;si;si;3\n", $this->actor);

    Event::assertNotDispatched(TeachingHoursChanged::class);
});

it('HC-08: con el aviso se recalculan los choques guardados, la carga por docente y el ranking del periodo', function () {
    $group = Group::factory()->create(['term_id' => $this->selection->term_id, 'teacher_id' => $this->ana->id]);
    AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['term_id' => $group->term_id, 'modality_id' => $group->modality_id, 'department_id' => $group->department_id]);
    $this->ana->adscriptions()->update(['department_id' => $group->department_id]);
    Queue::fake();

    event(new TeachingHoursChanged($this->selection->term_id, [$this->ana->id]));

    Queue::assertPushed(RecomputeScheduleConflicts::class, fn (RecomputeScheduleConflicts $job): bool => $job->termId === $group->term_id && $job->modalityId === $group->modality_id);
    Queue::assertPushed(RefreshTeacherLoads::class, fn (RefreshTeacherLoads $job): bool => $job->termId === $group->term_id);
    Queue::assertPushed(ComputeRankings::class, fn (ComputeRankings $job): bool => $job->departmentId === $group->department_id);
});
