<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\MyLoadQuery;
use App\Modules\Staffing\Queries\TeacherSummary;
use App\Modules\Staffing\Queries\TeacherSummaryQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| TeacherSummaryQuery (1.85, IN-01 e IN-03): el tablero del Resumen del docente. Cuadra con Mi carga (mismas horas, tope,
| banda y grupos del mismo periodo), cambia con el reloj y solo lee la ficha ligada al usuario.
*/

const TZ = 'America/Ciudad_Juarez';

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-26 12:10', TZ));
    $this->planning = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->running = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->dept = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->teacher = Teacher::factory()->adscribedTo($this->dept)->create(['name' => 'Laura Pérez']);
    TeacherPosition::factory()->tiempoCompleto()->create(['teacher_id' => $this->teacher->id]);
    $this->user = User::factory()->withRole(Role::Docente)->create();
    $this->teacher->update(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

afterEach(fn () => Carbon::setTestNow());

function tsSummary(?CarbonImmutable $at = null): ?TeacherSummary
{
    if ($at !== null) {
        Carbon::setTestNow($at);
    }

    app(Actor::class)->refresh();

    return app(TeacherSummaryQuery::class)->for(app(Actor::class), CarbonImmutable::now(TZ));
}

function tsGroup(Term $term, string $code, array $sessions, ?Teacher $teacher = null, array $subject = []): Group
{
    $group = Group::factory()->create([
        'term_id' => $term->id,
        'campus_id' => test()->campus->id,
        'subject_id' => Subject::factory()->create(['department_id' => test()->dept->id, 'theory_hours' => 4, 'practice_hours' => 0, ...$subject])->id,
        'code' => $code,
        'teacher_id' => ($teacher ?? test()->teacher)->id,
    ]);

    foreach ($sessions as [$day, $from, $to]) {
        GroupSession::factory()->on($day, $from, $to)->create(['group_id' => $group->id]);
    }

    return $group;
}

function tsHidden(): void
{
    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING), false);
}

it('sin ficha de docente ligada no hay tablero', function () {
    $this->teacher->update(['user_id' => null]);

    expect(tsSummary())->toBeNull();
});

it('Review Focus 1: horas, tope, banda y grupos del periodo en planeación son los de Mi carga', function () {
    tsGroup($this->planning, 'A', [[1, '07:00', '09:00']]);
    tsGroup($this->planning, 'B', [[2, '09:00', '11:00']]);

    $summary = tsSummary();
    $mine = app(MyLoadQuery::class)->forTeacher($this->teacher, $this->planning);

    expect($summary->planning['hours'])->toBe($mine['hours'])
        ->and(array_column($summary->planning['groups'], 'id'))->toBe(array_column($mine['groups'], 'id'))
        ->and($summary->planning['offered'])->toBeTrue()
        ->and($summary->tiles['planning'])->toMatchArray([
            'assigned' => $mine['hours']['assigned'], 'limit' => $mine['hours']['limit'],
            'band_min' => $mine['hours']['band_from'], 'band_max' => $mine['hours']['band_to'], 'over_band' => $mine['hours']['over_band'],
        ])
        ->and($summary->tiles['remaining']['hours'])->toBe($mine['hours']['remaining']);
});

it('Review Focus 1 con 1.83: sin plaza docente cuadra con Mi carga y dice lo mismo', function () {
    $noPlaza = Teacher::factory()->adscribedTo($this->dept)->create();
    $this->teacher->positions()->delete();
    tsGroup($this->planning, 'A', [[1, '07:00', '09:00']]);

    $summary = tsSummary();
    $mine = app(MyLoadQuery::class)->forTeacher($this->teacher, $this->planning);

    expect($mine['hours']['no_plaza'])->toBeTrue()
        ->and($summary->planning['hours'])->toBe($mine['hours'])
        ->and($summary->tiles['planning'])->toMatchArray(['no_plaza' => true, 'limit' => $mine['hours']['limit'], 'limit_label' => 'Sin plaza docente'])
        ->and($summary->tiles['remaining']['caption'])->toBe(TeacherHourLimit::NO_PLAZA_LABEL)
        ->and($noPlaza->id)->not->toBe($this->teacher->id);
});

