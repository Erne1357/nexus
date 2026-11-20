<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Notifications\DatabaseNotification;

/*
| Avance de validación en el navegador: Subdirección entra por el menú, abre el drawer de un departamento y le
| recuerda a su jefe sus catálogos pendientes; entra por la tarjeta de Inicio, ve la fecha límite y resuelve, desde
| «Observaciones abiertas», la de otro departamento con la nota de lo que se hizo (D3, D6 y D7).
*/

it('Subdirección abre el tablero, el drawer de un departamento y le recuerda al jefe', function () {
    $term = Term::factory()->current()->create();
    $campus = Campus::factory()->create(['name' => 'Campus II']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    app(CreateTermReviews::class)->handle($term, Catalog::cases());
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sis->id])->create(['name' => 'Dra. Ana Soto']);
    User::factory()->withRole(Role::Subdireccion, [])->create(['username' => 'subdireccion']);

    visit('/login')
        ->fill('username', 'subdireccion')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[data-label="Avance de validación"]')
        ->assertPathIs('/catalog-validation/progress')
        ->assertSee('0 de 5')
        ->assertSee('Salones')
        ->assertPresent('@column-rooms')
        ->assertPresent('@progress-legend')
        ->assertSee('Dra. Ana Soto')
        ->click('@open-SIS')
        ->assertSee('Último recordatorio')
        ->assertSee('nunca')
        ->click('@drawer-remind')
        ->waitForText('Se avisó a 1 jefe')
        ->assertSee('hoy')
        ->assertNoJavaScriptErrors();

    expect(DatabaseNotification::query()->where('notifiable_id', $jefe->id)->count())->toBe(1);
});

it('CV9: el admin ve que la validación no está abierta, abre dos catálogos en el drawer y aparecen en el tablero', function () {
    $term = Term::factory()->current()->create();
    $campus = Campus::factory()->create(['name' => 'Campus II']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    User::factory()->admin()->create(['username' => 'admin']);

    visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[data-label="Avance de validación"]')
        ->assertSee('La validación de este periodo no está abierta')
        ->click('@open-validation-empty')
        ->assertSee('Los jefes de cada departamento revisan')
        ->check('input[value="teachers"]')
        ->check('input[value="rooms"]')
        ->click('@open-submit')
        ->waitForText('Validación abierta')
        ->assertPresent('@column-teachers')
        ->assertPresent('@column-rooms')
        ->assertMissing('@column-careers')
        // La página se volvió a pintar: la franja de pantalla oculta ya no dice que no hay validación abierta.
        ->assertDontSee('No hay validación abierta')
        ->assertNoJavaScriptErrors();
});

it('CV10 y CV11: Subdirección recuerda a un jefe por correo y resuelve una observación de otro con la nota de lo que se hizo', function (int $width) {
    $this->travelTo(now()->setDate(2026, 11, 18)->startOfDay());
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);
    $term = Term::factory()->current()->create();
    Campus::factory()->create(['name' => 'Campus II']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    TermStage::factory()->create(['term_id' => $term->id, 'stage' => Stage::CatalogValidation, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-27']);
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica y Electrónica']);
    app(CreateTermReviews::class)->handle($term, [Catalog::Teachers]);
    $jefeSis = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sis->id])->create(['name' => 'Dra. Ana Soto', 'email' => 'ana.soto@cdjuarez.tecnm.mx']);
    $jefeEle = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $ele->id])->create(['name' => 'Ing. Raúl Terrazas']);
    $teacher = Teacher::factory()->adscribedTo($ele)->create(['rfc' => 'ELCA800101AA1', 'name' => 'Elsa Carrillo']);
    $flag = CatalogReviewFlag::factory()->on($teacher)->create([
        'review_id' => CatalogReview::query()->where('department_id', $ele->id)->sole()->id,
        'flag' => ReviewFlag::Duplicate,
        'flagged_by' => $jefeEle->id,
    ]);
    User::factory()->withRole(Role::Subdireccion, [])->create(['username' => 'subdireccion']);

    $page = visit('/login')
        ->resize($width, 900)
        ->fill('username', 'subdireccion')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertSee('0 de 2 departamentos validados')
        ->click('Ver avance')
        ->assertPathIs('/catalog-validation/progress')
        ->assertSee('Fecha límite: 27 nov')
        ->assertDontSee('Campus II abierto')
        ->click('@remind-all')
        ->waitForText('Enviando recordatorios a 2 departamentos');

    $page->click('@open-all-flags')
        ->waitForText('Observaciones abiertas (1)')
        ->assertSee('Eléctrica y Electrónica')
        ->assertSee('ELCA800101AA1')
        ->click('@resolve-flag-'.$flag->id)
        ->fill('@resolve-note-'.$flag->id, 'Se dio de baja la repetida.')
        ->click('@resolve-submit-'.$flag->id)
        ->waitForText('Observación resuelta')
        ->assertSee('Sin observaciones abiertas')
        ->assertNoJavaScriptErrors();

    expect($flag->fresh()->resolution_note)->toBe('Se dio de baja la repetida.')
        ->and($jefeSis->notifications()->sole()->data['title'])->toBe('Recordatorio: te faltan catálogos por validar')
        ->and($jefeEle->notifications()->pluck('data')->pluck('title')->sort()->values()->all())->toBe(['Recordatorio: te faltan catálogos por validar', 'Se resolvió tu observación']);
})->with(['celular' => 390, 'escritorio' => 1440]);
