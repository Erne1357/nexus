<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherEvaluation;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Queries\TeacherRecordQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
| La ficha de un docente (TeacherRecordQuery): adscripciones con la principal primero, plazas con su estado, las
| horas base del periodo activo (S1 y S2, supuestos por confirmar: plazas vigentes el primer día, sin tope en la
| suma) y su usuario. Solo lectura.
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->ii = Department::factory()->create(['code' => 'II']);
    $this->term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->user = User::factory()->create(['username' => '1000001']);
    $this->ana = Teacher::factory()->adscribedTo($this->ii)->create(['employee_number' => '1000001', 'user_id' => $this->user->id]);
    TeacherDepartment::factory()->for($this->ana)->for($this->sc)->create(['is_primary' => false]);
});

it('trae las adscripciones, sin campus, con la principal primero y su departamento', function () {
    $record = app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect($record->adscriptions->pluck('is_primary')->all())->toBe([true, false])
        ->and($record->adscriptions->first()->department->code)->toBe('II');
});

it('S1 y S2: las horas base del periodo suman las plazas vigentes el primer día, sin tope', function () {
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->ana)->asignatura(10)->create(['valid_from' => '2027-01-25', 'valid_to' => '2027-06-30']);
    TeacherPosition::factory()->for($this->ana)->asignatura(7)->create(['valid_from' => '2027-01-26']);
    TeacherPosition::factory()->for($this->ana)->expired()->create();

    $record = app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect($record->baseHours)->toBe(50)
        ->and($record->term->name)->toBe('Ene-Jun 2027');
});

it('sin periodo activo no hay horas base', function () {
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();

    expect(app(TeacherRecordQuery::class)->for($this->ana, null)->baseHours)->toBeNull();
});

it('marca cada plaza como vigente, por empezar o vencida, y si cuenta en el periodo', function () {
    $current = TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    $future = TeacherPosition::factory()->for($this->ana)->future()->create();
    $expired = TeacherPosition::factory()->for($this->ana)->expired()->create();

    $record = app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect($record->positionStatus)->toBe([$current->id => 'vigente', $future->id => 'por_empezar', $expired->id => 'vencida'])
        ->and($record->countsInTerm[$current->id])->toBeTrue()
        ->and($record->countsInTerm[$expired->id])->toBeFalse()
        ->and($record->positions->pluck('id')->all())->toBe([$current->id, $future->id, $expired->id]);
});

it('deja fuera las plazas y adscripciones quitadas', function () {
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create()->delete();
    $this->ana->adscriptions()->where('is_primary', false)->sole()->delete();

    $record = app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect($record->positions)->toBeEmpty()
        ->and($record->adscriptions)->toHaveCount(1)
        ->and($record->baseHours)->toBe(0);
});

it('trae su usuario, o null si no tiene', function () {
    $without = Teacher::factory()->adscribedTo($this->sc)->create();

    expect(app(TeacherRecordQuery::class)->for($this->ana, $this->term)->user->username)->toBe('1000001')
        ->and(app(TeacherRecordQuery::class)->for($without, $this->term)->user)->toBeNull();
});

