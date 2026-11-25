<?php

use App\Modules\Communication\Enums\PhotoBatchStatus;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Actions\SetStaffPhoto;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| «Fotos del personal» (`/comunicacion/fotos-personal`, communication::pages.staff-photos). CO-03 (C11): la jefa y el
| personal de Comunicación suben y quitan las fotos del personal; nadie más entra. Personas y archivos ficticios.
*/

beforeEach(function () {
    Storage::fake('staff_photos');
    Storage::fake('imports');
    $this->head = communicationHead(['name' => 'Carla Comunicación']);
    $this->ana = User::factory()->create(['name' => 'Ana Ficticia', 'username' => 'ANAA800101AAA']);
    $this->luis = User::factory()->create(['name' => 'Luis Inventado', 'username' => 'LUIS750202BBB']);
});

it('CO-03: Comunicación sube y quita una foto desde el drawer', function () {
    Livewire::actingAs($this->head)->test('communication::pages.staff-photos')
        ->assertOk()
        ->assertSee('Ana Ficticia')
        ->call('openPhoto', $this->ana->id)
        ->set('photo', UploadedFile::fake()->image('ana.jpg', 700, 900))
        ->assertSeeHtml('data-testid="photo-preview"')
        ->call('savePhoto')
        ->assertHasNoErrors()
        ->assertSet('showPhoto', false);

    expect($this->ana->fresh()->photo_path)->not->toBeNull();

    Livewire::actingAs($this->head)->test('communication::pages.staff-photos')
        ->call('openRemove', $this->ana->id)
        ->assertSeeHtml('data-testid="photo-remove-confirm"')
        ->call('removePhoto')
        ->assertSet('showPhoto', false);

    expect($this->ana->fresh()->photo_path)->toBeNull();
});

it('CO-03: una foto que no es imagen da un error junto al campo', function () {
    Livewire::actingAs($this->head)->test('communication::pages.staff-photos')
        ->call('openPhoto', $this->ana->id)
        ->set('photo', UploadedFile::fake()->createWithContent('ana.jpg', '%PDF-1.4 no soy imagen'))
        ->call('savePhoto')
        ->assertHasErrors('photo');

    expect($this->ana->fresh()->photo_path)->toBeNull();
});

it('CO-03: el personal de Comunicación también entra, y no abre a un alumno', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['name' => 'Alumno Ficticio']);

    Livewire::actingAs(communicationStaff())->test('communication::pages.staff-photos')
        ->assertOk()
        ->assertDontSee('Alumno Ficticio')
        ->call('openPhoto', $student->id)
        ->assertNotFound();
});

it('filtra Sin foto / Con foto y busca por nombre o usuario', function () {
    app(SetStaffPhoto::class)->handle($this->ana, UploadedFile::fake()->image('ana.jpg', 700, 900), $this->head);

    Livewire::actingAs($this->head)->test('communication::pages.staff-photos')
        ->set('photoFilter', 'con')
        ->assertSee('Ana Ficticia')
        ->assertDontSee('Luis Inventado')
        ->set('photoFilter', 'sin')
        ->assertDontSee('Ana Ficticia')
        ->assertSee('Luis Inventado')
        ->set('photoFilter', '')
        ->set('search', 'luis75')
        ->assertSee('Luis Inventado')
        ->assertDontSee('Ana Ficticia');
});

it('CO-S5: sube un ZIP y muestra el reporte por archivo', function () {
    $zip = zipOf(['ANAA800101AAA.jpg' => batchImage(), 'NADIE000000XXX.jpg' => batchImage()]);

    Livewire::actingAs($this->head)->test('communication::pages.staff-photos')
        ->call('openZip')
        ->set('zip', $zip)
        ->call('uploadZip')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-testid="zip-report"')
        ->assertSee('ANAA800101AAA.jpg')
        ->assertSee('Sin persona');

    expect($this->ana->fresh()->photo_path)->not->toBeNull();
});

it('CO-S5: mientras el lote está en cola la pantalla lo sondea cada 2 segundos', function () {
    $batch = PhotoBatch::factory()->status(PhotoBatchStatus::Procesando)->create(['uploaded_by' => $this->head->id, 'total' => 10, 'processed' => 4]);

    Livewire::actingAs($this->head)->test('communication::pages.staff-photos')
        ->call('openZip')
        ->call('showBatch', $batch->id)
        ->assertSeeHtml('wire:poll.2s')
        ->assertSee('4 de 10');
});

it('CO-03: otro jefe no ve la pantalla ni entra a la ruta', function () {
    $unit = OrgUnit::factory()->create();
    $other = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $unit->id])->create();
    $student = User::factory()->withRole(Role::Alumno)->create();

    foreach ([$other, $student] as $user) {
        Livewire::actingAs($user)->test('communication::pages.staff-photos')->assertForbidden();
    }

    $this->actingAs($other)->get(route('communication.staff-photos'))->assertForbidden();
});

it('CO-03: la ruta de Comunicación la ve su jefa y aparece en su menú', function () {
    $this->actingAs($this->head)->get(route('communication.staff-photos'))->assertOk()->assertSee('Fotos del personal');
});
