<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Jobs\GeneratePublishedExport;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| «Preparando el archivo» cuando la cola no responde (incidencia F-05 del ensayo 8.11): un invitado pide el PDF de un
| docente, la cola nunca lo arma y, pasada la espera, la página deja de esperar, dice que tarda más de lo normal y
| «Reintentar» lo vuelve a encolar y regresa a la espera.
*/

beforeEach(function () {
    Storage::fake(PublishedExports::DISK);
    $term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027']);
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->teacherId = (string) Str::uuid7();
    $load = AcademicLoad::factory()->for($term)->state(['modality_id' => modalityOf('PRES')->id])->for(Department::factory())->inStatus(LoadStatus::Published)->create();

    LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse('2026-12-12 10:30'))->withGroups([
        LoadSnapshotFactory::group([
            'code' => 'B',
            'subject' => ['id' => (string) Str::uuid7(), 'code' => 'ACF-0904', 'name' => 'Cálculo Vectorial', 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => (string) Str::uuid7()],
            'teacher' => ['id' => $this->teacherId, 'rfc' => 'RIML800101AB1', 'name' => 'Laura Ríos Medina'],
            'sessions' => [LoadSnapshotFactory::session(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'])],
        ]),
    ])->create();
});

it('si la cola no responde, la espera vence con un aviso y «Reintentar» vuelve a encolar el PDF', function () {
    Queue::fake();
    $path = "/horarios/20271/pres/docente/{$this->teacherId}/pdf?campus=C2";

    $page = visit($path)
        ->assertSee('Preparando el archivo')
        ->assertSee('se descarga solo en unos segundos');

    $this->travel(PublishedExports::WAIT_SECONDS + 1)->seconds();

    $page->navigate($path)
        ->assertSee('El archivo está tardando más de lo normal')
        ->assertDontSee('se descarga solo en unos segundos')
        ->click('Reintentar')
        ->assertSee('Preparando el archivo')
        ->assertNoJavaScriptErrors();

    Queue::assertPushed(GeneratePublishedExport::class, 2);
});