it('busca una adscripción o una plaza del docente por el id del navegador y no una de otro', function () {
    $query = app(TeacherRecordQuery::class);
    $position = TeacherPosition::factory()->for($this->ana)->create();
    $other = Teacher::factory()->adscribedTo($this->sc)->create();
    $otherAdscription = $other->adscriptions()->sole();

    expect($query->findAdscription($this->ana, $this->ana->adscriptions()->first()->id))->not->toBeNull()
        ->and($query->findPosition($this->ana, $position->id)->id)->toBe($position->id)
        ->and(fn () => $query->findAdscription($this->ana, $otherAdscription->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->findPosition($this->ana, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
});

it('lee con pocas consultas', function () {
    TeacherPosition::factory()->for($this->ana)->count(3)->create();
    DB::enableQueryLog();

    app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
});

describe('perfil, horas fuera del ITCJ e historial', function () {
    it('el perfil trae las materias con su departamento, preferentes primero y luego por clave, sin las quitadas', function () {
        $b = Subject::factory()->create(['code' => 'SCD-2', 'department_id' => $this->sc->id]);
        $a = Subject::factory()->create(['code' => 'SCD-1', 'department_id' => $this->sc->id]);
        $gone = Subject::factory()->create(['code' => 'SCD-0', 'department_id' => $this->sc->id]);
        TeacherSubjectQualification::factory()->for($this->ana)->for($b)->preferente()->create();
        TeacherSubjectQualification::factory()->for($this->ana)->for($a)->create();
        TeacherSubjectQualification::factory()->for($this->ana)->for($gone)->create(['deleted_at' => now()]);
        TeacherSubjectQualification::factory()->for(Teacher::factory())->for($a)->create();

        $profile = app(TeacherRecordQuery::class)->profile($this->ana);

        expect($profile->map(fn ($q) => $q->subject->code)->all())->toBe(['SCD-2', 'SCD-1'])
            ->and($profile->first()->subject->department->code)->toBe('SC')
            ->and($profile->pluck('taught_count')->all())->toBe([0, 0]);
    });

    it('las horas fuera del ITCJ son las del periodo, por origen, sin las quitadas', function () {
        $other = Term::factory()->create();
        TeacherExternalLoad::factory()->for($this->ana)->for($this->term)->create(['source' => 'TecNM Virtual', 'hours' => 6]);
        TeacherExternalLoad::factory()->for($this->ana)->for($this->term)->create(['source' => 'Anáhuac', 'hours' => 4]);
        TeacherExternalLoad::factory()->for($this->ana)->for($this->term)->create(['source' => 'Quitada', 'hours' => 4, 'deleted_at' => now()]);
        TeacherExternalLoad::factory()->for($this->ana)->for($other)->create();

        $loads = app(TeacherRecordQuery::class)->externalLoads($this->ana, $this->term);

        expect($loads->pluck('source')->all())->toBe(['Anáhuac', 'TecNM Virtual'])
            ->and($loads->sum('hours'))->toBe(10);
    });

    it('el historial va del periodo más antiguo al más reciente, con sus grupos y evaluaciones por tipo', function () {
        $subject = Subject::factory()->create(['code' => 'SCD-1', 'name' => 'Programación']);
        $campus = Campus::factory()->create(['code' => 'C2']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20243', 'campus_id' => $campus->id, 'group_code' => '5B']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20241', 'campus_id' => $campus->id, 'group_code' => '3B']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20241', 'campus_id' => $campus->id, 'group_code' => '3A']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20251', 'campus_id' => $campus->id, 'group_code' => 'X', 'deleted_at' => now()]);
        TeacherEvaluation::factory()->for($this->ana)->estudiantil()->create(['term_code' => '20241', 'score' => 4.5]);
        TeacherEvaluation::factory()->for($this->ana)->departamental()->create(['term_code' => '20241', 'score' => 3.8]);
        TeacherEvaluation::factory()->for($this->ana)->estudiantil()->create(['term_code' => '20232', 'score' => 4.0]);
        TeacherEvaluation::factory()->for(Teacher::factory())->create(['term_code' => '20241']);

        $history = app(TeacherRecordQuery::class)->history($this->ana);

        expect($history->pluck('code')->all())->toBe(['20232', '20241', '20243'])
            ->and($history->pluck('label')->all())->toBe(['Verano 2023', 'Ene-Jun 2024', 'Ago-Dic 2024'])
            ->and($history[0]['groups'])->toHaveCount(0)
            ->and($history[1]['groups']->pluck('group_code')->all())->toBe(['3A', '3B'])
            ->and($history[1]['groups']->first()->subject->code)->toBe('SCD-1')
            ->and($history[1]['groups']->first()->campus->code)->toBe('C2')
            ->and($history[1]['evaluations']->mapWithKeys(fn ($e) => [$e->kind->value => (float) $e->score])->all())->toBe(['departamental' => 3.8, 'estudiantil' => 4.5])
            ->and($history[2]['evaluations'])->toHaveCount(0);
    });

    it('usa el nombre del periodo de Nexus si lo tiene', function () {
        Term::factory()->create(['code' => '20253', 'name' => 'Ago-Dic 2025 (Nexus)']);
        TeacherEvaluation::factory()->for($this->ana)->create(['term_code' => '20253']);

        expect(app(TeacherRecordQuery::class)->history($this->ana)->first()['label'])->toBe('Ago-Dic 2025 (Nexus)');
    });

    it('ofrece solo las materias de los departamentos dados, activas, buscadas por clave o nombre', function () {
        Subject::factory()->create(['code' => 'SCC-1', 'name' => 'Redes', 'department_id' => $this->sc->id]);
        Subject::factory()->create(['code' => 'SCC-2', 'name' => 'Bases de datos', 'department_id' => $this->sc->id]);
        Subject::factory()->create(['code' => 'SCC-3', 'name' => 'Inactiva', 'department_id' => $this->sc->id, 'is_active' => false]);
        Subject::factory()->create(['code' => 'CBC-1', 'name' => 'Redes de cálculo', 'department_id' => $this->ii->id]);
        $query = app(TeacherRecordQuery::class);

        expect($query->qualifiableSubjects([$this->sc->id], '')->pluck('code')->all())->toBe(['SCC-1', 'SCC-2'])
            ->and($query->qualifiableSubjects([$this->sc->id], 'redes')->pluck('code')->all())->toBe(['SCC-1'])
            ->and($query->qualifiableSubjects(null, 'redes')->pluck('code')->all())->toBe(['CBC-1', 'SCC-1'])
            ->and($query->qualifiableSubjects([], '')->all())->toBe([]);
    });

    it('busca un perfil y unas horas del docente por id, y no los de otro', function () {
        $query = app(TeacherRecordQuery::class);
        $qualification = TeacherSubjectQualification::factory()->for($this->ana)->create();
        $load = TeacherExternalLoad::factory()->for($this->ana)->create();
        $foreign = TeacherSubjectQualification::factory()->for(Teacher::factory())->create();

        expect($query->findQualification($this->ana, $qualification->id)->id)->toBe($qualification->id)
            ->and($query->findExternalLoad($this->ana, $load->id)->id)->toBe($load->id)
            ->and(fn () => $query->findQualification($this->ana, $foreign->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->findExternalLoad($this->ana, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
    });

    it('lee el historial de 8 periodos con pocas consultas', function () {
        $subject = Subject::factory()->create();
        $campus = Campus::factory()->create();

        foreach (['20231', '20232', '20233', '20241', '20242', '20243', '20251', '20252'] as $code) {
            TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->count(2)->create(['term_code' => $code, 'campus_id' => $campus->id]);
            TeacherEvaluation::factory()->for($this->ana)->estudiantil()->create(['term_code' => $code]);
            TeacherEvaluation::factory()->for($this->ana)->departamental()->create(['term_code' => $code]);
        }

        DB::enableQueryLog();
        $query = app(TeacherRecordQuery::class);
        $history = $query->history($this->ana);
        $profile = $query->profile($this->ana);
        $loads = $query->externalLoads($this->ana, $this->term);

        expect($history)->toHaveCount(8)
            ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(9);
    });
});