describe('hoy, con el reloj en jueves 26 de noviembre de 2026, 12:10', function () {
    beforeEach(function () {
        tsGroup($this->running, 'A', [[4, '07:00', '09:00']]);
        tsGroup($this->running, 'B', [[4, '12:00', '13:00']]);
        tsGroup($this->running, 'C', [[4, '16:00', '18:00']]);
        tsGroup($this->running, 'D', [[4, '19:00', '20:00']]);
    });

    it('marca la que terminó, la que corre, la siguiente con su cuenta y la que sigue', function () {
        $today = tsSummary()->today;

        expect($today['state'])->toBe('classes')
            ->and(array_column($today['items'], 'status'))->toBe(['done', 'now', 'next', 'later'])
            ->and($today['items'][2]['starts_in'])->toBe('en 3 h 50 min')
            ->and($today['items'][3]['gap_label'])->toBe('Hueco de 1 h')
            ->and($today['date_label'])->toBe('jueves 26 de noviembre');
    });

    it('resume las cifras: cuántas, y «ahora» con su hora de fin', function () {
        $tiles = tsSummary()->tiles;

        expect($tiles['today_count'])->toBe(4)
            ->and($tiles['today_caption'])->toStartWith('ahora: ')->toEndWith('hasta las 13:00');
    });

    it('antes de la siguiente dice a qué hora es y, pasadas todas, «ya terminaron»', function () {
        expect(tsSummary(CarbonImmutable::parse('2026-11-26 13:30', TZ))->tiles['today_caption'])->toBe('la siguiente a las 16:00')
            ->and(tsSummary(CarbonImmutable::parse('2026-11-26 20:30', TZ))->tiles['today_caption'])->toBe('ya terminaron');
    });

    it('sin clases hoy da la próxima: «El lunes» a su hora y materia', function () {
        tsGroup($this->running, 'L', [[1, '09:00', '11:00']], null, ['name' => 'Programación Estructurada']);

        $summary = tsSummary(CarbonImmutable::parse('2026-11-27 10:00', TZ));

        expect($summary->today['state'])->toBe('none')
            ->and($summary->today['next'])->toBe(['day_label' => 'El lunes', 'time' => '9:00', 'subject' => 'Programación Estructurada'])
            ->and($summary->tiles['today_caption'])->toBe('sin clases hoy');
    });
});

it('entre semestres: el periodo terminó, empieza el siguiente y la semana es la del periodo en planeación, provisional', function () {
    tsGroup($this->planning, 'A', [[1, '07:00', '09:00'], [3, '07:00', '09:00']]);

    $summary = tsSummary(CarbonImmutable::parse('2026-12-15 10:00', TZ));

    expect($summary->today['state'])->toBe('between_terms')
        ->and($summary->today['ended']['term'])->toBe('Ago-Dic 2026')
        ->and($summary->today['ended']['ended_on']->toDateString())->toBe('2026-12-11')
        ->and($summary->today['upcoming']['term'])->toBe('Ene-Jun 2027')
        ->and($summary->today['upcoming']['starts_on']->toDateString())->toBe('2027-01-18')
        ->and($summary->tiles['today_caption'])->toBe('sin clases')
        ->and($summary->week)->toMatchArray(['term_name' => 'Ene-Jun 2027', 'title' => 'Tu semana desde el 18 de enero', 'provisional' => true])
        ->and(array_column($summary->week['days'], 'today'))->each->toBeFalse();
});

