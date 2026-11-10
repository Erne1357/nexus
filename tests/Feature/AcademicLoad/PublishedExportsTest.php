<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\ExportState;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Jobs\GeneratePublishedExport;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AcademicLoad\FakePublishedExport;

/*
| PublishedExports (tareas 4.5 y 4.6): los archivos de lo publicado se arman en la cola, una vez por llave, y se
| descargan mientras la llave (las versiones que leen) no cambie. Los PDF de semestre, docente y salón son públicos;
| los demás piden su permiso en el campus y la unidad (decisión del usuario del 10 de noviembre de 2026).
*/

beforeEach(function () {
    Storage::fake(PublishedExports::DISK);
    $this->counter = tempnam(sys_get_temp_dir(), 'export-builds-');
    $this->exports = app(PublishedExports::class);
});

afterEach(fn () => @unlink($this->counter));

it('arma el exporte en la cola y lo deja listo para descargar con su nombre y tipo', function () {
    $export = new FakePublishedExport(counter: $this->counter);

    expect($this->exports->state($export))->toBe(ExportState::Missing)
        ->and($this->exports->request($export))->toBe(ExportState::Ready)
        ->and(Storage::disk(PublishedExports::DISK)->get('published/fake-20271-C2-abc.pdf'))->toBe('%PDF-1.7 prueba fake-20271-C2-abc');

    $response = $this->exports->download($export);

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('Horario de prueba.pdf');
});

it('no vuelve a armar un exporte listo', function () {
    $export = new FakePublishedExport(counter: $this->counter);

    $this->exports->request($export);
    $this->exports->request($export);

    expect(file_get_contents($this->counter))->toBe('.');
});

it('mientras espera en la cola dice que se está preparando y no lo encola dos veces', function () {
    Queue::fake();
    $export = new FakePublishedExport;

    expect($this->exports->request($export))->toBe(ExportState::Pending)
        ->and($this->exports->request($export))->toBe(ExportState::Pending);

    Queue::assertPushed(GeneratePublishedExport::class, 1);
    Queue::assertPushed(fn (GeneratePublishedExport $job): bool => $job->uniqueId() === 'fake-20271-C2-abc');
});

it('si falla queda fallido con un mensaje sin el error interno, y se puede volver a pedir', function () {
    $failing = new FakePublishedExport(fails: true);

    expect($this->exports->request($failing))->toBe(ExportState::Failed)
        ->and($this->exports->failure($failing))->toBe('No se pudo generar el archivo. Vuelve a intentarlo en unos minutos.')
        ->and($this->exports->request(new FakePublishedExport))->toBe(ExportState::Ready)
        ->and($this->exports->failure($failing))->toBeNull();
});

it('no entrega un exporte que no está listo', function () {
    $this->exports->download(new FakePublishedExport);
})->throws(NotFoundHttpException::class);

it('rechaza una llave que no sirve como nombre de archivo', function () {
    $this->exports->path(new FakePublishedExport(key: '../otro'));
})->throws(InvalidArgumentException::class);

it('deja descargar a cualquiera lo público y lo demás solo con el permiso en su campus y unidad', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $public = new FakePublishedExport;
    $staffOnly = new FakePublishedExport(permission: AcademicLoadPermission::LoadView, campusId: $c2->id, unitId: departmentKey(10));
    $head = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $c2->id, 'department_id' => departmentKey(10)])->create();
    $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $c2->id, 'department_id' => departmentKey(11)])->create();
    $student = User::factory()->withRole(Role::Docente)->create();

    expect($this->exports->allows(null, $public))->toBeTrue()
        ->and($this->exports->allows(null, $staffOnly))->toBeFalse()
        ->and($this->exports->allows($head, $staffOnly))->toBeTrue()
        ->and($this->exports->allows($otherHead, $staffOnly))->toBeFalse()
        ->and($this->exports->allows($student, $staffOnly))->toBeFalse();
});
