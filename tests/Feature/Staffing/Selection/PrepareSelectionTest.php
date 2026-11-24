<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\Selection\CreateSelection;
use App\Modules\Staffing\Actions\Selection\ImportSelectionCsv;
use App\Modules\Staffing\Actions\Selection\ResolveSelectionEntry;
use App\Modules\Staffing\Actions\Selection\ScheduleSelection;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Models\Selection;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Preparar una selección (tarea 1.33, diseño 6.2, 6.3 y 6.12): crearla con sus cargas, importar el CSV, resolver los
| nombres sin empatar y programar la ventana de turnos.
*/

const PREP_HEADER = "Nombre completo;horas;auto ev;cumpl asig;C1;C2;antiguedad\n";

function prepSelection(): Selection
{
    $department = Department::factory()->create();
    $selection = Selection::factory()->create(['department_id' => $department->id]);

    return $selection;
}

function prepTeacher(Selection $selection, string $name, ?string $hiredOn = null): Teacher
{
    return Teacher::factory()->adscribedTo($selection->department)->create(['name' => $name, 'hired_on' => $hiredOn]);
}

beforeEach(function () {
    $this->actor = User::factory()->admin()->create();
});

it('incluye por defecto la carga de CEA con grupos de ISC', function () {
    $term = Term::factory()->create();
    $campus = Campus::factory()->create();
    $modality = Modality::factory()->create();
    $isc = Department::factory()->create();
    $cea = Department::factory()->create();
    $career = Career::factory()->create(['department_id' => $isc->id]);
    $otherCareer = Career::factory()->create(['department_id' => Department::factory()]);
    $ownLoad = AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $isc->id]);
    $ceaLoad = AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $cea->id]);
    $strangerDepartment = Department::factory()->create();
    $strangerLoad = AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $strangerDepartment->id]);
    $ceaSubject = Subject::factory()->create(['department_id' => $cea->id]);
    $strangerSubject = Subject::factory()->create(['department_id' => $strangerDepartment->id]);
    Group::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'modality_id' => $modality->id, 'subject_id' => $ceaSubject->id, 'owner_career_id' => $career->id]);
    Group::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'modality_id' => $modality->id, 'subject_id' => $strangerSubject->id, 'owner_career_id' => $otherCareer->id]);

    $selection = app(CreateSelection::class)->handle($term, $modality, $isc, $campus, $this->actor);

    expect($selection->loads()->pluck('academic_loads.id')->all())->toEqualCanonicalizing([$ownLoad->id, $ceaLoad->id])
        ->and($selection->loads()->pluck('academic_loads.id')->all())->not->toContain($strangerLoad->id)
        ->and($selection->status->value)->toBe('draft')
        ->and($selection->created_by)->toBe($this->actor->id)
        ->and(Activity::query()->where('event', 'selection_created')->count())->toBe(1);
});

it('no crea una segunda selección viva del mismo periodo, modalidad, departamento y campus', function () {
    $selection = Selection::factory()->create();

    app(CreateSelection::class)->handle($selection->term, $selection->modality, $selection->department, $selection->campus, $this->actor);
})->throws(ValidationException::class);

it('SEL-10: importar agrega al final a los docentes del departamento que no vienen en el CSV', function () {
    $selection = prepSelection();
    $ana = prepTeacher($selection, 'Ana Pérez');
    $luis = prepTeacher($selection, 'Luis Gómez', '2010-01-01');
    $outsider = Teacher::factory()->create(['name' => 'Otro Departamento']);
    $inactive = Teacher::factory()->adscribedTo($selection->department)->inactive()->create();

    $result = app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."ana perez;40;si;si;si;si;12,5\n", $this->actor);

    expect($result['matched'])->toBe(1)
        ->and($result['unresolved'])->toBe([])
        ->and($result['errors'])->toBe([]);
    $entries = $selection->entries()->orderBy('position')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->teacher_id)->toBe($ana->id)
        ->and($entries[0]->source)->toBe(SelectionEntrySource::Csv)
        ->and($entries[0]->tier)->toBe(0)
        ->and($entries[0]->position)->toBe(1)
        ->and($entries[1]->teacher_id)->toBe($luis->id)
        ->and($entries[1]->source)->toBe(SelectionEntrySource::Appended)
        ->and($entries[1]->position)->toBe(2)
        ->and($entries[1]->slot_index)->toBe(1)
        ->and($selection->entries()->whereIn('teacher_id', [$outsider->id, $inactive->id])->count())->toBe(0);
});

it('un nombre sin empatar bloquea publicar hasta resolverlo', function () {
    $selection = prepSelection();
    $ana = prepTeacher($selection, 'Ana Pérez');

    $result = app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Nombre Desconocido;20;si;no;si;si;3\n", $this->actor);

    expect($result['unresolved'])->toHaveCount(1)
        ->and($result['unresolved'][0]['line'])->toBe(2)
        ->and($result['unresolved'][0]['name'])->toBe('Nombre Desconocido');
    $entry = $selection->entries()->where('source', 'csv')->firstOrFail();
    expect($entry->teacher_id)->toBeNull()
        ->and($entry->failed_criteria)->toBe(1)
        ->and($entry->tier)->toBe(3);

    app(ResolveSelectionEntry::class)->handle($entry, $ana, $this->actor);

    $entry->refresh();
    expect($entry->teacher_id)->toBe($ana->id)
        // Ana ya no se agrega al final: su renglón del CSV la reemplaza.
        ->and($selection->entries()->count())->toBe(1);
    expect(Activity::query()->where('event', 'selection_entry_resolved')->count())->toBe(1);
});

