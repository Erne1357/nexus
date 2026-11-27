<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Actions\DeactivatePositionMailbox;
use App\Modules\Core\Actions\DeliverHeldPositionMail;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Actions\RecordHeldPositionMail;
use App\Modules\Core\Actions\ReleaseHeldPositionMails;
use App\Modules\Core\Actions\SavePositionMailbox;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Jobs\SendHeldPositionMail;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AcademicLoadNotice;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\PositionNotice;
use App\Modules\Core\Notifications\PositionRef;
use App\Modules\Core\Queries\PositionMailboxQuery;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Core\Settings\SettingRegistry;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

/*
| CE-02 (confirmado el 26 de noviembre de 2026): el correo de un aviso que compete al puesto va al buzón institucional
| del puesto, no al de la persona. El puesto es el nombramiento por el que quien recibe el aviso cubre lo avisado (su
| rol y su alcance); el buzón que vale es el más específico que coincide. Si el puesto todavía no tiene buzón, el aviso
| va a la campana y el correo espera hasta que el admin lo capture; entonces sale, uno por trabajo en la cola `bulk`.
*/

class PositionProbeNotice extends NexusNotification implements PositionNotice
{
    public function __construct(public readonly bool $explode = false) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->positionChannels() ?? ['database'];
    }

    public function position(): ?PositionRef
    {
        return $this->holderPosition();
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: 'Aviso del puesto', body: 'Texto.', icon: 'o-bell', route: 'home');
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->explode) {
            throw new RuntimeException('Microsoft no respondió.');
        }

        return (new MailMessage)->subject('Aviso del puesto')->line('Texto.');
    }
}

final class LoadPositionProbeNotice extends PositionProbeNotice implements AcademicLoadNotice {}

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx', 'nexus.institutional_email_domains' => ['cdjuarez.tecnm.mx']]);
    $this->unit = OrgUnit::factory()->create(['name' => 'Servicios Escolares de prueba']);
    $this->jefa = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->unit->id])->create(['email' => 'ana.soto@cdjuarez.tecnm.mx']);
});

/**
 * A quiénes llegó correo en esta prueba.
 *
 * @return list<string>
 */
function positionMailRecipients(): array
{
    return app('mail.manager')->mailer()->getSymfonyTransport()->messages()
        ->flatMap(fn (SentMessage $sent): array => array_map(fn (Address $address): string => $address->getAddress(), $sent->getOriginalMessage()->getTo()))
        ->values()
        ->all();
}

/**
 * Un destinatario de NotifyHolders con el nombramiento por el que le toca (sobre una unidad).
 *
 * @return array{user: User, role: Role, scope: AssignmentScope}
 */
function unitHolder(User $user, Role $role, string $unitId): array
{
    return ['user' => $user, 'role' => $role, 'scope' => new AssignmentScope($role, null, $unitId, null, null)];
}

/**
 * Manda el aviso de prueba a la jefa de la unidad, por su puesto.
 */
function noticeTheHead(?PositionProbeNotice $notice = null): void
{
    app(NotifyHolders::class)->handle([unitHolder(test()->jefa, Role::JefeDepartamento, test()->unit->id)], $notice ?? new PositionProbeNotice);
}

/**
 * Captura el buzón de la jefatura de la unidad de la prueba.
 */
function captureHeadMailbox(string $email = 'jefatura_se@cdjuarez.tecnm.mx'): PositionMailbox
{
    return app(SavePositionMailbox::class)->handle(['role' => 'jefe_departamento', 'org_unit_id' => test()->unit->id, 'email' => $email]);
}

function showAcademicLoadForMail(bool $visible): void
{
    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING), $visible);
}

