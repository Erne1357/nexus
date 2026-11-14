<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Queries\ImportBatchQuery;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TeacherEmailsTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| AC-19: importar los correos institucionales de los docentes desde el archivo de TI (tarea 1.21, D4, confirmado el 14
| de noviembre de 2026): RFC y correo, solo el admin. Cada fila guarda el correo con UpdateTeacher, la misma Action que
| la ficha (AC-06), así que la invitación sale por el mismo camino: si su departamento ya validó su catálogo de
| docentes, en ese momento (AC-09); si no, al validar. Si el docente ya tiene otro correo, no se cambia (D14, supuesto
| por confirmar). El resumen dice cuántos actualizó y cuántos no cambiaron, los RFC que no están en Nexus, los correos
| fuera del dominio, los repetidos, los que ya tenían otro correo y cuántas invitaciones salieron.
*/

beforeEach(function () {
    Storage::fake('imports');
    Notification::fake();
    $this->term = Term::factory()->current()->create();
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->ii = Department::factory()->create(['code' => 'II']);
    $this->admin = User::factory()->admin()->create();
    $this->context = new ImportContext($this->admin);
    $this->template = app(TeacherEmailsTemplate::class);

    // SC ya validó su catálogo de docentes; II todavía no.
    CatalogReview::factory()->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->sc->id, 'catalog' => Catalog::Teachers]);
    CatalogReview::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->ii->id, 'catalog' => Catalog::Teachers]);
});

/**
 * Un docente activo de `$department` con usuario sin acceso y, si se da, correo institucional.
 */
function teacherForEmails(Department $department, string $rfc, ?string $email = null): Teacher
{
    $user = User::factory()->withRole(Role::Docente)->create(['username' => $rfc, 'email' => null, 'password' => null, 'must_change_password' => true]);

    return Teacher::factory()->adscribedTo($department)->create(['rfc' => $rfc, 'institutional_email' => $email, 'user_id' => $user->id]);
}

/**
 * El lote del archivo de ejemplo anonimizado, validado.
 */
function validatedEmailsBatch(User $admin): ImportBatch
{
    $batch = app(StoreImportBatch::class)->handle(
        $admin,
        'teacher_emails',
        UploadedFile::fake()->createWithContent('correos.csv', (string) file_get_contents(base_path('tests/Fixtures/imports/teacher_emails.csv'))),
    );

    return app(ValidateImportBatch::class)->handle($batch);
}

/**
 * Los docentes del archivo de ejemplo: uno de SC y uno de II sin correo, uno que ya tiene el del archivo y uno que tiene
 * otro; PRUE099999AB1 no está en Nexus.
 */
function emailsFixtureTeachers(): void
{
    teacherForEmails(test()->sc, 'PRUE090001AB1');
    teacherForEmails(test()->ii, 'PRUE090002AB1');
    teacherForEmails(test()->sc, 'PRUE090003AB1', 'docente.tres@cdjuarez.tecnm.mx');
    teacherForEmails(test()->sc, 'PRUE090004AB1', 'docente.cuatro@cdjuarez.tecnm.mx');
    teacherForEmails(test()->sc, 'PRUE090005AB1');
    teacherForEmails(test()->sc, 'PRUE090006AB1');
    teacherForEmails(test()->sc, 'PRUE090007AB1');
}

/**
 * @return array<string, array{count: int, keys: list<string>}>
 */
function emailsSummary(ImportBatch $batch): array
{
    return collect(app(ImportBatchQuery::class)->summary($batch->fresh(), app(TeacherEmailsTemplate::class)))
        ->mapWithKeys(fn (array $group): array => [$group['label'] => ['count' => $group['count'], 'keys' => $group['keys']]])
        ->all();
}

it('AC-19: está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('teacher_emails'))
        ->toBeInstanceOf(TeacherEmailsTemplate::class)
        ->label()->toBe('Correos institucionales de docentes');
});

