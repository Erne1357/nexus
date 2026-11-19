<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\AcademicLoad\Queries\LoadVersionsQuery;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Constraints\SeeInOrder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

/*
| Versiones publicadas (tarea 8.10; maqueta C «Línea y detalle»): la línea de versiones de una carga, de la primera a la
| vigente, y la versión elegida con quién la publicó, su motivo, a quién se avisó (RA-05, RA-06), sus archivos y lo que
| cambió por tipo; «Ver por docente o carrera» abre lo que se le dijo a cada uno. La ve quien ve la carga
| (AcademicLoadPolicy::view): el jefe de su departamento, el jefe de proyecto docente, Subdirección y el admin.
|
| La carga de Ciencias Básicas tiene tres versiones: la 1 y la 2 se publicaron antes de la tarea 8.4 (sin motivo ni diff
| guardados; la 2 movió de salón un grupo, después de que Subdirección la reabriera) y la 3 salió de un re-análisis con
| su diff guardado: un grupo nuevo, uno cancelado y uno con más cupo.
*/

const VERSIONS_PAGE = 'academic-load::pages.versions';

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20273', 'name' => 'Ago-Dic 2027']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->basics = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales', 'department_id' => $this->systems->id]);
    $this->ind = Career::factory()->create(['code' => 'IND', 'name' => 'Ingeniería Industrial', 'department_id' => $this->systems->id]);

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->basics->id])->create(['name' => 'Sergio Valdez Ibarra']);
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Héctor Salinas Ruiz']);
    $this->load = AcademicLoad::factory()->for($this->term)->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => $this->basics->id, 'status' => LoadStatus::Published]);

    versionsBoardHistory();
});

/**
 * Un grupo de Ciencias Básicas en C2 para las fotos de la carga.
 *
 * @param  list<array{0: Career, 1: int, 2: bool}>  $seats  carrera, lugares y si es la dueña
 * @param  array<string, mixed>  $session
 * @return array<string, mixed>
 */
function versionsBoardGroup(int $key, string $code, string $subjectCode, string $subjectName, ?array $teacher, int $capacity, array $seats, array $session): array
{
    return LoadSnapshotFactory::group([
        'id' => fakeKey($key),
        'code' => $code,
        'capacity' => $capacity,
        'campus' => ['id' => test()->campus->id, 'code' => 'C2', 'name' => 'Campus II'],
        'subject' => ['id' => fakeKey($key + 100), 'code' => $subjectCode, 'name' => $subjectName, 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => test()->basics->id],
        'teacher' => $teacher,
        'placements' => array_map(fn (array $seat): array => LoadSnapshotFactory::placement([
            'career' => ['id' => $seat[0]->id, 'code' => $seat[0]->code, 'name' => $seat[0]->name],
            'seats' => $seat[1],
            'is_owner' => $seat[2],
        ]), $seats),
        'sessions' => [LoadSnapshotFactory::session($session)],
    ]);
}

/**
 * Un salón de la foto, por su clave.
 *
 * @return array<string, mixed>
 */
function versionsBoardRoom(int $key, string $code): array
{
    return ['id' => fakeKey($key), 'code' => $code, 'name' => null, 'building' => ['id' => fakeKey(900), 'code' => substr($code, 0, 1), 'name' => 'Edificio'], 'owner_department_id' => test()->basics->id];
}

/**
 * Una transición de la carga en una fecha de Ciudad Juárez.
 */
function versionsBoardTransition(LoadStatus $from, LoadStatus $to, User $by, string $at, ?string $comment = null): void
{
    LoadTransition::factory()->create([
        'academic_load_id' => test()->load->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $by->id, 'comment' => $comment,
        'created_at' => CarbonImmutable::parse($at, 'America/Ciudad_Juarez'),
    ]);
}

/**
 * Las tres versiones de la carga y sus transiciones (ver el encabezado del archivo).
 */
