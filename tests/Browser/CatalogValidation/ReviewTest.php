<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Spatie\Activitylog\Models\Activity;

/*
| Validación de catálogos en el navegador, opción A «Tabla con columna Revisión» (12 de noviembre de 2026): el jefe de
| departamento entra por el menú, mueve un salón a un edificio de otro campus desde su drawer (el campus sigue al
| edificio y queda revisado, CV-S10, CV7); en Docentes escribe en la búsqueda sin que los atajos corran, marca registros
| con el teclado (J, Espacio, R), captura un correo institucional en el recuadro de la fila (AC-06), quita a un
| docente de su departamento desde «Acciones», con su consecuencia y el motivo «Jubilación» (CV8, D8, D10), marca los
| que faltan juntos con «Elegir los N por revisar» y, con todo revisado, valida el catálogo desde «Revisaste los N
| docentes». En la segunda prueba, el jefe valida en un paso el catálogo sin registros (CV7, D5) y, con Docentes ya
| validado, sigue el enlace «N sin correo institucional» y captura el correo en la fila: la revisión sigue validada
| (CV-S2, D11), y ve quién validó sin recargar. En la tercera, el jefe de Servicios Escolares, que valida las materias
| (CV15), abre una materia con «Agregar observación», elige el tipo
| y oprime «Guardar»: no guarda y le pide agregarla o quitarla (CV13); la agrega y entonces guarda. En la cuarta, en
| celular, elige el tipo, sube a corregir el nombre y oprime «Guardar»: la vista baja al tipo, con su error y el foco.
*/

it('el jefe revisa salones y docentes registro por registro, con teclado y en lote, y valida', function () {
    $term = Term::factory()->current()->create();
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle($term, Catalog::cases());
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sis->id])->create(['username' => 'jefe']);

    $room = Room::factory()->for(Building::factory()->create(['campus_id' => $c2->id, 'code' => 'E2', 'name' => 'Edificio 2']))->create(['code' => 'Y-104', 'name' => 'Aula Y-104', 'department_id' => $sis->id]);
    $north = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'LC', 'name' => 'Laboratorio de cómputo']);

    foreach (['AAAA800101AA1' => 'Adriana Beltrán', 'BBBB800101BB1' => 'Beatriz Olivas', 'CCCC800101CC1' => 'Carlos Mendoza', 'DDDD800101DD1' => 'Diana Trejo', 'FFFF800101FF1' => 'Francisco Lerma'] as $rfc => $name) {
        Teacher::factory()->adscribedTo($sis)->create(['rfc' => $rfc, 'name' => $name, 'institutional_email' => strtolower(substr($rfc, 0, 4)).'@cdjuarez.tecnm.mx']);
    }
    $leaving = Teacher::factory()->adscribedTo($sis)->create(['rfc' => 'EEEE800101EE1', 'name' => 'Elena Saucedo', 'institutional_email' => 'eeee@cdjuarez.tecnm.mx']);
    $withoutEmail = Teacher::factory()->adscribedTo($sis)->create(['rfc' => 'ZZZZ800101ZZ1', 'name' => 'Daniel Esparza']);

    $page = visit('/login')
        ->fill('username', 'jefe')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('@hare')
        ->click('#nexus-menu a[data-label="Validar catálogos"]')
        ->assertPathIs('/catalog-validation')
        ->click('@tab-rooms')
        ->waitForText('0 de 1 revisados')
        ->click('@edit-Y-104')
        ->select('#room-building', $north->id)
        ->waitForText('con el edificio.')
        ->assertSeeIn('@room-campus', 'Campus I')
        ->click('@save-record')
        ->waitForText('Salón actualizado')
        ->assertSee('Revisaste el único salón.');

    expect($room->fresh())->building_id->toBe($north->id)->campus_id->toBe($c1->id);

    $page->click('@tab-teachers')
        ->waitForText('0 de 7 revisados')
        ->type('input[aria-label="Buscar registros"]', 'jr r')
        ->assertScript("document.activeElement.getAttribute('aria-label') === 'Buscar registros'")
        ->assertDontSee('Marcar 1 como revisado')
        ->clear('input[aria-label="Buscar registros"]')
        ->waitForText('Esparza')
        ->assertSee('0 de 7 revisados');

    $page->script('document.activeElement.blur()');

    $page->keys('@review-table', 'j')
        ->keys('tr[data-row]:focus', ' ')
        ->keys('tr[data-row]:focus', 'j')
        ->keys('tr[data-row]:focus', ' ')
        ->assertSee('Marcar 2 como revisados')
        ->keys('tr[data-row]:focus', 'r')
        ->waitForText('2 de 7 revisados')
        ->assertScript("document.activeElement.getAttribute('aria-label').startsWith('DDDD800101DD1')")
        ->keys('tr[data-row]:focus', 'r')
        ->waitForText('3 de 7 revisados')
        ->click('@add-email-ZZZZ800101ZZ1')
        ->fill('#review-email-input', 'daniel.esparza')
        ->click('@save-email')
        ->waitForText('Correo institucional guardado')
        ->assertSee('4 de 7 revisados')
        ->click('table [aria-label="Acciones de EEEE800101EE1"]')
        ->click('@discharge-EEEE800101EE1')
        ->waitForText('¿Quitar a Elena Saucedo de Sistemas y Computación?')
        ->assertSee('Es su único departamento: queda dado de baja y su cuenta se inhabilita.')
        ->select('select[data-testid="discharge-kind"]', 'retirement')
        ->click('@confirm-discharge')
        ->waitForText('Docente dado de baja')
        ->assertSee('4 de 6 revisados')
        ->click('@select-FFFF800101FF1')
        ->click('@choose-all-pending')
        ->assertSee('Marcar 2 como revisados')
        ->click('@mark-selected')
        ->waitForText('Revisaste los 6 docentes.')
        ->click('@validate-teachers-done')
        ->click('@confirm-accept')
        ->waitForText('Revisión validada')
        ->assertNoJavaScriptErrors();

    $review = CatalogReview::query()->where('catalog', Catalog::Teachers)->sole();

    expect($review->status)->toBe(ReviewStatus::Validated)
        ->and(CatalogReviewMark::query()->where('catalog_review_id', $review->id)->count())->toBe(6)
        ->and($withoutEmail->fresh()->institutional_email)->toBe('daniel.esparza@cdjuarez.tecnm.mx')
        ->and($leaving->fresh()->is_active)->toBeFalse()
        ->and(Activity::query()->where('event', 'discharged')->sole()->properties['reason'])->toBe(['kind' => 'retirement']);
});

