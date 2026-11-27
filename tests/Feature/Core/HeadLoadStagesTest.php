<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Listeners\ForgetLoadStagesOnTransition;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\PageTrailQuery;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Core\Settings\SettingType;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
| MN-06 (tarea 1.56): el jefe de departamento y el jefe de proyecto docente ven y abren solo las pantallas de la carga de
| las etapas en que está la carga de su departamento (confirmado el 26 de noviembre de 2026: la etapa la decide el estado
| de su carga), siempre, con la carga visible u oculta; las corridas del motor, nunca por ahora. Con «Mostrar la carga
| académica» apagado, ellos siguen viendo las de su etapa y los demás nada (confirmado), salvo que el admin apague «Mostrar
| a jefes y JPD las pantallas de su etapa». El admin no se afecta.
*/

final class HeadStageNotice extends NexusNotification
{
    public function __construct(private readonly string $title, private readonly string $route, private readonly ?string $termId = null) {}

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: $this->title, body: 'Detalle', icon: 'o-bell', route: $this->route, termId: $this->termId);
    }
}

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->sistemas = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->basicas = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->load = AcademicLoad::factory()->for($this->term)->create(['department_id' => $this->sistemas->id]);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sistemas->id])->create();
    $this->jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->campus->id, 'department_id' => $this->sistemas->id])->create();
    $this->admin = User::factory()->admin()->create();
});

/**
 * Deja la carga de Sistemas en `$status` y olvida lo leído en la petición, como si fuera la siguiente.
 */
function headStagesMove(AcademicLoad $load, LoadStatus $status): void
{
    $load->forceFill(['status' => $status])->save();
    app(AcademicLoadVisibility::class)->forget();
}

/**
 * Cambia un parámetro de la carga con la Action de «Parámetros», como el admin.
 */
function headStagesSet(string $key, bool $value): void
{
    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get($key), $value);
}

/**
 * Las entradas de «Carga académica» que el usuario ve en su menú, en su orden.
 *
 * @return list<string>
 */
function headStagesMenuOf(User $user): array
{
    test()->actingAs($user);
    app(Actor::class)->refresh();

    $labels = [];

    foreach (app(Navigation::class)->visibleByArea(app(Actor::class)) as $area) {
        if ($area['area'] === NavigationArea::CargaAcademica) {
            foreach ($area['groups'] as $group) {
                foreach ($group['entries'] as $entry) {
                    $labels[] = $entry->item->label;
                }
            }
        }
    }

    return $labels;
}

/**
 * El snapshot del componente de la página que pintó la respuesta.
 */
function headStagesSnapshotIn(TestResponse $response, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $response->getContent(), $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("La página no pintó {$component}.");
}

