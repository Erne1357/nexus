<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Notifications\SelectionExtraRoundScheduled;
use App\Modules\Staffing\Notifications\SelectionFinishedWithPending;
use App\Modules\Staffing\Notifications\SelectionTurnStarting;
use App\Modules\Staffing\Rules\SelectionSlots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El reloj de la selección (SEL-08, SEL-09; diseño 6.3): lo corre `staffing:advance-selections` cada minuto. El turno
 * vigente nunca sale de aquí (se calcula de las horas guardadas); esta Action solo hace los efectos: pasa la selección a
 * en curso, avisa 10 minutos antes y al empezar cada turno, deja en `pending_extra` al que no cerró, arma la ronda extra
 * con los pendientes en el mismo orden (los que compartían turno lo siguen compartiendo), y al terminarla deja sin
 * resolver a los que faltan, termina la selección y avisa al jefe y al JPD.
 *
 * Es idempotente y tolera correr tarde: cada aviso tiene su marca y se manda una vez, y no se avisa de un turno que ya
 * pasó. Da cuántos cambios de estado hizo y cuántos avisos mandó.
 */
final class AdvanceSelections
{
    private const SOON_MINUTES = 10;

    public function __construct(
        private readonly RecipientsQuery $recipients,
        private readonly GrantTable $grants,
        private readonly NotifyHolders $notifyHolders,
    ) {}

    /**
     * @return array{advanced: int, notices: int}
     */
    public function handle(CarbonImmutable $now): array
    {
        $totals = ['advanced' => 0, 'notices' => 0];

        $ids = Selection::query()
            ->whereIn('status', [SelectionStatus::Published, SelectionStatus::Running, SelectionStatus::ExtraRound])
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $result = DB::transaction(fn (): array => $this->advance($id, $now));
            $totals['advanced'] += $result['advanced'];
            $totals['notices'] += $result['notices'];
        }

