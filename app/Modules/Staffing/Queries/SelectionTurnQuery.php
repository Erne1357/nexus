<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * El turno vigente de una selección (tarea 1.33, diseño 6.3), calculado con las horas guardadas de cada renglón y la
 * hora que se le da, no con el comando que la avanza: así la pantalla y las Actions coinciden aunque el comando se
 * atrase. Publicada o en curso es la primera ronda: tiene turno el renglón que espera (`waiting`) con
 * `slot_starts_at <= ahora < slot_ends_at`. En la ronda extra, el pendiente (`pending_extra`) con su hora extra. Un
 * renglón terminado (`done`) nunca tiene turno, ni quien ya cerró con menos horas en la primera ronda; un borrador, una
 * terminada o una cancelada no tienen turno.
 */
final class SelectionTurnQuery
{
    /**
     * El primer renglón (por lugar) con turno ahora; varios comparten la misma media hora (SEL-07).
     */
    public function currentEntry(Selection $selection, CarbonImmutable $now): ?SelectionEntry
    {
        return $this->inTurn($selection, $now)?->orderBy('position')->orderBy('id')->first();
    }

    public function isTurnOf(Selection $selection, Teacher $teacher, CarbonImmutable $now): bool
    {
        return $this->inTurn($selection, $now)?->where('teacher_id', $teacher->getKey())->exists() ?? false;
    }

    /**
     * Todos los renglones con turno ahora, por lugar y con su docente: los que comparten la media hora (SEL-07) se
     * nombran juntos en la barra del turno y salen «En turno» en el orden. Una consulta.
     *
     * @return Collection<int, SelectionEntry>
     */
    public function entriesInTurn(Selection $selection, CarbonImmutable $now): Collection
    {
        return $this->inTurn($selection, $now)?->orderBy('position')->orderBy('id')->with('teacher:id,name')->get() ?? new Collection;
    }

    /**
     * @return Builder<SelectionEntry>|null
     */
    private function inTurn(Selection $selection, CarbonImmutable $now): ?Builder
    {
        [$status, $starts, $ends] = match ($selection->status) {
            SelectionStatus::Published, SelectionStatus::Running => [SelectionEntryStatus::Waiting, 'slot_starts_at', 'slot_ends_at'],
            SelectionStatus::ExtraRound => [SelectionEntryStatus::PendingExtra, 'extra_slot_starts_at', 'extra_slot_ends_at'],
            default => [null, null, null],
        };

        if ($status === null) {
            return null;
        }

        // Las fechas se mandan sin desfase en la zona de la conexión (config/database.php).
        $at = $now->setTimezone(config('app.timezone'));

        return SelectionEntry::query()
            ->where('selection_id', $selection->getKey())
            ->where('status', $status->value)
            ->where($starts, '<=', $at)
            ->where($ends, '>', $at);
    }
}