describe('MN-06: las etapas de cada pantalla', function () {
    it('cada ruta de la lista de la carga declara sus etapas, y las corridas del motor ninguna', function () {
        expect(array_keys(AcademicLoadVisibility::ROUTE_STAGES))->toEqualCanonicalizing(AcademicLoadVisibility::ROUTES)
            ->and(AcademicLoadVisibility::ROUTE_STAGES['solver.runs'])->toBe([]);

        foreach (AcademicLoadVisibility::ROUTE_STAGES as $route => $stages) {
            expect($stages)->each->toBeInstanceOf(Stage::class);

            if ($route !== 'solver.runs') {
                expect($stages)->not->toBe([], "{$route} no tiene etapa");
            }
        }
    });

    it('MN-06: el jefe ve en su menú solo las pantallas de la etapa de su carga, nunca las corridas del motor', function (LoadStatus $status, array $expected) {
        headStagesMove($this->load, $status);

        expect(headStagesMenuOf($this->jefe))->toBe($expected);
    })->with([
        'borrador' => [LoadStatus::Draft, ['Bandeja del departamento', 'Demanda por materia', 'Horarios', 'Choques y avance', 'Acuerdos', 'Carga actual', 'Asignación docente', 'Carga por docente', 'Solicitudes de cambio']],
        'propuesta' => [LoadStatus::Proposed, ['Bandeja del departamento', 'Demanda por materia', 'Horarios', 'Choques y avance', 'Acuerdos', 'Carga actual', 'Asignación docente', 'Carga por docente', 'Solicitudes de cambio']],
        'asignación' => [LoadStatus::Staffing, ['Horarios', 'Choques y avance', 'Acuerdos', 'Carga actual', 'Asignación docente', 'Carga por docente', 'Solicitudes de cambio', 'Cierre de la carga']],
        'cerrada' => [LoadStatus::Closed, ['Carga actual', 'Solicitudes de cambio', 'Cierre de la carga', 'Versiones publicadas', 'Horarios publicados']],
    ]);

    it('MN-06: el JPD también ve solo las de su etapa', function () {
        expect(headStagesMenuOf($this->jpd))->toBe(['Bandeja del departamento', 'Demanda por materia', 'Horarios', 'Choques y avance', 'Acuerdos', 'Carga actual', 'Asignación docente', 'Carga por docente', 'Solicitudes de cambio']);

        headStagesMove($this->load, LoadStatus::Closed);

        expect(headStagesMenuOf($this->jpd))->toBe(['Carga actual', 'Solicitudes de cambio', 'Versiones publicadas', 'Horarios publicados']);
    });

    it('MN-06: el jefe de otro departamento, con su carga en otro estado, ve su etapa y no la de Sistemas', function () {
        AcademicLoad::factory()->for($this->term)->inStatus(LoadStatus::Closed)->create(['department_id' => $this->basicas->id]);
        $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->basicas->id])->create();

        expect(headStagesMenuOf($otherHead))->toContain('Cierre de la carga')->not->toContain('Asignación docente', 'Bandeja del departamento')
            ->and(headStagesMenuOf($this->jefe))->toContain('Asignación docente')->not->toContain('Cierre de la carga');
    });

    it('MN-06: sin cargas de su departamento en el periodo de carga, el jefe no ve ninguna pantalla de la carga', function () {
        $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->basicas->id])->create();

        expect(headStagesMenuOf($otherHead))->toBe([]);
    });

    it('MN-06: el jefe de Servicios Escolares, que no ve la carga de ningún departamento (AZ-02), no se rige por la etapa: sigue el interruptor', function () {
        $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento, 'parent_id' => OrgUnit::query()->where('code', OrgTree::ACADEMIC)->sole()->id]);
        $this->actingAs(User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $services->id])->create());
        $visibility = app(AcademicLoadVisibility::class);

        expect($visibility->closesFor(app(Actor::class), 'published.export'))->toBeFalse();

        headStagesSet(AcademicLoadVisibility::SETTING, false);

        expect($visibility->closesFor(app(Actor::class), 'published.export'))->toBeTrue();
    });

    it('MN-06: el admin no se afecta: ve todo, también las corridas del motor', function () {
        expect(headStagesMenuOf($this->admin))->toContain('Corridas del motor', 'Cierre de la carga', 'Asignación docente', 'Horarios publicados');
    });

    it('MN-06: el jefe abre las de su etapa y no las de otra ni las corridas del motor (403); la banda tampoco las ubica', function () {
        $this->actingAs($this->jefe)->get(route('staffing.assign'))->assertOk();
        $this->get(route('scheduling.editor'))->assertOk();
        $this->get(route('staffing.closing'))->assertForbidden();
        $this->get(route('academic-load.versions'))->assertForbidden();
        $this->get(route('solver.runs'))->assertForbidden();

        app(Actor::class)->refresh();
        expect(app(PageTrailQuery::class)->forUrl('/solver/runs', app(Actor::class)))->toBeNull()
            ->and(app(PageTrailQuery::class)->forUrl('/staffing/assign', app(Actor::class)))->toBe(['area' => 'Carga académica', 'title' => 'Asignación docente']);
    });

    it('MN-06: si la carga cambia de etapa, una acción de Livewire en una pantalla que ya no es de su etapa se niega', function () {
        headStagesMove($this->load, LoadStatus::Staffing);
        $snapshot = headStagesSnapshotIn($this->actingAs($this->jefe)->get(route('staffing.closing'))->assertOk(), 'staffing::pages.closing');

        headStagesMove($this->load, LoadStatus::Proposed);
        Livewire::flushState();

        $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]]])->assertForbidden();
    });

    it('MN-06: si la carga cambia de estado a media petición, lo que se pinta después ya muestra la etapa nueva', function () {
        expect(headStagesMenuOf($this->jefe))->not->toContain('Cierre de la carga');

        $this->load->forceFill(['status' => LoadStatus::Staffing])->save();
        app(ForgetLoadStagesOnTransition::class)->handle(new LoadTransitioned($this->load, LoadStatus::Draft, LoadStatus::Staffing));

        expect(headStagesMenuOf($this->jefe))->toContain('Cierre de la carga');
        Event::fake()->assertListening(LoadTransitioned::class, ForgetLoadStagesOnTransition::class);
    });

    it('MN-06: con la carga visible, la consulta pública de horarios sigue abriendo para el jefe fuera de su etapa, aunque no salga en su menú (supuesto por confirmar)', function () {
        expect(headStagesMenuOf($this->jefe))->not->toContain('Horarios publicados');

        $this->get(route('published.index'))->assertOk();
    });
});