describe('el buzón que vale', function () {
    it('CE-02: gana el buzón más específico que coincide; inactivos y borrados no cuentan', function () {
        $modality = Modality::factory()->create();
        $campus = Campus::factory()->create();
        $other = Campus::factory()->create();
        $career = careerKey(1);
        $mailbox = fn (array $scope, string $email): PositionMailbox => PositionMailbox::factory()->create(['role' => Role::Coordinador, 'org_unit_id' => null, 'email' => $email, ...$scope]);

        $mailbox([], 'coordinaciones@cdjuarez.tecnm.mx');
        $mailbox(['career_id' => $career, 'modality_id' => $modality->id], 'coord_isc@cdjuarez.tecnm.mx');
        $mailbox(['career_id' => $career, 'modality_id' => $modality->id, 'campus_id' => $campus->id], 'coord_isc_c2@cdjuarez.tecnm.mx');
        $mailbox(['career_id' => $career, 'modality_id' => $modality->id, 'campus_id' => $other->id], 'vieja@cdjuarez.tecnm.mx')->update(['is_active' => false]);
        $mailbox(['career_id' => $career, 'modality_id' => $modality->id, 'campus_id' => $other->id], 'borrada@cdjuarez.tecnm.mx')->delete();

        $query = app(PositionMailboxQuery::class);
        $ref = fn (?string $campusId, ?string $careerId = null): PositionRef => PositionRef::of(Role::Coordinador, career: $careerId ?? $career, campus: $campusId, modality: $modality->id);

        expect($query->addressFor($ref($campus->id)))->toBe('coord_isc_c2@cdjuarez.tecnm.mx')
            ->and($query->addressFor($ref($other->id)))->toBe('coord_isc@cdjuarez.tecnm.mx')
            ->and($query->addressFor($ref(null)))->toBe('coord_isc@cdjuarez.tecnm.mx')
            ->and($query->addressFor($ref(null, careerKey(2))))->toBe('coordinaciones@cdjuarez.tecnm.mx')
            ->and($query->addressFor(PositionRef::of(Role::JefeDepartamento, unit: $this->unit->id)))->toBeNull();
    });

    it('CE-02: el puesto solo guarda el alcance que su rol admite', function () {
        $ref = PositionRef::of(Role::JefeDepartamento, unit: $this->unit->id, career: careerKey(1), campus: fakeKey(3), modality: fakeKey(4));

        expect($ref->columns())->toBe(['org_unit_id' => $this->unit->id, 'career_id' => null, 'campus_id' => null, 'modality_id' => null])
            ->and($ref->key())->toBe("jefe_departamento|{$this->unit->id}|*|*|*");
    });
});

describe('el correo del aviso de puesto', function () {
    it('CE-02: con buzón, el correo va al buzón del puesto y no al de la persona; la campana se guarda', function () {
        PositionMailbox::factory()->create(['org_unit_id' => $this->unit->id, 'email' => 'jefatura_se@cdjuarez.tecnm.mx']);

        noticeTheHead();

        expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx'])
            ->and($this->jefa->notifications()->count())->toBe(1)
            ->and(HeldPositionMail::query()->count())->toBe(0);
    });

    it('CE-02: sin buzón, la campana se guarda y el correo queda en espera, cifrado, con el puesto y el id de la notificación', function () {
        noticeTheHead();

        $held = HeldPositionMail::query()->sole();

        expect(positionMailRecipients())->toBe([])
            ->and($this->jefa->notifications()->count())->toBe(1)
            ->and($held->user_id)->toBe($this->jefa->id)
            ->and($held->role)->toBe(Role::JefeDepartamento)
            ->and($held->org_unit_id)->toBe($this->unit->id)
            ->and($held->kind)->toBe('PositionProbeNotice')
            ->and($held->subject)->toBe('Aviso del puesto')
            ->and($held->notification_id)->not->toBeNull()
            ->and($held->academic_load)->toBeFalse()
            ->and($held->notification)->not->toContain('PositionProbeNotice')
            ->and($held->released_at)->toBeNull()
            ->and(MailLog::query()->count())->toBe(0);
    });

    it('CE-02: si la cola vuelve a entregar el mismo correo, no se guarda dos veces', function () {
        $notice = (new PositionProbeNotice)->addressedTo(Role::JefeDepartamento, new AssignmentScope(Role::JefeDepartamento, null, $this->unit->id, null, null));
        $notice->id = (string) Str::uuid7();

        app(RecordHeldPositionMail::class)->handle($this->jefa, $notice, $notice->position());
        app(RecordHeldPositionMail::class)->handle($this->jefa, $notice, $notice->position());

        expect(HeldPositionMail::query()->count())->toBe(1);
    });

    it('CE-02: varias personas con el mismo puesto y alcance reciben su campana y el buzón un solo correo (supuesto por confirmar)', function () {
        $otra = User::factory()->create();
        $personal = User::factory()->create();
        PositionMailbox::factory()->create(['org_unit_id' => $this->unit->id, 'email' => 'jefatura_se@cdjuarez.tecnm.mx']);

        app(NotifyHolders::class)->handle([
            unitHolder($this->jefa, Role::JefeDepartamento, $this->unit->id),
            unitHolder($otra, Role::JefeDepartamento, $this->unit->id),
            unitHolder($personal, Role::Personal, $this->unit->id),
        ], new PositionProbeNotice);

        expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx'])
            ->and($this->jefa->notifications()->count())->toBe(1)
            ->and($otra->notifications()->count())->toBe(1)
            ->and($personal->notifications()->count())->toBe(1)
            ->and(HeldPositionMail::query()->sole()->role)->toBe(Role::Personal);
    });

    it('CE-02: un aviso que no se mandó a un puesto sigue a la persona', function () {
        $this->jefa->notify(new PositionProbeNotice);
        app(NotifyHolders::class)->handle([['user' => $this->jefa, 'role' => Role::JefeDepartamento]], new PositionProbeNotice);

        expect((new PositionProbeNotice)->position())->toBeNull()
            ->and(HeldPositionMail::query()->count())->toBe(0)
            ->and($this->jefa->notifications()->count())->toBe(2);
    });

    it('CE-02: un buzón cuyo dominio ya no es institucional se bloquea al salir (CE-01)', function () {
        config(['nexus.institutional_email_domains' => ['cdjuarez.tecnm.mx', 'viejo.tecnm.mx']]);
        PositionMailbox::factory()->create(['org_unit_id' => $this->unit->id, 'email' => 'jefatura@viejo.tecnm.mx']);
        config(['nexus.institutional_email_domains' => ['cdjuarez.tecnm.mx']]);

        noticeTheHead();

        $log = MailLog::query()->sole();

        expect(positionMailRecipients())->toBe([])
            ->and($log->status)->toBe(MailStatus::Failed)
            ->and($log->is_blocked)->toBeTrue()
            ->and($log->to)->toBe('jefatura@viejo.tecnm.mx');
    });
});