describe('validar una fila', function () {
    it('AC-06: un docente sin correo y un correo del dominio no traen errores ni advertencias; el mismo que ya tiene, tampoco', function () {
        teacherForEmails($this->sc, 'PRUE000001AB1');
        teacherForEmails($this->sc, 'PRUE000002AB1', 'beto@cdjuarez.tecnm.mx');

        foreach ([['PRUE000001AB1', 'ana@cdjuarez.tecnm.mx'], ['PRUE000002AB1', 'Beto@CDJuarez.TecNM.mx']] as [$rfc, $email]) {
            $issues = $this->template->validate($this->template->normalize(['rfc' => $rfc, 'email' => $email]), $this->context);

            expect($issues->errors)->toBe([])->and($issues->warnings)->toBe([]);
        }
    });

    it('AC-19: rechaza lo que no se aplica: RFC que no está, otro dominio, el correo de otro docente y el que ya tiene otro correo (D14)', function (array $row, string $field, string $message) {
        teacherForEmails($this->sc, 'PRUE000001AB1');
        teacherForEmails($this->sc, 'PRUE000002AB1', 'beto@cdjuarez.tecnm.mx');

        expect($this->template->validate($this->template->normalize($row), $this->context)->errors)->toBe([$field => [$message]]);
    })->with([
        'RFC que no está en Nexus' => [['rfc' => 'PRUE099999AB1', 'email' => 'nadie@cdjuarez.tecnm.mx'], 'rfc', TeacherEmailsTemplate::NOT_FOUND],
        'otro dominio' => [['rfc' => 'PRUE000001AB1', 'email' => 'ana@itcj.edu.mx'], 'email', 'El correo institucional debe terminar en @cdjuarez.tecnm.mx.'],
        'no es correo' => [['rfc' => 'PRUE000001AB1', 'email' => 'ana'], 'email', 'El correo institucional debe terminar en @cdjuarez.tecnm.mx.'],
        'de otro docente' => [['rfc' => 'PRUE000001AB1', 'email' => 'BETO@cdjuarez.tecnm.mx'], 'email', 'Otro docente ya tiene el correo institucional beto@cdjuarez.tecnm.mx.'],
        'ya tiene otro correo' => [['rfc' => 'PRUE000002AB1', 'email' => 'beto.ruiz@cdjuarez.tecnm.mx'], 'email', 'Ya tiene otro correo institucional (beto@cdjuarez.tecnm.mx): no se cambia.'],
    ]);

    it('AC-19: un docente borrado es error y uno dado de baja solo avisa', function () {
        teacherForEmails($this->sc, 'PRUE000001AB1')->delete();
        teacherForEmails($this->sc, 'PRUE000002AB1')->update(['is_active' => false]);

        expect($this->template->validate(['rfc' => 'PRUE000001AB1', 'email' => 'ana@cdjuarez.tecnm.mx'], $this->context)->errors)->toHaveKey('rfc')
            ->and($this->template->validate(['rfc' => 'PRUE000002AB1', 'email' => 'beto@cdjuarez.tecnm.mx'], $this->context))
            ->errors->toBe([])->warnings->toHaveKey('rfc');
    });

    it('AC-19: sin correo es error del campo', function () {
        teacherForEmails($this->sc, 'PRUE000001AB1');

        expect($this->template->validate($this->template->normalize(['rfc' => 'PRUE000001AB1', 'email' => ' ']), $this->context)->errors)->toHaveKey('email');
    });
});

describe('aplicar', function () {
    it('AC-06: guarda el correo con UpdateTeacher, como la ficha: queda en la bitácora del docente a nombre de quien importa', function () {
        $teacher = teacherForEmails($this->ii, 'PRUE000001AB1');

        $applied = $this->template->apply(['rfc' => 'PRUE000001AB1', 'email' => 'ana@cdjuarez.tecnm.mx'], $this->context);

        expect($applied->is($teacher))->toBeTrue()
            ->and($applied->wasChanged())->toBeTrue()
            ->and($teacher->fresh()->institutional_email)->toBe('ana@cdjuarez.tecnm.mx')
            ->and(Activity::query()->where('subject_id', $teacher->id)->where('event', 'updated')->sole())
            ->causer_id->toBe($this->admin->id)
            ->description->toBe('Actualizó al docente PRUE000001AB1');
    });

    it('AC-19: el mismo correo que ya tiene no cambia nada', function () {
        $teacher = teacherForEmails($this->ii, 'PRUE000001AB1', 'ana@cdjuarez.tecnm.mx');

        expect($this->template->apply(['rfc' => 'PRUE000001AB1', 'email' => 'ana@cdjuarez.tecnm.mx'], $this->context)->wasChanged())->toBeFalse()
            ->and(Activity::query()->where('subject_id', $teacher->id)->where('event', 'updated')->exists())->toBeFalse();
    });

    it('AC-19, D14: si el docente ya tiene otro correo, no lo cambia aunque la fila llegue a aplicarse', function () {
        $teacher = teacherForEmails($this->ii, 'PRUE000001AB1', 'ana@cdjuarez.tecnm.mx');

        expect($this->template->apply(['rfc' => 'PRUE000001AB1', 'email' => 'ana.perez@cdjuarez.tecnm.mx'], $this->context)->wasChanged())->toBeFalse()
            ->and($teacher->fresh()->institutional_email)->toBe('ana@cdjuarez.tecnm.mx');
    });

    it('AC-09: la invitación sale por el camino de la ficha: si su departamento ya validó, en ese momento y a nombre de quien importa; si no, al validar', function () {
        $sc = teacherForEmails($this->sc, 'PRUE000001AB1');
        $ii = teacherForEmails($this->ii, 'PRUE000002AB1');

        $this->template->apply(['rfc' => 'PRUE000001AB1', 'email' => 'ana@cdjuarez.tecnm.mx'], $this->context);
        $this->template->apply(['rfc' => 'PRUE000002AB1', 'email' => 'beto@cdjuarez.tecnm.mx'], $this->context);

        Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
        Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana@cdjuarez.tecnm.mx');
        expect(Activity::query()->where('event', 'access-invited')->where('subject_id', $sc->user_id)->sole()->causer_id)->toBe($this->admin->id)
            ->and($ii->user->fresh()->access_invited_at)->toBeNull();
    });
});