function versionsBoardHistory(): void
{
    $t = test();
    $ana = ['id' => fakeKey(1), 'rfc' => 'RUVA800101AA1', 'name' => 'Ana Ruiz Varela'];
    $jorge = ['id' => fakeKey(2), 'rfc' => 'ESLJ800101AA1', 'name' => 'Jorge Esparza Luna'];
    $d14 = versionsBoardRoom(10, 'D-14');
    $e05 = versionsBoardRoom(11, 'E-05');

    $integral = fn (array $room, int $capacity): array => versionsBoardGroup(20, 'A', 'ACF-0902', 'Cálculo Integral', $ana, $capacity, [[$t->isc, $capacity, true]], ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => $room]);
    $vectorial = versionsBoardGroup(21, 'B', 'ACF-0904', 'Cálculo Vectorial', $jorge, 30, [[$t->isc, 30, true]], ['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => $d14]);
    $equations = versionsBoardGroup(22, 'C', 'ACF-0905', 'Ecuaciones Diferenciales', $ana, 35, [[$t->isc, 25, true], [$t->ind, 10, false]], ['day' => 4, 'starts_at' => '11:00', 'ends_at' => '13:00', 'room' => versionsBoardRoom(12, 'E-03')]);

    $published = fn (int $version, string $at, array $groups): LoadSnapshot => LoadSnapshot::factory()->for($t->load)->withGroups($groups)
        ->publishedAt(CarbonImmutable::parse($at, 'America/Ciudad_Juarez'))->create(['version' => $version, 'created_by' => $t->jefe->id]);

    versionsBoardTransition(LoadStatus::Closed, LoadStatus::Published, $t->jefe, '2027-05-21 10:00', 'Primera publicación');
    $published(1, '2027-05-21 10:00', [$integral($d14, 35), $vectorial]);
    versionsBoardTransition(LoadStatus::Published, LoadStatus::Adjusting, $t->subdireccion, '2027-06-01 12:00', 'El edificio D cierra por obra');
    versionsBoardTransition(LoadStatus::Closed, LoadStatus::Published, $t->jefe, '2027-06-04 09:20');
    $second = $published(2, '2027-06-04 09:20', [$integral($e05, 35), $vectorial]);

    versionsBoardTransition(LoadStatus::Published, LoadStatus::Reanalysis, $t->jefe, '2027-06-20 08:00', 'Calificaciones de Ene-Jun 2027');
    versionsBoardTransition(LoadStatus::Closed, LoadStatus::Published, $t->jefe, '2027-06-26 13:05', 'Re-análisis con las calificaciones de Ene-Jun 2027');
    $at = CarbonImmutable::parse('2027-06-26 13:05', 'America/Ciudad_Juarez');
    $payload = LoadSnapshotFactory::payload($t->load, 3, $t->jefe, [$integral($e05, 45), $equations], $at);
    $diff = SnapshotDiff::between(SnapshotPayload::normalize($second->payload), SnapshotPayload::normalize($payload), [$t->isc->id => $t->systems->id, $t->ind->id => $t->systems->id]);
    LoadSnapshot::factory()->for($t->load)->publishedAt($at)->create(['version' => 3, 'created_by' => $t->jefe->id, 'payload' => $payload, 'diff' => $diff, 'reason' => SnapshotReason::Reanalysis]);
}

/**
 * Un aviso «Carga republicada» al docente de la versión 3 de la carga, como lo deja LoadRepublished: la campana
 * (`database`, en `notifications`) o el correo (`mail`, en el registro de correos), una hora después de publicarse.
 */
function versionsNotice(string $channel, ?string $title = null, MailStatus $status = MailStatus::Sent, string $at = '2027-06-26 14:05'): void
{
    $t = test();
    $title ??= LoadRepublished::teacherTitle('Ciencias Básicas');
    $at = CarbonImmutable::parse($at, 'America/Ciudad_Juarez');

    if ($channel === 'mail') {
        MailLog::factory()->create(['kind' => 'LoadRepublished', 'subject' => $title, 'status' => $status, 'sent_at' => $at]);

        return;
    }

    User::factory()->create()->notifications()->create([
        'id' => (string) Str::uuid7(),
        'type' => LoadRepublished::class,
        'data' => ['title' => $title, 'body' => '', 'icon' => 'o-arrow-path-rounded-square', 'tone' => 'warning', 'route' => 'published.index', 'parameters' => [], 'term_id' => $t->load->term_id, 'campus_id' => null, 'modality_id' => $t->load->modality_id],
        'created_at' => $at,
    ]);
}

/**
 * La página como la abre `$user`, con su Actor de esta petición.
 *
 * @param  array<string, mixed>  $query
 */
function versionsPageAs(User $user, array $query = []): Testable
{
    test()->actingAs($user);
    app()->forgetScopedInstances();

    return Livewire::withQueryParams($query)->actingAs($user)->test(VERSIONS_PAGE);
}

/**
 * Lo que dice la página en ese orden. Después de llamar a un método, el `assertSeeInOrder` de Livewire mira la respuesta en
 * JSON, con los acentos escapados: este mira el HTML.
 *
 * @param  list<string>  $values
 */
function versionsSeeInOrder(Testable $page, array $values): Testable
{
    Assert::assertThat($values, new SeeInOrder($page->html()));

    return $page;
}

/**
 * @return list<string>
 */
function versionsMenuOf(User $user): array
{
    test()->actingAs($user);
    app()->forgetScopedInstances();

    return array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)));
}

