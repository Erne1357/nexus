<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Database\Eloquent\Builder;

/**
 * HC-02 y HC-11 para un préstamo de salón (reglas-de-negocio.md, "Acuerdos entre departamentos"): el bloque pedido
 * (salón, periodo, día y horas de un grupo) no puede empalmar con la sesión activa de otro grupo en ese salón y periodo
 * (HC-02) ni con un bloqueo del salón de ese periodo o de todos (HC-11). Es la única revisión de un préstamo: la usan AcceptRoomLoan
 * (leyendo con bloqueo de filas), la semana del detalle y la lista de Acuerdos (AgreementsInboxQuery), para que los
 * tres digan lo mismo. Una sesión cuenta antes que un bloqueo.
 *
 * @phpstan-type Slot array{room_id: string, term_id: ?string, group_id: ?string, day: int, starts_at: string, ends_at: string, label: string, rule: string}
 * @phpstan-type Loan array{room_id: ?string, term_id: string, group_id: ?string, day: ?int, starts_at: ?string, ends_at: ?string}
 */
final class RoomLoanFree
{
    /**
     * Lo que ocupa esos salones en esos periodos: las sesiones activas (HC-02) y luego los bloqueos (HC-11), con su
     * clave o su motivo. Con `$lock`, las sesiones se leen `for update` (solo sus filas) y los bloqueos `for share`.
     *
     * @param  list<string>  $rooms
     * @param  list<string>  $terms
     * @return list<Slot>
     */
    public function busy(array $rooms, array $terms, ?int $day = null, bool $lock = false): array
    {
        if ($rooms === [] || $terms === []) {
            return [];
        }

        $sessions = GroupSession::query()
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->whereIn('group_sessions.room_id', $rooms)
            ->whereIn('group_sessions.term_id', $terms)
            ->where('group_sessions.is_active', true)
            ->when($day !== null, fn (Builder $query) => $query->where('group_sessions.day', $day))
            ->when($lock, fn (Builder $query) => $query->lock('for update of group_sessions'))
            ->orderBy('group_sessions.day')
            ->orderBy('group_sessions.starts_at')
            ->toBase()
            ->get(['group_sessions.group_id', 'group_sessions.room_id', 'group_sessions.term_id', 'group_sessions.day', 'group_sessions.starts_at', 'group_sessions.ends_at', 'groups.code as group_code', 'subjects.code as subject_code'])
            ->map(fn (object $session): array => [
                'room_id' => $session->room_id, 'term_id' => $session->term_id, 'group_id' => $session->group_id, 'day' => (int) $session->day,
                'starts_at' => Minutes::clock($session->starts_at), 'ends_at' => Minutes::clock($session->ends_at),
                'label' => "{$session->subject_code} {$session->group_code}", 'rule' => 'HC-02',
            ]);

        $blocks = RoomBlock::query()
            ->whereIn('room_id', $rooms)
            ->where(fn (Builder $query) => $query->whereNull('term_id')->orWhereIn('term_id', $terms))
            ->when($day !== null, fn (Builder $query) => $query->where('day', $day))
            ->when($lock, fn (Builder $query) => $query->sharedLock())
            ->orderBy('day')
            ->orderBy('starts_at')
            ->get(['room_id', 'term_id', 'day', 'starts_at', 'ends_at', 'reason'])
            ->map(fn (RoomBlock $block): array => [
                'room_id' => $block->room_id, 'term_id' => $block->term_id, 'group_id' => null, 'day' => (int) $block->day,
                'starts_at' => Minutes::clock($block->starts_at), 'ends_at' => Minutes::clock($block->ends_at),
                'label' => (string) $block->reason === '' ? 'Bloqueado' : (string) $block->reason, 'rule' => 'HC-11',
            ]);

        return [...$sessions->all(), ...$blocks->all()];
    }

    /**
     * Lo primero de `$busy` que choca con el bloque pedido: mismo salón, un periodo que aplica (el del préstamo, o
     * todos en un bloqueo), otro grupo, el mismo día y horas que se empalman (dos horas contiguas no). Null si está libre.
     *
     * @param  Loan  $loan
     * @param  iterable<Slot>  $busy
     * @return Slot|null
     */
    public function clash(array $loan, iterable $busy): ?array
    {
        if ($loan['room_id'] === null || $loan['day'] === null || $loan['starts_at'] === null || $loan['ends_at'] === null) {
            return null;
        }

        $starts = Minutes::of($loan['starts_at']);
        $ends = Minutes::of($loan['ends_at']);

        foreach ($busy as $slot) {
            if ($slot['room_id'] === $loan['room_id']
                && ($slot['term_id'] === null || $slot['term_id'] === $loan['term_id'])
                && ($slot['group_id'] === null || $slot['group_id'] !== $loan['group_id'])
                && $slot['day'] === (int) $loan['day']
                && Minutes::overlap(Minutes::of($slot['starts_at']), Minutes::of($slot['ends_at']), $starts, $ends)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * La revisión de un préstamo contra la base, para escribir: lee el día del salón (con bloqueo si se pide) y aplica
     * `clash()`.
     *
     * @return Slot|null
     */
    public function clashFor(InterdepartmentalAgreement $agreement, bool $lock = false): ?array
    {
        if ($agreement->room_id === null || $agreement->day === null) {
            return null;
        }

        return $this->clash($this->loanOf($agreement), $this->busy([$agreement->room_id], [$agreement->term_id], (int) $agreement->day, $lock));
    }

    /**
     * El bloque pedido de un acuerdo, en la forma que espera `clash()`.
     *
     * @return Loan
     */
    public function loanOf(InterdepartmentalAgreement $agreement): array
    {
        return [
            'room_id' => $agreement->room_id,
            'term_id' => $agreement->term_id,
            'group_id' => $agreement->group_id,
            'day' => $agreement->day === null ? null : (int) $agreement->day,
            'starts_at' => $agreement->starts_at === null ? null : Minutes::clock($agreement->starts_at),
            'ends_at' => $agreement->ends_at === null ? null : Minutes::clock($agreement->ends_at),
        ];
    }
}