describe('el puesto es el nombramiento de quien lo recibe', function () {
    beforeEach(function () {
        $this->modality = Modality::query()->where('code', 'PRES')->first() ?? Modality::factory()->create(['code' => 'PRES']);
        $this->c1 = Campus::factory()->create(['code' => 'C1']);
        $this->c2 = Campus::factory()->create(['code' => 'C2']);
        $this->career = careerKey(1);
        $this->coordC1 = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->career, 'campus_id' => $this->c1->id, 'modality_id' => $this->modality->id])->create();
        $this->coordC2 = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->career, 'campus_id' => $this->c2->id, 'modality_id' => $this->modality->id])->create();
        $this->noticeCoordinators = fn () => app(NotifyHolders::class)->handle(
            app(RecipientsQuery::class)->holders(AcademicLoadPermission::ProposalSubmit, Target::of(modality: $this->modality->id, career: $this->career)),
            new PositionProbeNotice,
        );
    });

    it('CE-02: dos coordinaciones de la misma carrera en C1 y C2, cada una con su buzón de campus, reciben cada una el suyo', function () {
        $mailbox = fn (Campus $campus, string $email): PositionMailbox => PositionMailbox::factory()->create(['role' => Role::Coordinador, 'org_unit_id' => null, 'career_id' => $this->career, 'modality_id' => $this->modality->id, 'campus_id' => $campus->id, 'email' => $email]);
        $mailbox($this->c1, 'coord_c1@cdjuarez.tecnm.mx');
        $mailbox($this->c2, 'coord_c2@cdjuarez.tecnm.mx');

        ($this->noticeCoordinators)();

        expect(collect(positionMailRecipients())->sort()->values()->all())->toBe(['coord_c1@cdjuarez.tecnm.mx', 'coord_c2@cdjuarez.tecnm.mx']);
    });

    it('CE-02: con solo un buzón de todos los campus, cada puesto le manda su correo: puede llegar dos veces (supuesto por confirmar)', function () {
        PositionMailbox::factory()->create(['role' => Role::Coordinador, 'org_unit_id' => null, 'career_id' => $this->career, 'modality_id' => $this->modality->id, 'email' => 'coord_isc@cdjuarez.tecnm.mx']);

        ($this->noticeCoordinators)();

        expect(positionMailRecipients())->toBe(['coord_isc@cdjuarez.tecnm.mx', 'coord_isc@cdjuarez.tecnm.mx'])
            ->and(HeldPositionMail::query()->count())->toBe(0);
    });
});

