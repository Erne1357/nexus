<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/*
| Avance de validación en el navegador: Subdirección entra por el menú, abre el drawer de un departamento y le
| recuerda a su jefe sus catálogos pendientes.
*/

it('Subdirección abre el tablero, el drawer de un departamento y le recuerda al jefe', function () {
    $term = Term::factory()->current()->create();
    $campus = Campus::factory()->create(['name' => 'Campus II']);
    TermOpening::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id]);
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    app(CreateTermReviews::class)->handle($term, $campus);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sis->id])->create(['name' => 'Dra. Ana Soto']);
    DepartmentHead::factory()->create(['department_id' => $sis->id, 'user_id' => $jefe->id]);
    User::factory()->withRole(Role::Subdireccion, [])->create(['username' => 'subdireccion']);

    visit('/login')
        ->fill('username', 'subdireccion')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[data-label="Avance de validación"]')
        ->assertPathIs('/catalog-validation/progress')
        ->assertSee('0 de 5')
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