describe('MN-06: con la carga oculta', function () {
    beforeEach(fn () => headStagesSet(AcademicLoadVisibility::SETTING, false));

    it('el jefe y el JPD siguen viendo y abriendo las pantallas de su etapa, sin la marca del admin', function () {
        expect(headStagesMenuOf($this->jefe))->toBe(['Bandeja del departamento', 'Demanda por materia', 'Horarios', 'Choques y avance', 'Acuerdos', 'Carga actual', 'Asignación docente', 'Carga por docente', 'Solicitudes de cambio'])
            ->and(headStagesMenuOf($this->jpd))->toContain('Asignación docente');

        $this->actingAs($this->jefe);
        app(Actor::class)->refresh();
        $item = app(Navigation::class)->forRoute('staffing.assign', app(Actor::class));

        expect(app(Navigation::class)->hiddenReasonFor($item, app(Actor::class)))->toBeNull();

        $this->get(route('staffing.assign'))->assertOk();
        $this->get(route('staffing.closing'))->assertForbidden();
        $this->get(route('solver.runs'))->assertForbidden();
    });

    it('la consulta pública abre para el jefe solo si su carga está en publicación; al invitado le sigue respondiendo 404', function () {
        $this->actingAs($this->jefe)->get(route('published.index'))->assertNotFound();

        headStagesMove($this->load, LoadStatus::Closed);

        $this->get(route('published.index'))->assertOk();
        auth()->logout();
        $this->get(route('published.index'))->assertNotFound();
    });

    it('MN-06: el coordinador, Subdirección y el docente no ven nada de la carga ni la abren', function () {
        $others = [
            User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create(),
            User::factory()->withRole(Role::Subdireccion)->create(),
            User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create(),
        ];

        foreach ($others as $user) {
            expect(headStagesMenuOf($user))->toBe([]);
            expect($this->actingAs($user)->get(route('staffing.assign'))->status())->toBe(403, $user->username);
        }
    });

    it('MN-06: con «Mostrar a jefes y JPD las pantallas de su etapa» apagado, el jefe y el JPD tampoco la ven ni la abren', function () {
        headStagesSet(AcademicLoadVisibility::HEADS_SETTING, false);

        expect(headStagesMenuOf($this->jefe))->toBe([])
            ->and(headStagesMenuOf($this->jpd))->toBe([]);

        $this->actingAs($this->jefe)->get(route('staffing.assign'))->assertForbidden();
        expect(headStagesMenuOf($this->admin))->toContain('Asignación docente', 'Corridas del motor');
    });
});