describe('atención', function () {
    it('sin nada que atender está vacía', function () {
        expect(tsSummary()->attention)->toBe([]);
    });

    it('cada renglón sale solo cuando aplica, y con todo aplicando salen 5 en orden', function () {
        // Materias fuera de su experiencia, sobre su banda (plaza de 4 h), dos solicitudes y su turno.
        $groups = [];
        foreach (range(1, 4) as $n) {
            $groups[] = tsGroup($this->planning, "G{$n}", [[$n, '07:00', '09:00']]);
        }
        $this->teacher->positions()->delete();
        TeacherPosition::factory()->asignatura(4)->create(['teacher_id' => $this->teacher->id]);
        foreach ([0, 1] as $index) {
            ChangeRequest::factory()->forGroup($groups[$index])->pending()->create(['requested_by' => $this->user->id, 'teacher_id' => $this->teacher->id]);
        }
        $selection = Selection::factory()->create(['term_id' => $this->planning->id, 'department_id' => $this->dept->id, 'status' => SelectionStatus::Published]);
        SelectionEntry::factory()->create(['selection_id' => $selection->id, 'teacher_id' => $this->teacher->id, 'position' => 14, 'slot_starts_at' => '2026-12-08 10:30', 'slot_ends_at' => '2026-12-08 11:00']);

        $attention = tsSummary()->attention;

        expect($attention)->toHaveCount(5)
            ->and(array_column($attention, 'kind'))->toBe(['selection_turn', 'hours', 'request', 'request', 'experience'])
            ->and($attention[0]['title'])->toBe('Tu turno: martes 8 de diciembre, 10:30–11:00')
            ->and($attention[0]['detail'])->toBe('Lugar 14 de 1 · Sistemas y Computación')
            ->and($attention[1]['tone'])->toBe('warning')
            ->and(tsSummary()->planning['badge'])->toBe('selection');
    });

    it('en su turno lo dice con la hora de fin', function () {
        tsGroup($this->planning, 'A', [[1, '07:00', '09:00']]);
        $selection = Selection::factory()->create(['term_id' => $this->planning->id, 'department_id' => $this->dept->id, 'status' => SelectionStatus::Running]);
        SelectionEntry::factory()->create(['selection_id' => $selection->id, 'teacher_id' => $this->teacher->id, 'position' => 1, 'slot_starts_at' => '2026-11-26 12:00', 'slot_ends_at' => '2026-11-26 12:30']);

        $first = tsSummary()->attention[0];

        expect($first['title'])->toBe('Es tu turno, hasta las 12:30')->and($first['tone'])->toBe('info');
    });

    it('solicitudes: la abierta sí, la rechazada hace 3 días sí con su comentario y la aplicada hace 10 días no', function () {
        $group = tsGroup($this->planning, 'A', [[1, '07:00', '09:00']]);
        $mk = fn (?Group $on = null) => ChangeRequest::factory()->forGroup($on ?? $group)->create(['requested_by' => $this->user->id, 'teacher_id' => $this->teacher->id]);
        $mk(tsGroup($this->planning, 'B', [[2, '07:00', '09:00']]))->forceFill(['status' => 'pending'])->save();
        $mk()->forceFill(['status' => 'rejected', 'decided_at' => now()->subDays(3), 'decision_comment' => 'No hay otro horario disponible.'])->save();
        $mk(tsGroup($this->planning, 'C', [[3, '07:00', '09:00']]))->forceFill(['status' => 'applied', 'decided_at' => now()->subDays(11), 'applied_at' => now()->subDays(10)])->save();
        TeacherSubjectQualification::factory()->for($this->teacher)->for($group->subject)->create();

        $rows = array_values(array_filter(tsSummary()->attention, fn (array $row): bool => $row['kind'] === 'request'));

        expect($rows)->toHaveCount(2)
            ->and(collect($rows)->pluck('detail')->all())->toContain('No hay otro horario disponible.')
            ->and(collect($rows)->pluck('title')->join(' | '))->not->toContain('aplicada');
    });

    it('las materias fuera de su experiencia solo salen si la carga las trae', function () {
        $group = tsGroup($this->planning, 'A', [[1, '07:00', '09:00']]);

        expect(array_column(tsSummary()->attention, 'kind'))->toBe(['experience']);

        TeacherSubjectQualification::factory()->for($this->teacher)->for($group->subject)->create();

        expect(tsSummary()->attention)->toBe([]);
    });
});

