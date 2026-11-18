<?php

namespace App\Modules\Staffing\Livewire\Concerns;

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