describe('el archivo de TI', function () {
    it('AC-19: el archivo de ejemplo anonimizado se valida y se aplica; el resumen lo cuenta por tipo', function () {
        emailsFixtureTeachers();

        $batch = validatedEmailsBatch($this->admin);
        $rows = $batch->rows()->orderBy('row_number')->get()->keyBy('row_number');

        expect([$batch->total_rows, $batch->valid_rows, $batch->error_rows])->toBe([9, 3, 6])
            ->and($rows[3]->status)->toBe(RowStatus::Valid)
            ->and($rows[8]->errors)->toHaveKey('rfc')
            ->and($rows[9]->errors)->toHaveKey('email');

        $before = emailsSummary($batch);

        expect($before)->not->toHaveKey('Invitación enviada')
            ->and($before['RFC que no está en Nexus'])->toBe(['count' => 1, 'keys' => ['PRUE099999AB1']])
            ->and($before['Correo fuera del dominio'])->toBe(['count' => 1, 'keys' => ['PRUE090005AB1']])
            ->and($before['Repetidos'])->toBe(['count' => 3, 'keys' => ['PRUE090001AB1', 'PRUE090006AB1', 'PRUE090007AB1']])
            ->and($before['Ya tenía otro correo'])->toBe(['count' => 1, 'keys' => ['PRUE090004AB1']]);

        $batch = app(ApplyImportBatch::class)->handle($batch->fresh(), $this->admin);

        expect([$batch->created_rows, $batch->updated_rows, $batch->unchanged_rows])->toBe([0, 2, 1])
            ->and(Teacher::where('rfc', 'PRUE090002AB1')->sole()->institutional_email)->toBe('docente.dos@cdjuarez.tecnm.mx')
            ->and(Teacher::where('rfc', 'PRUE090004AB1')->sole()->institutional_email)->toBe('docente.cuatro@cdjuarez.tecnm.mx')
            ->and(emailsSummary($batch)['Invitación enviada'])->toBe(['count' => 1, 'keys' => ['PRUE090001AB1']]);
        Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
    });

    it('AC-09: la que sale después, al validar el otro departamento, no cuenta como de la importación', function () {
        emailsFixtureTeachers();
        $batch = app(ApplyImportBatch::class)->handle(validatedEmailsBatch($this->admin)->fresh(), $this->admin);
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ii->id])->create();

        app(SendAccessInvitation::class)->handle(Teacher::where('rfc', 'PRUE090002AB1')->sole()->user, 'docente.dos@cdjuarez.tecnm.mx', $jefe);

        expect(emailsSummary($batch)['Invitación enviada']['count'])->toBe(1);
    });

    it('AC-19: el asistente muestra el resumen de los correos al validar y al aplicar', function () {
        emailsFixtureTeachers();
        $batch = validatedEmailsBatch($this->admin);

        $page = Livewire::actingAs($this->admin)->withQueryParams(['lote' => $batch->id])->test('imports::pages.import-wizard');
        $summary = textOf(domOf($page->html())->querySelector('[data-testid="batch-summary"]'));

        expect($summary)->toContain('1 RFC que no está en Nexus PRUE099999AB1', '1 Correo fuera del dominio', '3 Repetidos', '1 Ya tenía otro correo PRUE090004AB1')
            ->not->toContain('Invitaci');

        $page->call('apply');
        $summary = textOf(domOf($page->html())->querySelector('[data-testid="batch-summary"]'));

        expect($summary)->toContain('1 Invitación enviada PRUE090001AB1');
    });

    it('AC-19: el resumen nombra en singular el grupo de una sola fila y en plural los demás', function () {
        emailsFixtureTeachers();
        $batch = validatedEmailsBatch($this->admin);

        expect(array_keys(emailsSummary($batch)))->toBe(['RFC que no está en Nexus', 'Correo fuera del dominio', 'Repetidos', 'Ya tenía otro correo']);

        $batch->rows()->where('normalized->rfc', 'PRUE099999AB1')->sole()->update(['errors' => [], 'status' => RowStatus::Valid]);

        expect(array_keys(emailsSummary($batch)))->toBe(['RFC que no están en Nexus', 'Correo fuera del dominio', 'Repetidos', 'Ya tenía otro correo']);
    });

    it('AC-19: el jefe de departamento no importa correos: no entra al asistente ni sube el archivo', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();

        $this->actingAs($jefe)->get(route('imports.wizard'))->assertForbidden();
        Livewire::actingAs($jefe)->test('imports::pages.import-wizard')->assertForbidden();

        expect($jefe->can('create', ImportBatch::class))->toBeFalse();
    });
});
