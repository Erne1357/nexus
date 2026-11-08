<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use App\Modules\Staffing\Jobs\RefreshTeacherLoads;
use App\Modules\Staffing\Queries\TeacherLoadsBoardQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| «Carga por docente» (tarea 3.12, maqueta B): mosaicos por estado con las horas de todos los campus, el drawer del
| docente y «Calcular ahora». Una prueba negativa de alcance por rol.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
});

function boardTeacher(string $name, ?string $kind, ?Department $department = null): Teacher
{
    $teacher = Teacher::factory()->adscribedTo($department ?? test()->sc)->create(['name' => $name]);

    if ($kind !== null) {
        TeacherPosition::factory()->for($teacher)->{$kind}()->create();
    }

    return $teacher;
}

function boardGroup(Teacher $teacher, Campus $campus, int $hours, string $code): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id, 'campus_id' => $campus->id, 'teacher_id' => $teacher->id, 'code' => $code,
        'subject_id' => Subject::factory()->create(['theory_hours' => $hours, 'practice_hours' => 0, 'name' => "Materia {$code}"])->id,
    ]);
}

function boardSeed(): array
{
    $over = boardTeacher('Olga Excedida', 'medioTiempo');
    boardGroup($over, test()->c2, 15, 'A1');
    boardGroup($over, test()->c1, 8, 'A2');
    $none = boardTeacher('Nora Sinclase', 'tiempoCompleto');
    $part = boardTeacher('Pablo Libre', 'tiempoCompleto');
    boardGroup($part, test()->c2, 10, 'B1');
    $full = boardTeacher('Fabiola Completa', 'medioTiempo');
    boardGroup($full, test()->c2, 20, 'C1');
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.honorarios_max_hours', 'value' => 12]);
    $hon = boardTeacher('Hugo Honorarios', 'honorarios');
    boardGroup($hon, test()->c2, 4, 'D1');

    app(RefreshTeacherTermLoads::class)->handle(test()->term);

    return compact('over', 'none', 'part', 'full', 'hon');
}

function loadsPage(?User $user = null, array $query = [])
{
    return Livewire::withQueryParams($query)->actingAs($user ?? test()->jefe)->test('staffing::pages.teacher-loads');
}

function tilesIn(string $html, string $section): array
{
    $dom = domOf($html);
    $node = $dom->querySelector("[data-testid=section-{$section}]");

    return $node === null ? [] : array_map(fn ($tile) => textOf($tile->querySelector('.font-semibold')), iterator_to_array($node->querySelectorAll('[data-testid=tile]')));
}

describe('mosaicos', function () {
    it('pone a cada docente en su sección', function () {
        boardSeed();
        $html = loadsPage()->html();

        expect(tilesIn($html, 'over'))->toBe(['Olga Excedida'])
            ->and(tilesIn($html, 'none'))->toBe(['Nora Sinclase'])
            ->and(tilesIn($html, 'part'))->toBe(['Pablo Libre'])
            ->and(tilesIn($html, 'full'))->toBe(['Fabiola Completa'])
            ->and(tilesIn($html, 'hon'))->toBe(['Hugo Honorarios']);
    });

    it('HC-08: muestra las horas de más, las libres y suma todos los campus', function () {
        boardSeed();

        $figures = collect(domOf(loadsPage()->html())->querySelectorAll('[data-testid=tile-figure]'))->map(fn ($figure) => textOf($figure))->all();

        expect($figures)->toEqualCanonicalizing(['+3', '0 / 40', '30', '0', '4']);

        loadsPage()
            ->assertSeeHtml('data-testid="kpi-exceden"')
            ->assertSee('Todos los campus')
            ->assertSee('Sistemas y Computación')
            ->assertSee('Ene-Jun 2027');
    });

    it('HC-08: las horas de más llevan «+», nunca un signo menos', function () {
        boardSeed();
        $html = loadsPage()->html();
        $tile = domOf($html)->querySelector('[data-testid=section-over] [data-testid=tile]');

        expect(textOf($tile))->toContain('+3')->toContain('h de más')->not->toContain('−');
    });

    it('filtra por estado con el filtro segmentado y cuenta cada estado', function () {
        boardSeed();

        $page = loadsPage();
        $html = $page->html();

        expect(textOf(domOf($html)->querySelector('[data-testid=state-todos]')))->toContain('5')
            ->and(textOf(domOf($html)->querySelector('[data-testid=state-over]')))->toContain('1');

        $page->set('state', 'over')->assertSee('Olga Excedida')->assertDontSee('Pablo Libre')->assertDontSee('Hugo Honorarios');
        loadsPage(query: ['estado' => 'hon'])->assertSee('Hugo Honorarios')->assertDontSee('Olga Excedida');
        loadsPage()->set('state', 'inventado')->assertSee('Olga Excedida')->assertSee('Pablo Libre');
    });

    it('filtra por nombre o RFC y por plaza', function () {
        $seed = boardSeed();
        $seed['part']->update(['rfc' => 'PALP800101AAA']);

        loadsPage()->set('search', 'pablo')->assertSee('Pablo Libre')->assertDontSee('Olga Excedida');
        loadsPage()->set('search', 'palp8001')->assertSee('Pablo Libre')->assertDontSee('Olga Excedida');
        loadsPage()->set('plaza', 'honorarios')->assertSee('Hugo Honorarios')->assertDontSee('Pablo Libre');
        loadsPage()->set('search', 'zzz')->assertSeeHtml('data-testid="loads-no-match"');
    });

    it('un docente de otro departamento o inactivo no sale', function () {
        boardSeed();
        $other = boardTeacher('Carlos Matemático', 'tiempoCompleto', $this->cb);
        Teacher::factory()->inactive()->adscribedTo($this->sc)->create(['name' => 'Inés Inactiva']);
        app(RefreshTeacherTermLoads::class)->handle($this->term);

        loadsPage()->assertDontSee('Carlos Matemático')->assertDontSee('Inés Inactiva');
    });
});

