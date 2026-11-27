<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\PositionMailboxSaved;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PositionRef;
use App\Modules\Core\Rules\PositionMailboxRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Captura o cambia el buzón institucional de un puesto (CE-02): el puesto (rol y alcance, PositionMailboxRules) y su
 * correo institucional. Guardarlo lo deja activo, así que editar uno desactivado lo reactiva. Quién puede lo decide
 * PositionMailboxPolicy antes de llamarla (solo el admin).
 *
 * En una transacción revisa que el puesto no tenga otro buzón activo (y atrapa la carrera con el índice único), vuelve a
 * leer el buzón que edita con bloqueo para que el valor anterior de la bitácora sea el confirmado, escribe y deja la
 * bitácora; si nada cambió no escribe, no registra ni avisa. Después del commit, PositionMailboxSaved libera los correos
 * que esperaban un buzón (ReleaseHeldPositionMailsJob).
 */
final class SavePositionMailbox
{
    private const array COLUMNS = ['role', 'org_unit_id', 'career_id', 'campus_id', 'modality_id', 'email', 'is_active'];

    public function __construct(private readonly PositionMailboxRules $rules) {}

    /**
     * @param  array{role?: string, org_unit_id?: ?string, career_id?: ?string, campus_id?: ?string, modality_id?: ?string, email?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data, ?PositionMailbox $mailbox = null, ?User $actor = null): PositionMailbox
    {
        $position = $this->rules->position($data);
        $email = $this->rules->email($data['email'] ?? null);

        return DB::transaction(function () use ($position, $email, $mailbox, $actor): PositionMailbox {
            $creating = $mailbox === null;
            $mailbox ??= new PositionMailbox;
            $old = [];

            if (! $creating) {
                $mailbox->setRawAttributes(PositionMailbox::query()->lockForUpdate()->findOrFail($mailbox->getKey())->getAttributes(), sync: true);
                $old = $this->attributesOf($mailbox);
            }

            $this->rules->ensureFree($position, $creating ? null : $mailbox);

            $mailbox->fill([...$position, 'email' => $email, 'is_active' => true]);
            $changed = array_keys(Arr::only($mailbox->getDirty(), self::COLUMNS));

            if (! $creating && $changed === []) {
                return $mailbox;
            }

            try {
                $mailbox->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->taken($email);
            }

            $label = $position['role']->label();
            $log = activity()->performedOn($mailbox)->causedBy($actor);

            $creating
                ? $log->event(ActivityEvent::Created)->withChanges(['attributes' => $this->attributesOf($mailbox)])->log("Capturó el buzón {$email} del puesto {$label}")
                : $log->event(ActivityEvent::Updated)->withChanges(['attributes' => Arr::only($this->attributesOf($mailbox), $changed), 'old' => Arr::only($old, $changed)])->log("Cambió el buzón {$email} del puesto {$label}");

            PositionMailboxSaved::dispatch($mailbox);

            return $mailbox;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(PositionMailbox $mailbox): array
    {
        return ['role' => $mailbox->role?->value, ...Arr::only($mailbox->getAttributes(), [...PositionRef::COLUMNS, 'email']), 'is_active' => (bool) $mailbox->is_active];
    }
}
