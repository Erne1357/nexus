<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Enums\Role;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Base de las notificaciones de Nexus (tarea 2.9): van por el canal `database` y guardan un
 * NotificationMessage, que la campana de la banda lee sin conocer la clase. Cada módulo define las suyas en
 * `<Modulo>/Notifications` (el correo queda para después).
 *
 * Al abrirla, la campana pregunta a la clase si su destino sigue disponible para el usuario, con el perfil con que
 * lo abriría (unavailableReason) y, si no, se lo dice en lugar de llevarlo: cada clase agrega sus condiciones (que el
 * registro exista, que siga en su alcance) a la de omisión, que la ruta exista.
 *
 * Un aviso puede ir a un puesto (`addressedTo()`): se guarda en `data.role` y, al abrirlo, la sesión actúa con ese puesto
 * (OpenNotification). NotifyHolders manda una copia por destinatario, cada una con su puesto.
 *
 * Laravel clona la notificación para cada destinatario y canal antes de darle llave; __clone le da a cada
 * copia un UUIDv7 propio (ADR-0014), en lugar del UUIDv4 que pondría Laravel.
 *
 * Un aviso de puesto (PositionNotice, CE-02) va al buzón del puesto de quien lo recibe: NotifyHolders le pasa, con el
 * puesto, el alcance del nombramiento por el que esa persona cubre lo que se avisa (`addressedTo($role, $scope)`), y de
 * ahí sale el puesto (holderPosition()). Manda un solo correo por puesto aunque lo reciban varias personas con el mismo
 * puesto y alcance: NotifyHolders marca las demás copias con sharesPositionMail() y esas solo van a la campana
 * (positionChannels()). Al liberar un correo que esperaba un buzón, la copia lleva el id de su renglón en espera
 * (carriesHeldPositionMail()), para que si vuelve a esperar no se duplique. Las propiedades nuevas tienen valor por
 * omisión: un aviso en cola de antes se lee igual (sin puesto, sigue a la persona).
 */
abstract class NexusNotification extends Notification
{
    /** Protegida, no privada: SerializesModels omite las propiedades privadas de una clase padre si el aviso se encola. */
    protected ?Role $addressedRole = null;

    /** CE-02: otra copia de este aviso ya lleva el correo al buzón del mismo puesto. */
    protected bool $positionMailShared = false;

    /**
     * CE-02: el alcance del nombramiento por el que quien lo recibe cubre lo avisado (`org_unit_id`, `career_id`,
     * `campus_id`, `modality_id`), o null si no se mandó a un puesto.
     *
     * @var array<string, ?string>|null
     */
    protected ?array $addressedScope = null;

    /** CE-02: el renglón de `held_position_mails` que esta copia libera, si es una liberación. */
    protected ?string $heldPositionMailId = null;

    abstract public function message(object $notifiable): NotificationMessage;

    /**
     * El nombre del tipo de aviso en español, para el filtro de «Todas las notificaciones».
     */
    public static function typeLabel(): string
    {
        return Str::headline(class_basename(static::class));
    }

    /**
     * La llave del tipo en la URL del filtro (`?tipo=`). Por omisión sale del nombre completo de la clase, así que
     * dos clases con el mismo nombre corto en módulos distintos no chocan; una clase puede declarar una más corta.
     */
    public static function typeKey(): string
    {
        return Str::kebab(str_replace('\\', '', static::class));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->message($notifiable)->withRole($this->addressedRole)->toArray();
    }

    /**
     * El puesto al que va el aviso (`data.role`) y, si se sabe, el alcance del nombramiento por el que le toca (CE-02).
     */
    public function addressedTo(Role $role, ?AssignmentScope $scope = null): static
    {
        $this->addressedRole = $role;
        $this->addressedScope = $scope === null ? null : [
            'org_unit_id' => $scope->unitId,
            'career_id' => $scope->careerId,
            'campus_id' => $scope->campusId,
            'modality_id' => $scope->modalityId,
        ];

        return $this;
    }

    public function addressedRole(): ?Role
    {
        return $this->addressedRole;
    }

    /**
     * CE-02: esta copia no lleva correo, porque otra copia del mismo aviso ya lo lleva al buzón del mismo puesto.
     */
    public function sharesPositionMail(): static
    {
        $this->positionMailShared = true;

        return $this;
    }

    public function positionMailShared(): bool
    {
        return $this->positionMailShared;
    }

    /**
     * CE-02: esta copia libera el correo en espera `$id`; si vuelve a esperar, se reusa ese renglón.
     */
    public function carriesHeldPositionMail(string $id): static
    {
        $this->heldPositionMailId = $id;

        return $this;
    }

    public function heldPositionMailId(): ?string
    {
        return $this->heldPositionMailId;
    }

    /**
     * CE-02: el puesto de quien recibe esta copia, con el alcance de su nombramiento; null si no se mandó a un puesto
     * (o es un aviso en cola de antes, que no lo trae). Lo usan como position() los avisos de puesto.
     */
    protected function holderPosition(): ?PositionRef
    {
        return $this->addressedRole === null || $this->addressedScope === null ? null : PositionRef::fromColumns($this->addressedRole, $this->addressedScope);
    }

    /**
     * CE-02: los canales de un aviso de puesto (PositionNotice con puesto): la campana y el correo al buzón, aunque el
     * puesto todavía no tenga buzón (el correo espera, HoldPositionMail), o solo la campana si otra copia lleva el
     * correo. Null si esta instancia no es de un puesto: la notificación decide como siempre.
     *
     * @return list<string>|null
     */
    protected function positionChannels(): ?array
    {
        if (! $this instanceof PositionNotice || $this->position() === null) {
            return null;
        }

        return $this->positionMailShared ? ['database'] : ['database', 'mail'];
    }

    /**
     * Por qué el destino ya no se puede abrir para la persona de `$context`, con el perfil con que lo abriría
     * (OpenNotification pasa el del puesto de `data.role`, Actor::asRole()), o null si se puede. Recibe el
     * mensaje guardado, no la notificación, porque la campana solo tiene la fila de la base.
     */
    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        if ($message->route === null || ! Route::has($message->route)) {
            return 'Esa pantalla no está disponible.';
        }

        return null;
    }

    public function __clone(): void
    {
        $this->id = (string) Str::uuid7();
    }
}