it('el jefe valida en un paso los catálogos sin registros y, ya validados sus docentes, captura un correo desde «sin correo institucional»', function () {
    $term = Term::factory()->current()->create();
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $cb = Department::factory()->create(['code' => '110100', 'name' => 'Ciencias Básicas']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle($term, Catalog::cases());
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $cb->id])->create(['username' => 'jefe', 'name' => 'Laura Ramos']);

    $teacher = Teacher::factory()->adscribedTo($cb)->create(['rfc' => 'AAAA800101AA1', 'name' => 'Adriana Beltrán', 'institutional_email' => null]);
    Teacher::factory()->adscribedTo($cb)->create(['rfc' => 'BBBB800101BB1', 'name' => 'Beatriz Olivas', 'institutional_email' => 'bbbb@cdjuarez.tecnm.mx']);
    Subject::factory()->create(['code' => 'AEC-1058', 'name' => 'Química', 'department_id' => $cb->id]);

    $page = visit('/login')
        ->fill('username', 'jefe')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('@hare')
        ->click('#nexus-menu a[data-label="Validar catálogos"]')
        ->assertPathIs('/catalog-validation')
        ->assertSeeIn('@empty-reviews', '1 catálogo sin registros')
        ->assertSeeIn('@empty-reviews', 'Salones')
        ->click('@validate-empty')
        ->waitForText('Vas a validar Salones de Ciencias Básicas')
        ->click('@confirm-empty')
        ->waitForText('1 catálogo validado')
        ->assertMissing('@empty-reviews')
        ->assertSee('1 de 2');

    expect(CatalogReview::query()->where('status', ReviewStatus::Validated)->pluck('catalog')->map->value->sort()->values()->all())->toBe(['rooms']);

    $page->click('@tab-teachers')
        ->waitForText('0 de 2 revisados')
        ->click('@select-page')
        ->click('@mark-selected')
        ->waitForText('Revisaste los 2 docentes.')
        ->click('@validate-teachers-done')
        ->click('@confirm-accept')
        ->waitForText('Revisión validada')
        ->assertSee('Validó Laura Ramos')
        ->assertDontSee('un usuario ya dado de baja')
        ->click('@tab-board')
        ->click('@missing-correo')
        ->waitForText('Adriana Beltrán')
        ->assertDontSee('Beatriz Olivas')
        ->click('@add-email-AAAA800101AA1')
        ->assertSeeIn('@email-popover', 'Al guardar le llega su invitación a Nexus')
        ->fill('#review-email-input', 'adriana.beltran')
        ->click('@save-email')
        ->waitForText('Correo institucional guardado')
        ->assertNoJavaScriptErrors();

    expect($teacher->fresh()->institutional_email)->toBe('adriana.beltran@cdjuarez.tecnm.mx')
        ->and(CatalogReview::query()->where('catalog', Catalog::Teachers)->sole()->status)->toBe(ReviewStatus::Validated)
        ->and($jefe->exists)->toBeTrue();
});