it('dos renglones del CSV del mismo docente quedan sin resolver los dos', function () {
    $selection = prepSelection();
    $ana = prepTeacher($selection, 'Ana Pérez');

    $result = app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Ana Pérez;40;si;si;si;si;10\nANA PEREZ;40;si;si;si;si;3\n", $this->actor);

    expect($result['matched'])->toBe(0)
        ->and($result['unresolved'])->toHaveCount(2)
        ->and(collect($result['unresolved'])->pluck('line')->all())->toBe([2, 3])
        ->and($result['unresolved'][0]['candidates'])->toBe([$ana->id]);
    expect($selection->entries()->whereNull('teacher_id')->count())->toBe(2);
});

it('descartar un renglón sin empatar lo quita de la cola', function () {
    $selection = prepSelection();
    app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Nombre Desconocido;20;si;no;si;si;3\n", $this->actor);
    $entry = $selection->entries()->firstOrFail();

    app(ResolveSelectionEntry::class)->handle($entry, null, $this->actor);

    expect($selection->entries()->count())->toBe(0);
});

it('no resuelve un renglón con un docente que ya tiene el suyo', function () {
    $selection = prepSelection();
    $ana = prepTeacher($selection, 'Ana Pérez');
    app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Ana Pérez;40;si;si;si;si;10\nZulema;40;si;si;si;si;3\n", $this->actor);
    $stray = $selection->entries()->whereNull('teacher_id')->firstOrFail();

    app(ResolveSelectionEntry::class)->handle($stray, $ana, $this->actor);
})->throws(ValidationException::class);

it('un CSV con errores no cambia la cola y los reporta', function () {
    $selection = prepSelection();
    prepTeacher($selection, 'Ana Pérez');
    app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Ana Pérez;40;si;si;si;si;10\n", $this->actor);

    $result = app(ImportSelectionCsv::class)->handle($selection, "basura\n", $this->actor);

    expect($result['errors'])->not->toBe([])
        ->and($selection->entries()->where('source', 'csv')->count())->toBe(1);
});

it('programar guarda la ventana y calcula los turnos de cada renglón', function () {
    $selection = prepSelection();
    prepTeacher($selection, 'Ana Pérez');
    prepTeacher($selection, 'Luis Gómez');
    app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Ana Pérez;40;si;si;si;si;10\nLuis Gómez;40;si;si;si;si;3\n", $this->actor);
    $start = CarbonImmutable::parse('2027-01-11 09:00', 'America/Ciudad_Juarez');

    app(ScheduleSelection::class)->handle($selection, $start, '09:00', '14:00', [1, 2, 3, 4, 5], $this->actor);

    $selection->refresh();
    $entries = $selection->entries()->orderBy('position')->get();
    expect($selection->starts_at->equalTo($start))->toBeTrue()
        ->and($selection->window_ends)->toStartWith('14:00')
        ->and($selection->weekdays)->toBe([1, 2, 3, 4, 5])
        ->and($selection->first_round_ends_at->equalTo($start->addMinutes(60)))->toBeTrue()
        ->and($entries[0]->slot_starts_at->equalTo($start))->toBeTrue()
        ->and($entries[1]->slot_starts_at->equalTo($start->addMinutes(30)))->toBeTrue()
        ->and($entries[1]->slot_ends_at->equalTo($start->addMinutes(60)))->toBeTrue();
});

it('un nombre que solo empata con un docente de otro departamento queda sin resolver', function () {
    $selection = prepSelection();
    Teacher::factory()->adscribedTo(Department::factory()->create())->create(['name' => 'Persona Ajena']);

    $result = app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Persona Ajena;40;si;si;si;si;5\n", $this->actor);

    expect($result['matched'])->toBe(0)
        ->and($result['unresolved'])->toHaveCount(1)
        ->and($selection->entries()->whereNotNull('teacher_id')->count())->toBe(0);
});

it('descartar los dos renglones duplicados agrega al docente al final', function () {
    $selection = prepSelection();
    $ana = prepTeacher($selection, 'Ana Pérez');
    $result = app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Ana Pérez;40;si;si;si;si;10\nANA PEREZ;40;si;si;si;si;3\n", $this->actor);
    expect($selection->entries()->where('teacher_id', $ana->id)->count())->toBe(0);

    foreach ($result['unresolved'] as $row) {
        app(ResolveSelectionEntry::class)->handle($selection->entries()->findOrFail($row['entry_id']), null, $this->actor);
    }

    $entry = $selection->entries()->where('teacher_id', $ana->id)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->source)->toBe(SelectionEntrySource::Appended);
});

it('resolver exige un docente activo y adscrito al departamento', function (string $kind) {
    $selection = prepSelection();
    app(ImportSelectionCsv::class)->handle($selection, PREP_HEADER."Zulema;40;si;si;si;si;3\n", $this->actor);
    $entry = $selection->entries()->firstOrFail();
    $teacher = match ($kind) {
        'otro departamento' => Teacher::factory()->adscribedTo(Department::factory()->create())->create(),
        'inactivo' => Teacher::factory()->adscribedTo($selection->department)->inactive()->create(),
    };

    expect(fn () => app(ResolveSelectionEntry::class)->handle($entry, $teacher, $this->actor))->toThrow(ValidationException::class);
    expect($entry->fresh()->teacher_id)->toBeNull();
})->with(['otro departamento', 'inactivo']);
