<?php

use App\Modules\Core\Actions\RemoveStaffPhoto;
use App\Modules\Core\Actions\SetStaffPhoto;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\OrgChartQuery;
use App\Modules\Core\Support\StaffPhotoUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Las fotos del personal (Core): CO-03 (guardarlas, nunca a un alumno, se conservan las anteriores) y CO-04 (URL firmada
| que vence). Imágenes ficticias de UploadedFile::fake().
*/

beforeEach(function () {
    Storage::fake('staff_photos');
    $this->unit = communicationUnit();
    $this->head = communicationHead();
    $this->person = User::factory()->create(['name' => 'Persona Ficticia']);
});

it('CO-03: guardar una foto conserva la anterior en el disco', function () {
    $set = app(SetStaffPhoto::class);

    $set->handle($this->person, UploadedFile::fake()->image('uno.jpg', 700, 900), $this->head);
    $first = $this->person->fresh()->photo_path;

    $this->travel(2)->seconds();
    $set->handle($this->person, UploadedFile::fake()->image('dos.png', 800, 1000), $this->head);
    $second = $this->person->fresh()->photo_path;

    expect($second)->not->toBe($first)
        ->and($this->person->fresh()->photo_updated_at)->not->toBeNull();
    Storage::disk('staff_photos')->assertExists($first);
    Storage::disk('staff_photos')->assertExists($second);
    Storage::disk('staff_photos')->assertExists(str_replace('.jpg', '-mini.jpg', $second));
    expect(Storage::disk('staff_photos')->allFiles($this->person->id))->toHaveCount(6);
    expect(Activity::query()->where('description', 'Cambió la foto')->count())->toBe(2);
});

it('CO-03: quitar la foto deja las columnas nulas y el archivo se queda', function () {
    app(SetStaffPhoto::class)->handle($this->person, UploadedFile::fake()->image('uno.jpg', 700, 900), $this->head);
    $path = $this->person->fresh()->photo_path;

    app(RemoveStaffPhoto::class)->handle($this->person, $this->head);

    expect($this->person->fresh()->photo_path)->toBeNull()
        ->and($this->person->fresh()->photo_updated_at)->toBeNull();
    Storage::disk('staff_photos')->assertExists($path);
});

it('CO-03: no se le pone foto a un usuario alumno', function () {
    $student = User::factory()->withRole(Role::Alumno)->create();

    expect(fn () => app(SetStaffPhoto::class)->handle($student, UploadedFile::fake()->image('a.jpg', 700, 900), $this->head))
        ->toThrow(ValidationException::class);
    expect($student->fresh()->photo_path)->toBeNull();
});

it('CO-04: la URL firmada sirve la foto con private, max-age=7200', function () {
    app(SetStaffPhoto::class)->handle($this->person, UploadedFile::fake()->image('uno.jpg', 700, 900), $this->head);

    $url = StaffPhotoUrl::for($this->person->fresh());

    $response = $this->actingAs($this->head)->get($url);

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=7200')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toBe('image/jpeg');
});

it('CO-04: una URL vencida o alterada da 403', function () {
    app(SetStaffPhoto::class)->handle($this->person, UploadedFile::fake()->image('uno.jpg', 700, 900), $this->head);
    $url = StaffPhotoUrl::for($this->person->fresh());

    $this->actingAs($this->head)->get($url.'x')->assertForbidden();
    $this->actingAs($this->head)->get(preg_replace('/signature=[a-f0-9]+/', 'signature=abc', $url))->assertForbidden();

    $this->travel(4)->hours();
    $this->actingAs($this->head)->get($url)->assertForbidden();
});

it('CO-04: la URL firmada no cambia dentro de la hora y siempre vale al menos 2 horas', function () {
    app(SetStaffPhoto::class)->handle($this->person, UploadedFile::fake()->image('uno.jpg', 700, 900), $this->head);
    $person = $this->person->fresh();

    $this->travelTo(now()->startOfHour()->addMinutes(1));
    $first = StaffPhotoUrl::for($person);
    $this->travelTo(now()->startOfHour()->addMinutes(55));
    $second = StaffPhotoUrl::for($person);

    expect($second)->toBe($first);

    // Aun a punto de cambiar de hora, la URL recién armada vale 2 horas.
    $this->travelTo(now()->startOfHour()->addMinutes(59)->addSeconds(59));
    $last = StaffPhotoUrl::for($person);
    $this->travel(2)->hours();
    $this->actingAs($this->head)->get($last)->assertOk();
});

it('CO-04: la miniatura de 150×200 sale por su propia URL firmada y el original por la suya', function () {
    app(SetStaffPhoto::class)->handle($this->person, UploadedFile::fake()->image('uno.jpg', 700, 900), $this->head);
    $person = $this->person->fresh();

    $mini = StaffPhotoUrl::for($person, mini: true);
    $response = $this->actingAs($this->head)->get($mini);

    $response->assertOk();
    $bytes = $response->streamedContent();
    expect($mini)->not->toBe(StaffPhotoUrl::for($person))->and($mini)->toContain('/mini')
        ->and(array_slice(getimagesizefromstring($bytes), 0, 2))->toBe([150, 200])
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=7200');
    $this->actingAs($this->head)->get($mini.'x')->assertForbidden();
});

it('CO-04: sin foto no hay URL y sin sesión no se sirve', function () {
    expect(StaffPhotoUrl::for($this->person))->toBeNull();

    app(SetStaffPhoto::class)->handle($this->person, UploadedFile::fake()->image('uno.jpg', 700, 900), $this->head);
    $this->get(StaffPhotoUrl::for($this->person->fresh()))->assertRedirect();
});

it('CO-04: la banda muestra la foto propia y las iniciales sin foto', function () {
    $this->actingAs($this->person);
    Livewire::test('core::components.band')->assertDontSeeHtml('data-testid="credential-photo"');

    app(SetStaffPhoto::class)->handle($this->person, UploadedFile::fake()->image('uno.jpg', 700, 900), $this->head);

    Livewire::actingAs($this->person->fresh())->test('core::components.band')
        ->assertSeeHtml('data-testid="credential-photo"')
        ->assertSeeHtml('/foto/');
});

it('CO-04: la ficha de la persona y el titular del Organigrama muestran la foto, y sin foto las iniciales', function () {
    $admin = User::factory()->admin()->create();
    $unit = communicationUnit();

    $this->actingAs($admin)->get(route('core.people.show', $this->head->id))->assertOk()->assertDontSee('data-testid="person-photo"', false);
    expect(app(OrgChartQuery::class)->detail($unit->id)->titular['photo'])->toBeNull();

    app(SetStaffPhoto::class)->handle($this->head, UploadedFile::fake()->image('jefa.jpg', 700, 900), $admin);

    $this->actingAs($admin)->get(route('core.people.show', $this->head->id))->assertOk()->assertSee('data-testid="person-photo"', false);
    expect(app(OrgChartQuery::class)->detail($unit->id)->titular['photo'])->toContain('/foto/');
});