describe('MN-06: el parámetro de jefes y JPD', function () {
    it('es un sí o no de Core, encendido por omisión, con el mismo candado que el interruptor', function () {
        $definition = app(SettingRegistry::class)->get(AcademicLoadVisibility::HEADS_SETTING);

        expect($definition)
            ->module->toBe('core')
            ->label->toBe('Mostrar a jefes y JPD las pantallas de su etapa')
            ->type->toBe(SettingType::Boolean)
            ->default->toBeTrue()
            ->permission->toBe(CorePermission::AcademicLoadToggle)
            ->permissionOnly->toBeTrue();
    });

    it('va en Parámetros justo después de «Mostrar la carga académica», y el admin lo apaga', function () {
        $component = Livewire::actingAs($this->admin)->test('core::pages.settings');
        $switch = domOf($component)->querySelector('[data-testid="setting-'.AcademicLoadVisibility::SETTING.'"]');

        expect($switch->nextElementSibling?->getAttribute('data-testid'))->toBe('setting-'.AcademicLoadVisibility::HEADS_SETTING);

        $component->assertSet('values.core__academic_load_heads_by_stage', true)
            ->set('values.core__academic_load_heads_by_stage', false)
            ->call('save', 'core')
            ->assertHasNoErrors();

        expect(Setting::query()->where('key', AcademicLoadVisibility::HEADS_SETTING)->sole()->value)->toBeFalse();
    });

    it('apagarlo con la carga visible no cambia nada: el jefe sigue viendo su etapa', function () {
        headStagesSet(AcademicLoadVisibility::HEADS_SETTING, false);

        expect(headStagesMenuOf($this->jefe))->toContain('Asignación docente')->not->toContain('Cierre de la carga', 'Corridas del motor');
    });
});

describe('MN-06: la campana', function () {
    beforeEach(function () {
        $this->jefe->notify(new HeadStageNotice('Asigna a tus docentes', 'staffing.assign'));
        $this->jefe->notify(new HeadStageNotice('Cierra tu carga', 'staffing.closing'));
        $this->jefe->notify(new HeadStageNotice('Terminó la corrida', 'solver.runs'));
    });

    it('no le muestra al jefe los avisos que llevan a pantallas fuera de su etapa', function () {
        Livewire::actingAs($this->jefe)->test('core::components.bell')
            ->assertSeeHtml('aria-label="Notificaciones: 1 sin leer"')
            ->assertSee('Asigna a tus docentes')
            ->assertDontSee('Cierra tu carga')
            ->assertDontSee('Terminó la corrida');
    });

    it('abrir uno que ya estaba en pantalla lo avisa en lugar de llevar a un 403', function () {
        $component = Livewire::actingAs($this->jefe)->test('core::components.bell');

        $component->call('open', $this->jefe->notifications()->where('data->title', 'Terminó la corrida')->value('id'))->assertNoRedirect();

        expect(lastToastOf($component))->toMatchArray(['type' => 'warning', 'title' => 'La carga académica no está disponible por ahora.']);
    });

    it('un aviso de otro periodo se cuenta y se abre con la etapa de la carga de ese periodo, que queda en la credencial', function () {
        $previous = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026']);
        AcademicLoad::factory()->for($previous)->inStatus(LoadStatus::Closed)->create(['department_id' => $this->sistemas->id]);
        $this->jefe->notify(new HeadStageNotice('Cierra la de agosto', 'staffing.closing', $previous->id));
        $this->jefe->notify(new HeadStageNotice('Asigna los de agosto', 'staffing.assign', $previous->id));

        Livewire::actingAs($this->jefe)->test('core::components.bell')
            ->assertSeeHtml('aria-label="Notificaciones: 2 sin leer"')
            ->assertSee('Asigna a tus docentes')
            ->assertSee('Cierra la de agosto')
            ->assertDontSee('Asigna los de agosto')
            ->call('open', $this->jefe->notifications()->where('data->title', 'Cierra la de agosto')->value('id'))
            ->assertRedirect(route('staffing.closing'));

        app(Actor::class)->refresh();
        expect(app(Actor::class)->loadTerm()?->is($previous))->toBeTrue();
    });
});