describe('la línea y la versión elegida', function () {
    it('pinta las versiones de la primera a la vigente, como la línea de «Publicación», y abre la vigente', function () {
        versionsPageAs($this->jefe)
            ->assertOk()
            ->assertSee(['CB', 'Ciencias Básicas', 'Ago-Dic 2027', '3 versiones', 'Publicada · v3'])
            ->assertSeeInOrder(['21 may 2027', 'Publicación', '2', 'grupos', '4 jun 2027', 'Corrección', '26 jun 2027', 'vigente', 'Re-análisis', '+1 −1', '1', 'con cambios'])
            ->assertSeeInOrder(['Versión 3', 'Re-análisis', 'Vigente', 'Publicada el 26 jun 2027', '13:05', 'por Sergio Valdez Ibarra'])
            ->assertSee('Re-análisis con las calificaciones de Ene-Jun 2027')
            ->assertSee('Re-análisis iniciado por Sergio Valdez Ibarra el 20 jun 2027: «Calificaciones de Ene-Jun 2027»')
            ->assertSee('Qué cambió contra la versión 2');
    });

    it('elegir un punto abre esa versión y la URL la recuerda; una que no existe deja la vigente', function () {
        $page = versionsPageAs($this->jefe)
            ->call('selectVersion', 2)
            ->assertSet('version', 2)
            ->assertSee(['Versión 2', 'Qué cambió contra la versión 1'])
            ->assertDontSee('Qué cambió contra la versión 2');

        expect(domOf($page)->querySelector('[data-testid="version-2"]')->getAttribute('aria-pressed'))->toBe('true')
            ->and(domOf($page)->querySelector('[data-testid="version-3"]')->getAttribute('aria-pressed'))->toBe('false');

        $page->call('selectVersion', 9)->assertSet('version', null)->assertSee('Qué cambió contra la versión 2');

        versionsPageAs($this->jefe, ['version' => 1])->assertSee('Primera versión: no hay contra qué comparar.');
        versionsPageAs($this->jefe, ['version' => 'otra'])->assertOk()->assertSee('Qué cambió contra la versión 2');
        $this->actingAs($this->jefe)->get(route('academic-load.versions', ['version' => '7']))->assertOk()->assertSee('Qué cambió contra la versión 2');
    });

    it('agrupa lo que cambió la versión por tipo, con lo que tenía y lo que quedó', function () {
        $page = versionsPageAs($this->jefe);
        $sections = array_map(fn ($node): string => $node->getAttribute('data-testid'), iterator_to_array(domOf($page)->querySelectorAll('[data-testid^="changes-"]')));

        expect($sections)->toBe(['changes-added', 'changes-cancelled', 'changes-quota', 'changes-career_quotas']);

        $page->assertSeeInOrder(['Nuevos', 'ACF-0905 · C', 'Ecuaciones Diferenciales', 'J 11:00–13:00 en E-03 · Ana Ruiz Varela · ISC, IND'])
            ->assertSeeInOrder(['Cancelados', 'ACF-0904 · B', 'Cálculo Vectorial', 'Tenía M 7:00–9:00 en D-14 · Jorge Esparza Luna · ISC'])
            ->assertSeeInOrder(['Cupo', 'ACF-0902 · A', 'Cálculo Integral', '35', 'pasa a', '45'])
            ->assertSeeInOrder(['Cupos por carrera', 'ACF-0902 · A', 'ISC 35', 'pasa a', 'ISC 45']);
    });

    it('una versión de antes de la tarea 8.4 se compara al leerla y su motivo se deduce', function () {
        $page = versionsPageAs($this->jefe, ['version' => 2]);
        $reason = domOf($page)->querySelector('[data-testid="version-reason"]');

        expect(trim($reason->textContent))->toBe('Corrección')
            ->and($reason->getAttribute('title'))->toBe('Deducido: se publicó antes de que Nexus guardara el motivo.');

        $page->assertSeeInOrder(['Salón', 'ACF-0902 · A', 'D-14 (L 7:00–9:00)', 'pasa a', 'E-05'])
            ->assertSee('Reabierta por Héctor Salinas Ruiz el 1 jun 2027: «El edificio D cierra por obra»')
            ->assertSeeInOrder(['Afectados', '1', 'docente', '1', 'carrera', '0', 'departamentos'])
            ->assertSee('Se publicó antes de que Nexus avisara las republicaciones: nadie recibió aviso.')
            ->assertDontSee('Se avisó a');
    });

    it('la primera versión no tiene contra qué compararse: da sus grupos, sesiones y docentes', function () {
        versionsPageAs($this->jefe, ['version' => 1])
            ->assertSeeInOrder(['Versión 1', 'Publicación', 'Primera publicación', 'Grupos', '2', 'Sesiones', '2', 'Docentes', '2'])
            ->assertSee('Primera versión: no hay contra qué comparar.')
            ->assertDontSee('Se avisó a')
            ->assertDontSeeHtml('data-testid="open-affected"');
    });

    it('el motivo va con el tono de su insignia: publicación en éxito, corrección en advertencia y re-análisis en información', function (int $version, string $label, string $class) {
        $reason = domOf(versionsPageAs($this->jefe)->call('selectVersion', $version))->querySelector('[data-testid="version-reason"]');

        expect(trim($reason->textContent))->toBe($label)
            ->and($reason->getAttribute('class'))->toContain($class);
    })->with([
        'publicación' => [1, 'Publicación', 'badge-success'],
        'corrección' => [2, 'Corrección', 'badge-warning'],
        're-análisis' => [3, 'Re-análisis', 'badge-info'],
    ]);

    it('«Se avisó a» cuenta los docentes, carreras y departamentos afectados del resumen guardado (RA-05)', function () {
        versionsPageAs($this->jefe)
            ->assertSeeInOrder(['Nuevos', '+1', 'Cancelados', '−1', 'Con cambios', '1'])
            ->assertSeeInOrder(['Se avisó a', '2', 'docentes', '2', 'carreras', '1', 'departamento'])
            ->assertDontSee('Afectados');
    });

    it('los archivos de la versión vigente se descargan; los de una anterior dicen por qué no', function () {
        $page = versionsPageAs($this->jefe);
        $parameters = ['periodo' => '20273', 'modalidad' => 'pres'];

        expect(domOf($page)->querySelector('a[data-testid="file-pdf"]')->getAttribute('href'))->toBe(route('published.export', [...$parameters, 'tipo' => 'departamento', 'departamento' => $this->basics->id]))
            ->and(domOf($page)->querySelector('a[data-testid="file-excel"]')->getAttribute('href'))->toBe(route('published.export', [...$parameters, 'tipo' => 'excel']));
        expect(textOf(domOf($page)->querySelector('a[data-testid="file-pdf"]')))->toContain('Carga CB - 20273 PRES.pdf')->toContain('PDF del departamento')
            ->and(textOf(domOf($page)->querySelector('a[data-testid="file-excel"]')))->toContain('Carga publicada 20273 PRES.xlsx')->toContain('Excel de la modalidad')
            ->and(textOf(domOf($page)->querySelector('a[data-testid="file-excel"] .whitespace-nowrap')))->toBe('.xlsx');

        $page->call('selectVersion', 2);

        expect(domOf($page)->querySelector('a[data-testid="file-pdf"]'))->toBeNull()
            ->and(domOf($page)->querySelector('[data-testid="file-pdf"]')->getAttribute('aria-disabled'))->toBe('true');
        $page->assertSee('Los archivos de las versiones anteriores no se ofrecen: solo se descargan los de la versión vigente (v3).');
    });

    it('«Ver por docente o carrera» abre lo que se le avisó a cada uno', function () {
        versionsNotice('database');
        versionsNotice('mail');

        $page = versionsPageAs($this->jefe)
            ->call('openAffected')
            ->assertSet('showAffected', true)
            ->assertSee('Versión 3: por docente o carrera')
            ->assertSee('Lo que se le avisó a cada docente afectado, por la campana y el correo.')
            ->assertSee('Lo que ven docentes y alumnos desde el 26 jun 2027.');

        versionsSeeInOrder($page, [
            'Lo que se le avisó a cada docente afectado',
            'Ana Ruiz Varela', 'Grupo nuevo: ACF-0905 Ecuaciones Diferenciales, grupo C (C2), jueves 11:00 a 13:00 en E-03.',
            'ACF-0902 Cálculo Integral, grupo A (C2): cupo de 35 a 45; lugares por carrera de ISC 35 a ISC 45.',
            'Jorge Esparza Luna', 'Se canceló ACF-0904 Cálculo Vectorial, grupo B (C2).',
            'Lo que se le avisó a la coordinación de cada carrera afectada.',
            'IND', 'Ingeniería Industrial', 'Grupo nuevo: ACF-0905',
            'ISC', 'Ingeniería en Sistemas Computacionales', 'Grupo nuevo: ACF-0905', 'Se canceló ACF-0904', 'ACF-0902 Cálculo Integral, grupo A (C2): cupo de 35 a 45',
        ]);
    });

    it('RA-06: el drawer dice el canal por el que de verdad salió el aviso al docente: el correo, solo si se mandó (ensayo 8.11, E-04)', function (array $channels, string $text) {
        foreach ($channels as $channel) {
            versionsNotice($channel);
        }

        // Lo de otra carga, de otro tipo, fallido o de antes de la versión no cuenta.
        versionsNotice('database', title: 'Cambiaron tus grupos en la carga de Sistemas y Computación');
        versionsNotice('mail', status: MailStatus::Failed);
        versionsNotice('mail', at: '2027-06-26 13:00');
        MailLog::factory()->create(['kind' => 'ProposalReturned', 'subject' => 'Cambiaron tus grupos en la carga de Ciencias Básicas', 'sent_at' => CarbonImmutable::parse('2027-06-26 14:00', 'America/Ciudad_Juarez')]);

        versionsPageAs($this->jefe)->call('openAffected')->assertSee($text);
    })->with([
        'campana y correo' => [['database', 'mail'], 'Lo que se le avisó a cada docente afectado, por la campana y el correo.'],
        'solo campana: los docentes no tienen correo' => [['database'], 'Lo que se le avisó a cada docente afectado, por la campana.'],
        'solo correo: los docentes no tienen cuenta' => [['mail'], 'Lo que se le avisó a cada docente afectado, por correo.'],
        'ninguno' => [[], 'Lo que cambió para cada docente afectado. No ha salido ningún aviso por la campana ni por correo.'],
    ]);

    it('en una versión anterior, el drawer dice hasta cuándo se vio y que nadie recibió el aviso', function () {
        versionsPageAs($this->jefe, ['version' => 2])->call('openAffected')
            ->assertSee('Lo que cambió para cada docente. Esta versión se publicó antes de los avisos: nadie lo recibió.')
            ->assertSee('Lo que vieron docentes y alumnos del 4 jun 2027 al 26 jun 2027.');
    });
});

