<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;

/*
| «Versiones publicadas» en el navegador (tarea 8.10): el jefe de Ciencias Básicas abre el tablero desde su menú, ve la
| vigente (v2, de un re-análisis) con lo que cambió por tipo, cambia a la v1 y vuelve, abre «Ver por docente o carrera»
| y pasa a «Carrera»; a 1440 y a 390 px, en claro y en oscuro, sin desbordar la página.
*/

beforeEach(function () {
    $term = Term::factory()->current()->create(['code' => '20273', 'name' => 'Ago-Dic 2027']);
    $basics = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $basics->id])->create(['username' => 'jefe.versiones', 'name' => 'Sergio Valdez Ibarra']);
    $load = AcademicLoad::factory()->for($term)->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => $basics->id, 'status' => LoadStatus::Published]);

    $ana = ['id' => fakeKey(1), 'rfc' => 'RUVA800101AA1', 'name' => 'Ana Ruiz Varela'];
    $jorge = ['id' => fakeKey(2), 'rfc' => 'ESLJ800101AA1', 'name' => 'Jorge Esparza Luna'];
    $room = LoadSnapshotFactory::session()['room'];
    $placement = LoadSnapshotFactory::placement(['career' => ['id' => $isc->id, 'code' => 'ISC', 'name' => $isc->name], 'seats' => 35]);
    $group = fn (int $key, string $code, string $subject, string $name, array $teacher, int $day): array => LoadSnapshotFactory::group([
        'id' => fakeKey($key), 'code' => $code, 'teacher' => $teacher, 'placements' => [$placement],
        'subject' => ['id' => fakeKey($key + 100), 'code' => $subject, 'name' => $name, 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => $basics->id],
        'sessions' => [LoadSnapshotFactory::session(['day' => $day, 'room' => $room])],
    ]);

    $first = LoadSnapshot::factory()->for($load)->withGroups([$group(20, 'A', 'ACF-0902', 'Cálculo Integral', $ana, 1), $group(21, 'B', 'ACF-0904', 'Cálculo Vectorial', $jorge, 2)])
        ->publishedAt(CarbonImmutable::parse('2027-05-21 10:00', 'America/Ciudad_Juarez'))->create(['version' => 1, 'created_by' => $jefe->id]);
    $at = CarbonImmutable::parse('2027-06-26 13:05', 'America/Ciudad_Juarez');
    $payload = LoadSnapshotFactory::payload($load, 2, $jefe, [$group(20, 'A', 'ACF-0902', 'Cálculo Integral', $jorge, 1), $group(22, 'C', 'ACF-0905', 'Ecuaciones Diferenciales', $ana, 4)], $at);
    LoadSnapshot::factory()->for($load)->publishedAt($at)->create([
        'version' => 2, 'created_by' => $jefe->id, 'payload' => $payload, 'reason' => SnapshotReason::Reanalysis,
        'diff' => SnapshotDiff::between(SnapshotPayload::normalize($first->payload), SnapshotPayload::normalize($payload)),
    ]);
});

it('el jefe recorre las versiones de su carga y ve lo que se avisó por docente y por carrera, a 1440 y a 390 px', function () {
    $page = visit('/login')
        ->resize(1440, 1000)
        ->fill('username', 'jefe.versiones')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/versions')
        ->assertPresent('a[data-label="Versiones publicadas"]')
        ->assertSeeIn('@version-detail', 'Versión 2')
        ->assertSeeIn('@version-detail', 'Qué cambió contra la versión 1')
        ->assertSeeIn('@changes-added', 'Ecuaciones Diferenciales')
        ->assertSeeIn('@changes-cancelled', 'Cálculo Vectorial')
        ->assertSeeIn('@changes-teacher', 'Jorge Esparza Luna')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'versions-1440')
        ->click('@version-1')
        ->waitForText('Primera versión: no hay contra qué comparar.')
        ->assertSeeIn('@version-detail', 'Versión 1')
        ->click('@version-2')
        ->waitForText('Qué cambió contra la versión 1')
        ->click('@open-affected')
        ->waitForText('Versión 2: por docente o carrera')
        ->assertSeeIn('@affected-teachers', 'Ana Ruiz Varela')
        ->assertSeeIn('@affected-teachers', 'Ya no impartes ACF-0902 Cálculo Integral, grupo A (C2).')
        ->screenshot(filename: 'versions-drawer-1440')
        ->click('@affected-by-carrera')
        ->assertSeeIn('@affected-careers', 'Ingeniería en Sistemas Computacionales')
        ->assertScript("document.querySelector('[data-testid=\"affected-by-carrera\"]').getAttribute('aria-pressed')", 'true')
        ->assertNoJavaScriptErrors();

    $page->script("document.documentElement.dataset.theme = 'nexus-dark'");
    $page->screenshot(filename: 'versions-drawer-1440-dark')
        ->navigate('/academic-load/versions')
        ->script("document.documentElement.dataset.theme = 'nexus-dark'");
    $page->screenshot(filename: 'versions-1440-dark');

    $page->resize(390, 844)
        ->navigate('/academic-load/versions')
        ->assertSeeIn('@version-detail', 'Versión 2')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'versions-390')
        ->click('@version-1')
        ->waitForText('Primera versión: no hay contra qué comparar.')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'versions-390-v1')
        ->assertNoJavaScriptErrors();
});
