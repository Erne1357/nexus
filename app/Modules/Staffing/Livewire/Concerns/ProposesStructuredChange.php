<?php

namespace App\Modules\Staffing\Livewire\Concerns;

use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Enums\ChangeRequestKind;

/**
 * Lo que se propone en forma que el validador entiende (CR-01, tarea 8.6), opcional en el modal de «Solicitar cambio»:
 * las sesiones nuevas de un cambio de horario (`proposedSessions`, día y horas) o la clave del salón de uno de salón
 * (`proposedRoom`). RequestChange los valida; sin ellos el impacto de la solicitud no se puede calcular.
 */
trait ProposesStructuredChange
{
    /** @var list<array{day: string, starts_at: string, ends_at: string}> */
    public array $proposedSessions = [];

    public string $proposedRoom = '';

    public function addProposedSession(): void
    {
        if (count($this->proposedSessions) < 6) {
            $this->proposedSessions[] = ['day' => '', 'starts_at' => '', 'ends_at' => ''];
        }
    }

    /**
     * Arranca el «Horario propuesto» con las sesiones de hoy del grupo (la propuesta es el horario completo: quien pide
     * edita solo la que mueve). Recibe un grupo ya autorizado por la pantalla.
     */
    private function prefillProposedSessions(Group $group): void
    {
        $this->proposedSessions = $group->sessions
            ->sortBy([['day', 'asc'], ['starts_at', 'asc']])
            ->map(fn (GroupSession $session): array => [
                'day' => (string) $session->day,
                'starts_at' => substr((string) $session->starts_at, 0, 5),
                'ends_at' => substr((string) $session->ends_at, 0, 5),
            ])
            ->values()
            ->all();
    }

    public function removeProposedSession(int $index): void
    {
        unset($this->proposedSessions[$index]);
        $this->proposedSessions = array_values($this->proposedSessions);
    }

    /**
     * @return array{sessions?: list<array{day: string, starts_at: string, ends_at: string}>, room?: string}
     */
    private function structuredProposal(): array
    {
        if ($this->requestKind === ChangeRequestKind::Room->value) {
            return trim($this->proposedRoom) === '' ? [] : ['room' => $this->proposedRoom];
        }

        return $this->proposedSessions === [] ? [] : ['sessions' => $this->proposedSessions];
    }

    private function resetStructuredProposal(): void
    {
        $this->reset('proposedSessions', 'proposedRoom');
    }
}