describe('quién la ve', function () {
    it('la ven el jefe del departamento, el jefe de proyecto docente, Subdirección y el admin, con su entrada en el menú', function (Closure $user) {
        $user = $user($this);

        $this->actingAs($user)->get(route('academic-load.versions'))->assertOk()->assertSee('Versión 3');

        expect(versionsMenuOf($user))->toContain('Versiones publicadas');
    })->with([
        'jefe de departamento' => [fn ($test) => $test->jefe],
        'jefe de proyecto docente' => [fn ($test) => User::factory()->withRole(Role::JefeProyectoDocente, ['department_id' => $test->basics->id])->create()],
        'subdirección' => [fn ($test) => $test->subdireccion],
        'admin' => [fn () => User::factory()->admin()->create()],
    ]);

    it('AZ-S17: el jefe de proyecto docente con nombramiento en otro campus ve la carga de su modalidad', function () {
        $other = Campus::factory()->create(['code' => 'C1']);
        $user = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $other->id, 'department_id' => $this->basics->id])->create();

        versionsPageAs($user)->assertSee('Versión 3');
    });

    it('el coordinador y el docente reciben 403 y no ven la entrada', function (Closure $user) {
        $user = $user($this);

        $this->actingAs($user)->get(route('academic-load.versions'))->assertForbidden();

        expect(versionsMenuOf($user))->not->toContain('Versiones publicadas');
    })->with([
        'coordinador' => [fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->campus->id, 'career_id' => $test->isc->id])->create()],
        'docente' => [fn () => User::factory()->withRole(Role::Docente)->create()],
    ]);

    it('el jefe de otro departamento no ve las versiones de Ciencias Básicas: pedirlas es 403', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->systems->id])->create();

        versionsPageAs($jefe)->assertSeeHtml('data-testid="versions-empty"')->assertDontSee('Versión 3');
        $this->actingAs($jefe)->get(route('academic-load.versions', ['departamento' => $this->basics->id]))->assertForbidden();
        $this->actingAs($jefe)->get(route('academic-load.versions', ['departamento' => 'no-es-un-id']))->assertForbidden();
    });

    it('AZ-S17: el jefe de proyecto docente de otra modalidad no ve la carga de Presencial', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $user = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => modalityOf('EAD')->id, 'department_id' => $this->basics->id])->create();

        versionsPageAs($user, ['modalidad' => 'PRES', 'departamento' => $this->basics->id])
            ->assertSee('todavía no tiene versiones publicadas')
            ->assertDontSee('Versión 3');
    });

    it('Subdirección elige el departamento entre las cargas con versiones', function () {
        LoadSnapshot::factory()->for(AcademicLoad::factory()->for($this->term)->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => $this->systems->id, 'status' => LoadStatus::Published]))
            ->create(['version' => 1, 'created_by' => $this->subdireccion->id]);
        AcademicLoad::factory()->for($this->term)->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => Department::factory()->create(['code' => 'II'])->id, 'status' => LoadStatus::Staffing]);

        $page = versionsPageAs($this->subdireccion)->assertSee('Versión 3');
        $options = array_map(fn ($option): string => trim($option->textContent), iterator_to_array(domOf($page)->querySelectorAll('[data-testid="versions-department"] option')));

        expect($options)->toBe(['CB · Ciencias Básicas — Publicada · v3', 'SC · Sistemas y Computación — Publicada · v1']);

        $page->set('department', $this->systems->id)
            ->assertSee(['Sistemas y Computación', 'Versión 1', 'Primera versión: no hay contra qué comparar.'])
            ->assertSet('version', null);
    });

    it('cada acción llamada directo por quien no ve la carga da 403', function (string $method, array $arguments) {
        $page = versionsPageAs(User::factory()->admin()->create());

        $this->actingAs(User::factory()->withRole(Role::Docente)->create());
        app()->forgetScopedInstances();

        $page->call($method, ...$arguments)->assertForbidden();
    })->with([
        'elegir una versión' => ['selectVersion', [2]],
        'abrir «Ver por docente o carrera»' => ['openAffected', []],
    ]);

    it('el jefe de otro departamento tampoco elige una versión en la página que abrió el de Ciencias Básicas', function () {
        $page = versionsPageAs($this->jefe);

        $this->actingAs(User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->systems->id])->create());
        app()->forgetScopedInstances();

        $page->call('selectVersion', 1)->assertForbidden();
    });

    it('un invitado va al login', function () {
        $this->get(route('academic-load.versions'))->assertRedirect(route('login'));
    });
});