describe('liberar lo que espera', function () {
    it('CE-02: al capturar el buzón sale el correo que esperaba y queda liberado; el de otro puesto sigue esperando', function () {
        $otherUnit = OrgUnit::factory()->create();
        noticeTheHead();
        app(NotifyHolders::class)->handle([unitHolder($this->jefa, Role::JefeDepartamento, $otherUnit->id)], new PositionProbeNotice);

        captureHeadMailbox('Jefatura_SE@cdjuarez.tecnm.mx');

        expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx'])
            ->and(HeldPositionMail::query()->where('org_unit_id', $this->unit->id)->sole()->released_at)->not->toBeNull()
            ->and(HeldPositionMail::query()->where('org_unit_id', $otherUnit->id)->sole()->released_at)->toBeNull()
            ->and(MailLog::query()->sole()->status)->toBe(MailStatus::Sent);
    });

    it('CE-02: liberar dos veces no manda dos correos', function () {
        noticeTheHead();
        $mailbox = captureHeadMailbox();

        app(SavePositionMailbox::class)->handle(['role' => 'jefe_departamento', 'org_unit_id' => $this->unit->id, 'email' => 'jefatura.se@cdjuarez.tecnm.mx'], $mailbox);
        app(ReleaseHeldPositionMails::class)->handle();

        expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx']);
    });

    it('CE-02: la liberación solo toma y encola un trabajo por correo en la cola bulk', function () {
        noticeTheHead();
        Queue::fake([SendHeldPositionMail::class]);

        captureHeadMailbox();

        $held = HeldPositionMail::query()->sole();

        expect($held->released_at)->not->toBeNull()
            ->and(positionMailRecipients())->toBe([]);
        Queue::assertPushedOn('bulk', SendHeldPositionMail::class, fn (SendHeldPositionMail $job): bool => $job->heldId === $held->id);
    });

    it('CE-02: una falla que se puede reintentar lo regresa a la espera con un intento más; al tope queda fallido', function () {
        noticeTheHead(new PositionProbeNotice(explode: true));
        Queue::fake([SendHeldPositionMail::class]);
        captureHeadMailbox();
        $id = HeldPositionMail::query()->sole()->id;

        foreach (range(1, DeliverHeldPositionMail::MAX_ATTEMPTS) as $attempt) {
            $job = new SendHeldPositionMail($id);

            expect(fn () => $job->handle(app(DeliverHeldPositionMail::class)))->toThrow(RuntimeException::class, 'Microsoft no respondió.');
            $job->failed(new RuntimeException('Microsoft no respondió.'));

            $held = HeldPositionMail::query()->sole();

            expect($held->attempts)->toBe($attempt)->and($held->released_at)->toBeNull();

            $attempt < DeliverHeldPositionMail::MAX_ATTEMPTS
                ? expect($held->failed_at)->toBeNull()->and(app(ReleaseHeldPositionMails::class)->handle())->toBe(1)
                : expect($held->failed_at)->not->toBeNull()->and(app(ReleaseHeldPositionMails::class)->handle())->toBe(0);
        }

        expect(positionMailRecipients())->toBe([]);
    });

    it('CE-02: un correo que ya no se puede volver a armar queda fallido de una vez', function () {
        noticeTheHead();
        Queue::fake([SendHeldPositionMail::class]);
        captureHeadMailbox();
        $held = HeldPositionMail::query()->sole();
        $held->update(['notification' => 'basura']);
        Exceptions::fake();

        (new SendHeldPositionMail($held->id))->handle(app(DeliverHeldPositionMail::class));

        expect($held->fresh())->failed_at->not->toBeNull()->released_at->toBeNull()->attempts->toBe(0)
            ->and(positionMailRecipients())->toBe([]);
    });

    it('CE-02: si el buzón se desactiva entre que se toma y se manda, vuelve a esperar en el mismo renglón', function () {
        noticeTheHead();
        Queue::fake([SendHeldPositionMail::class]);
        $mailbox = captureHeadMailbox();
        $held = HeldPositionMail::query()->sole();

        app(DeactivatePositionMailbox::class)->handle($mailbox);
        (new SendHeldPositionMail($held->id))->handle(app(DeliverHeldPositionMail::class));

        expect(HeldPositionMail::query()->sole())->id->toBe($held->id)->released_at->toBeNull()->failed_at->toBeNull()
            ->and(positionMailRecipients())->toBe([]);
    });

});

it('MN-05: un correo de puesto de la carga que esperaba no se toma mientras la carga está oculta; sale al mostrarla', function () {
    noticeTheHead(new LoadPositionProbeNotice);
    showAcademicLoadForMail(false);

    captureHeadMailbox();

    expect(HeldPositionMail::query()->sole())->academic_load->toBeTrue()->released_at->toBeNull()
        ->and(positionMailRecipients())->toBe([]);

    showAcademicLoadForMail(true);
    app(ReleaseHeldPositionMails::class)->handle();

    expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx'])
        ->and(HeldPositionMail::query()->sole()->released_at)->not->toBeNull();
});

it('MN-05: si la carga se oculta entre que se toma y se manda, el correo vuelve a esperar', function () {
    noticeTheHead(new LoadPositionProbeNotice);
    Queue::fake([SendHeldPositionMail::class]);
    captureHeadMailbox();
    $held = HeldPositionMail::query()->sole();

    showAcademicLoadForMail(false);
    (new SendHeldPositionMail($held->id))->handle(app(DeliverHeldPositionMail::class));

    expect($held->fresh()->released_at)->toBeNull()
        ->and(positionMailRecipients())->toBe([]);
});