it('CV13: el jefe no pierde una observación a medias al oprimir «Guardar»: la agrega y luego guarda', function () {
    Term::factory()->current()->create();
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    TermOpening::factory()->create(['term_id' => Term::query()->sole()->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle(Term::query()->sole(), [Catalog::Subjects]);
    schoolServicesHead(['username' => 'jefe']);
    $subject = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Programación Orientada a Objetos', 'department_id' => $sis->id]);

    $page = visit('/login')
        ->fill('username', 'jefe')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/catalog-validation?catalogo=subjects')
        ->waitForText('0 de 1 revisados')
        ->click('table [aria-label="Acciones de SCD-1027"]')
        ->click('@flag-SCD-1027')
        ->waitForText('Tipo de observación')
        ->select('flag', 'missing_data')
        ->fill('comment', 'Faltan las horas de práctica.')
        ->click('@save-record')
        ->waitForText('Agrega la observación o quítala antes de guardar.')
        ->assertVisible('@record-panel')
        ->assertSee('0 de 1 revisados')
        ->click('@add-flag')
        ->waitForText('Observación agregada')
        ->assertSeeIn('@flags-section', 'Faltan las horas de práctica.')
        ->click('@save-record')
        ->waitForText('Sin cambios: queda revisado')
        ->assertSee('1 de 1 revisados')
        ->assertNoJavaScriptErrors();

    expect(CatalogReviewMark::query()->where('record_id', $subject->id)->exists())->toBeTrue();
});

it('CV13: en celular, «Guardar» con el tipo elegido y la vista arriba lleva la vista y el foco al tipo con su error', function () {
    Term::factory()->current()->create();
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    TermOpening::factory()->create(['term_id' => Term::query()->sole()->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle(Term::query()->sole(), [Catalog::Subjects]);
    schoolServicesHead(['username' => 'jefe']);
    $subject = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Programación Orientada a Objetos', 'department_id' => $sis->id]);

    $page = visit('/login')
        ->resize(390, 844)
        ->fill('username', 'jefe')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/catalog-validation?catalogo=subjects')
        ->waitForText('0 de 1 revisados')
        ->click('[data-testid="record-cards"] [aria-label="Editar SCD-1027"]')
        ->waitForText('Tipo de observación')
        ->select('flag', 'missing_data');

    $page->script('document.querySelector(\'[name="name"]\').scrollIntoView({ block: \'center\' })');

    $page->fill('name', 'Programación Orientada a Objetos I')
        ->click('@save-record')
        ->waitForText('Agrega la observación o quítala antes de guardar.')
        ->wait(0.5)
        ->assertScript(<<<'JS'
            (() => {
                const field = document.querySelector('[name="flag"]');
                const box = field.getBoundingClientRect();
                const footer = document.querySelector('[data-testid="save-record"]').closest('footer').getBoundingClientRect();
                return box.top >= 0 && box.bottom <= footer.top && document.activeElement === field;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();

    expect($subject->fresh()->name)->toBe('Programación Orientada a Objetos')
        ->and(CatalogReviewMark::query()->where('record_id', $subject->id)->exists())->toBeFalse();
});