describe('datos inconsistentes, motivo deducido y caché del diff', function () {
    it('una carga con versión vigente pero sin fotos muestra el estado vacío, no un 500', function () {
        $empty = Department::factory()->create(['code' => 'EX', 'name' => 'Sin fotos']);
        AcademicLoad::factory()->for($this->term)->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => $empty->id, 'status' => LoadStatus::Published, 'current_version' => 2]);

        versionsPageAs($this->subdireccion, ['departamento' => $empty->id])
            ->assertOk()
            ->assertSee('no hay ninguna foto que mostrar')
            ->assertDontSee('Qué cambió contra');
    });

    it('deduce «Re-análisis» en una versión de antes de la 8.4 cuya salida de publicada fue al re-análisis, y «Corrección» si fue a ajustes', function () {
        $query = new LoadVersionsQuery;
        $board = $query->board($this->load);

        // La 2 de la carga de prueba salió de «adjusting»; la 3 guarda su motivo.
        expect(collect($board)->pluck('reason')->all())->toBe([SnapshotReason::Published, SnapshotReason::Correction, SnapshotReason::Reanalysis]);

        $other = AcademicLoad::factory()->for($this->term)->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => $this->systems->id, 'status' => LoadStatus::Published, 'current_version' => 2]);
        foreach ([1, 2] as $version) {
            LoadSnapshot::factory()->for($other)->withGroups([])->create(['version' => $version, 'created_by' => $this->jefe->id]);
        }
        foreach ([[LoadStatus::Closed, LoadStatus::Published], [LoadStatus::Published, LoadStatus::Reanalysis], [LoadStatus::Closed, LoadStatus::Published]] as [$from, $to]) {
            LoadTransition::factory()->create(['academic_load_id' => $other->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $this->jefe->id]);
        }

        expect(collect($query->board($other))->pluck('reason')->all())->toBe([SnapshotReason::Published, SnapshotReason::Reanalysis])
            ->and(SnapshotReason::inferredFor(1, LoadStatus::Reanalysis))->toBe(SnapshotReason::Published)
            ->and(SnapshotReason::inferredFor(2, LoadStatus::Adjusting))->toBe(SnapshotReason::Correction)
            ->and(SnapshotReason::inferredFor(2))->toBe(SnapshotReason::Correction);
    });

    it('el diff de una versión sin diff guardado se calcula una vez y las siguientes lecturas salen de la caché', function () {
        $query = new LoadVersionsQuery;
        $first = $query->diff($this->load, 2);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $query->board($this->load);
        $second = $query->diff($this->load, 2);
        $payloadReads = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], '"load_snapshots"."payload"') || str_contains($log['query'], 'select "payload"') || str_contains($log['query'], '"payload"') && ! str_contains($log['query'], 'payload->'))->count();
        DB::disableQueryLog();

        expect($second)->toBe($first)->and($first)->not->toBeNull()->and($payloadReads)->toBe(0)
            ->and(Cache::has('load_snapshot_diff:'.LoadSnapshot::query()->where('academic_load_id', $this->load->id)->where('version', 2)->value('id')))->toBeTrue();
    });
});