        return $totals;
    }

    /**
     * @return array{advanced: int, notices: int}
     */
    private function advance(string $id, CarbonImmutable $now): array
    {
        // Mismo candado que PickSelectionGroup y el cierre de carga: primero el de la selección, luego su fila y sus renglones.
        DB::select('select pg_advisory_xact_lock(hashtext(?))', [$id]);

        $selection = Selection::query()->whereKey($id)->lockForUpdate()->first();
        $result = ['advanced' => 0, 'notices' => 0];

        if ($selection === null || ! in_array($selection->status, [SelectionStatus::Published, SelectionStatus::Running, SelectionStatus::ExtraRound], true)) {
            return $result;
        }

        if ($selection->status === SelectionStatus::Published) {
            $first = $selection->entries()->min('slot_starts_at');

            if ($first === null || $now < CarbonImmutable::parse($first)) {
                return $this->add($result, 0, $this->noticesFor($selection, $now));
            }

            $selection->update(['status' => SelectionStatus::Running]);
            $result['advanced']++;
        }

        if ($selection->status === SelectionStatus::Running) {
            $result['advanced'] += $selection->entries()
                ->where('status', SelectionEntryStatus::Waiting)
                ->where('slot_ends_at', '<=', $now)
                ->update(['status' => SelectionEntryStatus::PendingExtra]);
        }

        $result['notices'] += $this->noticesFor($selection, $now);

        if ($selection->status === SelectionStatus::Running) {
            $end = $selection->first_round_ends_at ?? ($selection->entries()->max('slot_ends_at') === null ? null : CarbonImmutable::parse($selection->entries()->max('slot_ends_at')));

            if ($end !== null && $end <= $now) {
                $result = $this->add($result, ...$this->closeFirstRound($selection, $end, $now));
            }
        }

        if ($selection->status === SelectionStatus::ExtraRound && $selection->extra_round_ends_at <= $now) {
            $result = $this->add($result, ...$this->finish($selection, $now));
        }

        return $result;
    }

    /**
     * Avisos de «en 10 minutos» y «empieza tu turno» del turno vigente de cada renglón (el de la primera ronda o, en la
     * ronda extra, el extra). Un turno que ya terminó no se avisa.
     */
    private function noticesFor(Selection $selection, CarbonImmutable $now): int
    {
        $sent = 0;
        $extra = $selection->status === SelectionStatus::ExtraRound;
        $startColumn = $extra ? 'extra_slot_starts_at' : 'slot_starts_at';
        $endColumn = $extra ? 'extra_slot_ends_at' : 'slot_ends_at';
        $status = $extra ? SelectionEntryStatus::PendingExtra : SelectionEntryStatus::Waiting;

        $entries = $selection->entries()
            ->with('teacher.user')
            ->where('status', $status)
            ->where($endColumn, '>', $now)
            ->where($startColumn, '<=', $now->addMinutes(self::SOON_MINUTES))
            ->get();

        foreach ($entries as $entry) {
            $user = $entry->teacher?->user;
            $startsAt = $entry->{$startColumn};
            $started = $startsAt <= $now;
            $mark = $started ? 'notified_start_at' : 'notified_soon_at';

            if ($entry->{$mark} !== null) {
                continue;
            }

            $entry->update([$mark => $now]);

            if ($user === null) {
                continue;
            }

            $this->notifyTeacher($user, new SelectionTurnStarting($selection->term_id, $selection->modality_id, $startsAt, $started));
            $sent++;
        }

        return $sent;
    }

    /**
     * SEL-09: con la primera ronda terminada, los pendientes pasan a la ronda extra, o la selección termina si no hay.
     *
     * @return array{0: int, 1: int}
     */
    private function closeFirstRound(Selection $selection, CarbonImmutable $firstRoundEnd, CarbonImmutable $now): array
    {
        $pending = $selection->entries()
            ->with('teacher.user')
            ->where('status', SelectionEntryStatus::PendingExtra)
            ->orderBy('position')
            ->get();

        if ($pending->isEmpty()) {
            $selection->update(['status' => SelectionStatus::Finished, 'finished_at' => $now]);

            return [1, 0];
        }

        $groups = $pending->groupBy('slot_index')->values();
        $slots = SelectionSlots::layout(
            SelectionSlots::extraRoundStart($firstRoundEnd, $this->time($selection->window_starts), $this->time($selection->window_ends), $selection->weekdays, $selection->slot_minutes),
            $this->time($selection->window_starts),
            $this->time($selection->window_ends),
            $selection->weekdays,
            $selection->slot_minutes,
            $groups->count(),
        );
        $notices = 0;

        foreach ($groups as $i => $group) {
            foreach ($group as $entry) {
                $changed = $selection->entries()->whereKey($entry->getKey())->where('status', SelectionEntryStatus::PendingExtra)->update([
                    'extra_slot_starts_at' => $slots[$i]['starts_at'],
                    'extra_slot_ends_at' => $slots[$i]['ends_at'],
                    'notified_soon_at' => null,
                    'notified_start_at' => null,
                    'notified_extra_at' => $now,
                ]);

                if ($changed > 0 && $entry->teacher?->user !== null) {
                    $this->notifyTeacher($entry->teacher->user, new SelectionExtraRoundScheduled($selection->term_id, $selection->modality_id, $slots[$i]['starts_at']));
                    $notices++;
                }
            }
        }

        $selection->update([
            'status' => SelectionStatus::ExtraRound,
            'extra_round_starts_at' => $slots[0]['starts_at'],
            'extra_round_ends_at' => $slots[count($slots) - 1]['ends_at'],
        ]);

        return [1, $notices];
    }

    /**
     * Termina la ronda extra: los que siguen sin cerrar quedan sin resolver y el jefe y el JPD reciben la lista.
     *
     * @return array{0: int, 1: int}
     */
    private function finish(Selection $selection, CarbonImmutable $now): array
    {
        $pending = $selection->entries()
            ->with('teacher')
            ->where('status', SelectionEntryStatus::PendingExtra)
            ->orderBy('position')
            ->get();

        $selection->entries()->where('status', SelectionEntryStatus::PendingExtra)->update(['status' => SelectionEntryStatus::Unresolved]);
        $selection->update(['status' => SelectionStatus::Finished, 'finished_at' => $now]);
        $advanced = 1 + $pending->count();

        if ($pending->isEmpty()) {
            return [$advanced, 0];
        }

        // Quienes asignan docente en el departamento son el jefe y el JPD (el admin no entra por serlo).
        $holders = $this->recipients->holders(StaffingPermission::TeacherAssign, Target::of(modality: $selection->modality_id, unit: $selection->department_id));
        $names = $pending->map(fn (SelectionEntry $entry): string => $entry->teacher?->name ?? $entry->csv_name ?? 'Sin nombre')->all();
        $notice = new SelectionFinishedWithPending($selection->term_id, $selection->modality_id, $selection->department()->value('name') ?? '', $names);

        return [$advanced, $this->notifyHolders->handle($holders, $notice)->count()];
    }

    /**
     * El aviso va al puesto con el que el docente elige (`staffing.selection.pick_own`), así al abrirlo la sesión actúa con él.
     */
    private function notifyTeacher(User $user, NexusNotification $notice): void
    {
        $granting = $this->grants->rolesGranting(StaffingPermission::SelectionPickOwn);
        $role = $user->roleAssignments()->current()->get()->first(fn ($assignment): bool => in_array($assignment->role, $granting, true))?->role;

        $user->notify($role === null ? $notice : $notice->addressedTo($role));
    }

    private function time(string $value): string
    {
        return substr($value, 0, 5);
    }

    /**
     * @param  array{advanced: int, notices: int}  $result
     * @return array{advanced: int, notices: int}
     */
    private function add(array $result, int $advanced, int $notices): array
    {
        return ['advanced' => $result['advanced'] + $advanced, 'notices' => $result['notices'] + $notices];
    }
}
