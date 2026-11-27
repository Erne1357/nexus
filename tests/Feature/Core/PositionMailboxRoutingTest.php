<?php

use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Actions\SavePositionMailbox;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\PositionNotice;
use App\Modules\Core\Notifications\PositionRef;
use App\Modules\Core\Queries\PositionMailboxQuery;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

/*
| CE-02 (confirmado el 26 de noviembre de 2026): el correo de un aviso que compete al puesto va al buzón institucional
| del puesto, no al de la persona. El puesto es el rol más su alcance; el buzón que vale es el más específico que
| coincide. Si el puesto todavía no tiene buzón, el aviso va a la campana y el correo queda en espera hasta que el admin
| lo capture; entonces sale. Un aviso de puesto manda un solo correo por buzón aunque lo reciban varias personas.
*/

final class PositionProbeNotice extends NexusNotification implements PositionNotice
{
    public function __construct(public readonly ?string $unitId, public readonly bool $explode = false) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->positionChannels() ?? ['database'];
    }

    public function position(): ?PositionRef
    {
        return $this->addressedRole() === null || $this->unitId === null ? null : PositionRef::of($this->addressedRole(), unit: $this->unitId);
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: 'Aviso del puesto', body: 'Texto.', icon: 'o-bell', route: 'home');
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->explode) {
            throw new RuntimeException('El transporte no respondió.');
        }

        return (new MailMessage)->subject('Aviso del puesto')->line('Texto.');
    }
}

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

        app(NotifyHolders::class)->handle([['user' => $this->jefa, 'role' => Role::JefeDepartamento]], new PositionProbeNotice($this->unit->id));

        expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx'])
            ->and($this->jefa->notifications()->count())->toBe(1)
            ->and(HeldPositionMail::query()->count())->toBe(0);
    });

    it('CE-02: sin buzón, la campana se guarda y el correo queda en espera con el puesto', function () {
        app(NotifyHolders::class)->handle([['user' => $this->jefa, 'role' => Role::JefeDepartamento]], new PositionProbeNotice($this->unit->id));

        $held = HeldPositionMail::query()->sole();

        expect(positionMailRecipients())->toBe([])
            ->and($this->jefa->notifications()->count())->toBe(1)
            ->and($held->user_id)->toBe($this->jefa->id)
            ->and($held->role)->toBe(Role::JefeDepartamento)
            ->and($held->org_unit_id)->toBe($this->unit->id)
            ->and($held->kind)->toBe('PositionProbeNotice')
            ->and($held->subject)->toBe('Aviso del puesto')
            ->and($held->released_at)->toBeNull()
            ->and(MailLog::query()->count())->toBe(0);
    });

    it('CE-02: varias personas del mismo puesto reciben su campana y el buzón un solo correo (supuesto por confirmar)', function () {
        $otra = User::factory()->create();
        $jpd = User::factory()->create();
        PositionMailbox::factory()->create(['org_unit_id' => $this->unit->id, 'email' => 'jefatura_se@cdjuarez.tecnm.mx']);

        app(NotifyHolders::class)->handle([
            ['user' => $this->jefa, 'role' => Role::JefeDepartamento],
            ['user' => $otra, 'role' => Role::JefeDepartamento],
            ['user' => $jpd, 'role' => Role::Personal],
        ], new PositionProbeNotice($this->unit->id));

        expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx'])
            ->and($this->jefa->notifications()->count())->toBe(1)
            ->and($otra->notifications()->count())->toBe(1)
            ->and($jpd->notifications()->count())->toBe(1)
            ->and(HeldPositionMail::query()->sole()->role)->toBe(Role::Personal);
    });

    it('CE-02: un aviso que no se mandó a un puesto sigue a la persona', function () {
        $this->jefa->notify(new PositionProbeNotice($this->unit->id));

        expect((new PositionProbeNotice($this->unit->id))->position())->toBeNull()
            ->and(HeldPositionMail::query()->count())->toBe(0)
            ->and($this->jefa->notifications()->count())->toBe(1);
    });
});

describe('liberar lo que espera', function () {
    it('CE-02: al capturar el buzón sale el correo que esperaba y queda liberado; el de otro puesto sigue esperando', function () {
        $otherUnit = OrgUnit::factory()->create();
        $notify = app(NotifyHolders::class);
        $notify->handle([['user' => $this->jefa, 'role' => Role::JefeDepartamento]], new PositionProbeNotice($this->unit->id));
        $notify->handle([['user' => $this->jefa, 'role' => Role::JefeDepartamento]], new PositionProbeNotice($otherUnit->id));

        app(SavePositionMailbox::class)->handle(['role' => 'jefe_departamento', 'org_unit_id' => $this->unit->id, 'email' => 'Jefatura_SE@cdjuarez.tecnm.mx']);

        expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx'])
            ->and(HeldPositionMail::query()->where('org_unit_id', $this->unit->id)->sole()->released_at)->not->toBeNull()
            ->and(HeldPositionMail::query()->where('org_unit_id', $otherUnit->id)->sole()->released_at)->toBeNull()
            ->and(MailLog::query()->sole()->status)->toBe(MailStatus::Sent);
    });

    it('CE-02: liberar dos veces no manda dos correos', function () {
        app(NotifyHolders::class)->handle([['user' => $this->jefa, 'role' => Role::JefeDepartamento]], new PositionProbeNotice($this->unit->id));
        $mailbox = app(SavePositionMailbox::class)->handle(['role' => 'jefe_departamento', 'org_unit_id' => $this->unit->id, 'email' => 'jefatura_se@cdjuarez.tecnm.mx']);

        app(SavePositionMailbox::class)->handle(['role' => 'jefe_departamento', 'org_unit_id' => $this->unit->id, 'email' => 'jefatura.se@cdjuarez.tecnm.mx'], $mailbox);

        expect(positionMailRecipients())->toBe(['jefatura_se@cdjuarez.tecnm.mx']);
    });

    it('CE-02: si el correo liberado falla, queda fallido y no se reintenta solo', function () {
        app(NotifyHolders::class)->handle([['user' => $this->jefa, 'role' => Role::JefeDepartamento]], new PositionProbeNotice($this->unit->id, explode: true));

        app(SavePositionMailbox::class)->handle(['role' => 'jefe_departamento', 'org_unit_id' => $this->unit->id, 'email' => 'jefatura_se@cdjuarez.tecnm.mx']);

        $held = HeldPositionMail::query()->sole();

        expect($held->failed_at)->not->toBeNull()
            ->and($held->released_at)->toBeNull()
            ->and(positionMailRecipients())->toBe([]);
    });

    it('CE-02: con el buzón desactivado, el aviso nuevo vuelve a esperar', function () {
        $mailbox = PositionMailbox::factory()->inactive()->create(['org_unit_id' => $this->unit->id, 'email' => 'jefatura_se@cdjuarez.tecnm.mx']);

        app(NotifyHolders::class)->handle([['user' => $this->jefa, 'role' => Role::JefeDepartamento]], new PositionProbeNotice($this->unit->id));

        expect(positionMailRecipients())->toBe([])
            ->and(HeldPositionMail::query()->count())->toBe(1)
            ->and($mailbox->fresh()->is_active)->toBeFalse();
    });
});