it('experiencia: 40 materias dan 6 con «y 34 más»', function () {
    TeacherSubjectQualification::factory()->count(40)->for($this->teacher)->create();

    $experience = tsSummary()->experience;

    expect($experience['total'])->toBe(40)
        ->and($experience['subjects'])->toHaveCount(6)
        ->and($experience['rest'])->toHaveCount(34)
        ->and($experience['more'])->toBe(34);
});

it('disponibilidad: sin excepciones es todo el horario y con ellas lista sus rangos', function () {
    $all = tsSummary()->availability;

    expect($all['all_available'])->toBeTrue()->and($all['term_name'])->toBe('Ene-Jun 2027')->and($all['available'])->toBe($all['total']);

    TeacherAvailability::factory()->create(['teacher_id' => $this->teacher->id, 'term_id' => $this->planning->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => AvailabilityKind::Unavailable]);

    $some = tsSummary()->availability;

    expect($some['all_available'])->toBeFalse()
        ->and($some['days'][0]['ranges'][0])->toMatchArray(['label' => '7:00–9:00', 'kind' => 'unavailable'])
        ->and($some['available'])->toBeLessThan($some['total']);
});

it('MN-05: con la carga oculta no sale el periodo en planeación ni lo que depende de él', function () {
    tsGroup($this->planning, 'A', [[1, '07:00', '09:00']]);
    tsGroup($this->running, 'B', [[4, '12:00', '13:00']]);
    tsHidden();

    $summary = tsSummary();

    expect($summary->planning)->toBeNull()
        ->and($summary->tiles['planning'])->toBeNull()
        ->and($summary->tiles['remaining'])->toBeNull()
        ->and($summary->attention)->toBe([])
        ->and($summary->today['items'])->toHaveCount(1);
});

it('alcance: nada de otro docente aparece en su tablero', function () {
    $other = Teacher::factory()->adscribedTo($this->dept)->create();
    tsGroup($this->planning, 'OTRO', [[1, '07:00', '09:00']], $other, ['name' => 'Materia Ajena']);
    TeacherSubjectQualification::factory()->for($other)->create();
    TeacherAvailability::factory()->create(['teacher_id' => $other->id, 'term_id' => $this->planning->id, 'day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => AvailabilityKind::Unavailable]);

    $summary = tsSummary();

    expect($summary->planning['groups'])->toBe([])
        ->and($summary->experience['total'])->toBe(0)
        ->and($summary->availability['all_available'])->toBeTrue()
        ->and($summary->identity['name'])->toBe('Laura Pérez')
        ->and($summary->account['username'])->toBe($this->user->username);
});

it('hace un número constante de consultas con 1 o con 6 grupos', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        tsSummary();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $add = function (int $n): void {
        $group = tsGroup($this->planning, "P{$n}", [[$n, '10:00', '12:00'], [$n, '14:00', '16:00']]);
        tsGroup($this->running, "R{$n}", [[4, (10 + $n).':00', (11 + $n).':00']]);
        ChangeRequest::factory()->forGroup($group)->create(['requested_by' => $this->user->id, 'teacher_id' => $this->teacher->id]);
        TeacherSubjectQualification::factory()->for($this->teacher)->create();
    };

    $add(1);
    tsSummary(); // calienta lo que el Actor y los ajustes guardan en la petición
    $one = $count();

    foreach (range(2, 6) as $n) {
        $add($n);
    }

    expect($count())->toBe($one);
});
