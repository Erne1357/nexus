<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Support\Collection;

/**
 * Bloques consecutivos se guardan como una sola sesión (docs/dominio/modelo-de-datos.md, `group_sessions`): al colocar
 * o mover, las sesiones del mismo grupo y día pegadas a la nueva (una termina donde la otra empieza) con el mismo tipo
 * y el mismo salón, o las dos sin salón, se unen con ella (supuesto por confirmar).
 */
final class ContiguousSessions
{
    /**
     * La vecina que termina donde empieza la nueva y la que empieza donde termina, si se unen con ella.
     *
     * @param  Collection<int, GroupSession>  $sameDay  las del grupo en ese día, sin la que se mueve
     * @return array{?GroupSession, ?GroupSession}
     */
    public static function around(Collection $sameDay, string $startsAt, string $endsAt, SessionKind $kind, ?string $roomId): array
    {
        $joins = fn (GroupSession $session): bool => $session->kind === $kind && $session->room_id === $roomId;
        $start = Minutes::of($startsAt);
        $end = Minutes::of($endsAt);

        return [
            $sameDay->first(fn (GroupSession $session): bool => $joins($session) && Minutes::of($session->ends_at) === $start),
            $sameDay->first(fn (GroupSession $session): bool => $joins($session) && Minutes::of($session->starts_at) === $end),
        ];
    }
}