describe('drawer', function () {
    it('muestra los grupos por campus y la semana del docente', function () {
        $seed = boardSeed();
        $group = Group::query()->where('code', 'A1')->first();
        GroupSession::factory()->for($group)->on(2, '07:00', '09:00')->create();

        loadsPage()->call('open', $seed['over']->id)
            ->assertSet('showDrawer', true)
            ->assertSeeHtml('data-testid="drawer-campus-C1"')
            ->assertSeeHtml('data-testid="drawer-campus-C2"')
            ->assertSee('Materia A1')
            ->assertSee('Materia A2')
            ->assertSeeHtml('data-testid="drawer-week"')
            ->assertSee('Excede +3 h');
    });

    it('un docente que no es del departamento da 404', function () {
        boardSeed();
        $other = boardTeacher('Carlos Matemático', 'tiempoCompleto', $this->cb);

        loadsPage()->call('open', $other->id)->assertNotFound();
    });
});

describe('calcular ahora', function () {
    it('sin filas muestra el estado vacío y el botón despacha el job', function () {
        Queue::fake();
        boardTeacher('Pablo Libre', 'tiempoCompleto');

        loadsPage()->assertSeeHtml('data-testid="loads-not-computed"')
            ->call('calculate');

        Queue::assertPushed(RefreshTeacherLoads::class, fn (RefreshTeacherLoads $job): bool => $job->termId === $this->term->id);
    });

    it('con filas ya no ofrece el botón', function () {
        boardSeed();

        loadsPage()->assertDontSeeHtml('data-testid="loads-calculate"');
    });
});

describe('alcance', function () {
    it('el jefe de otro departamento no entra a este por la URL', function () {
        boardSeed();
        $other = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->cb->id])->create();

        loadsPage($other, ['depto' => 'SC'])->assertForbidden();
        loadsPage($other)->assertSee('Ciencias Básicas')->assertDontSee('Olga Excedida');
    });

    it('el coordinador y el docente no entran', function () {
        $career = Career::factory()->create();
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $career->id])->create();

        loadsPage($coordinator)->assertForbidden();
        $this->actingAs($coordinator)->get(route('staffing.teacher-loads'))->assertForbidden();

        $teacherUser = User::factory()->withRole(Role::Docente)->create();
        $this->actingAs($teacherUser)->get(route('staffing.teacher-loads'))->assertForbidden();
    });

    it('Subdirección elige el departamento con ?depto', function () {
        boardSeed();
        boardTeacher('Carlos Matemático', 'tiempoCompleto', $this->cb);
        app(RefreshTeacherTermLoads::class)->handle($this->term);
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        loadsPage($subdireccion, ['depto' => 'CB'])->assertSee('Carlos Matemático')->assertDontSee('Olga Excedida');
        loadsPage($subdireccion, ['depto' => 'SC'])->assertSee('Olga Excedida');
    });

    it('la entrada del menú sale solo a quien ve la carga por docente', function () {
        $labels = function (User $user): array {
            $this->actingAs($user);

            return array_map(fn ($item) => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)->forUser($user)));
        };
        $proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => Career::factory()->create()->id])->create();

        expect($labels($this->jefe))->toContain('Carga por docente')
            ->and($labels($proyecto))->toContain('Carga por docente')
            ->and($labels(User::factory()->withRole(Role::Subdireccion)->create()))->toContain('Carga por docente')
            ->and($labels(User::factory()->admin()->create()))->toContain('Carga por docente')
            ->and($labels($coordinador))->not->toContain('Carga por docente');
    });
});

describe('TeacherLoadsBoardQuery', function () {
    it('no crece en consultas con más docentes', function () {
        boardSeed();
        $department = $this->sc;
        $count = function () use ($department): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(TeacherLoadsBoardQuery::class)->forDepartment($this->term, $department);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $before = $count();

        foreach (range(1, 6) as $i) {
            $teacher = boardTeacher("Docente {$i}", 'tiempoCompleto');
            $group = boardGroup($teacher, $i % 2 ? $this->c1 : $this->c2, 5, "Z{$i}");
            GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();
            TeacherExternalLoad::factory()->for($teacher)->create(['term_id' => $this->term->id, 'hours' => 2]);
        }

        app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect($count())->toBe($before)->and($before)->toBeLessThanOrEqual(6);
    });

    it('cuenta a los docentes sin fila como pendientes y trae la plaza y lo asignado por campus', function () {
        $seed = boardSeed();
        boardTeacher('Nuevo Docente', 'tiempoCompleto');

        $board = app(TeacherLoadsBoardQuery::class)->forDepartment($this->term, $this->sc);
        $row = collect($board->rows)->firstWhere('teacherId', $seed['over']->id);

        expect($board->pending)->toBe(1)
            ->and($row->positionLabels)->toBe(['MT'])
            ->and($row->hoursByCampus)->toBe(['C1' => 8, 'C2' => 15])
            ->and($board->totals()['over'])->toBe(1);
    });
});
