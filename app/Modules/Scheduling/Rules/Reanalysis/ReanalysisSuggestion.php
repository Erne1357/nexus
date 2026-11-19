<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;

/**
 * Una sugerencia de ReanalysisAdvisor para una materia en un campus (tarea 8.3a), con su evidencia (esperados contra
 * lugares, el porqué) y su efecto (qué cambia y a quién toca). `key` la identifica entre peticiones mientras el grupo y la
 * carrera sean los mismos: «expand_quota:<grupo>:<carrera>», «change_room:<grupo>:<carrera>», «close_group:<grupo>» y
 * «open_group:<carrera>».
 *
 * - Ampliar cupo y cambiar salón suman `seats` lugares a la carrera `careerId` en el grupo `groupId`, hasta `maxSeats`
 *   (lo que se puede editar antes de aplicar); cambiar salón lleva además las sesiones que se mueven (`moves`).
 * - Cerrar grupo cancela `groupId` y suma a otros grupos los lugares de `relocations`.
 * - Abrir grupo (tarea 8.3b) crea el grupo `newGroup` (NewGroupSpec) para la carrera `careerId`, con `seats` lugares, en el
 *   horario y el salón que el motor encuentre al abrir la tarjeta (OpenReanalysisGroup). Sin `newGroup` (sin campus o sin
 *   plan vigente) solo avisa.
 *
 * `blockedReason` dice por qué todavía no se puede aplicar aunque se vea (cerrar un grupo con docente: primero se le quita,
 * como pide CancelGroup). `raisesCapacity`, si con los lugares sugeridos sube la capacidad del grupo (pide además
 * GroupPolicy::manage). `expected` es la capacidad y los cupos de cada grupo que toca tal como estaban al calcularla: quien
 * aplica los compara con los grupos ya bloqueados y, si cambiaron (otra pestaña la aplicó primero), no escribe.
 *
 * Quien aplica (ApplyReanalysisSuggestion) suma esos lugares a lo que el grupo tenga en ese momento y deja que las
 * Actions de la fase 3 revisen otra vez HC-05 y HC-07.
 */
final readonly class ReanalysisSuggestion
{
    /**
     * @param  list<string>  $evidence
     * @param  list<string>  $effect
     * @param  list<array{sessionId: string, when: string, from: string, fromId: string, to: AdvisorRoom, day: int, startsAt: string, endsAt: string}>  $moves
     * @param  list<array{groupId: string, groupCode: string, careerId: string, careerCode: string, seats: int}>  $relocations
     * @param  array<string, array{capacity: int, quotas: array<string, int>}>  $expected
     */
    public function __construct(
        public string $key,
        public ReanalysisSuggestionKind $kind,
        public string $title,
        public array $evidence,
        public array $effect,
        public ?string $warning = null,
        public ?string $groupId = null,
        public ?string $careerId = null,
        public int $seats = 0,
        public int $maxSeats = 0,
        public array $moves = [],
        public array $relocations = [],
        public ?string $blockedReason = null,
        public bool $raisesCapacity = false,
        public array $expected = [],
        public ?NewGroupSpec $newGroup = null,
    ) {}

    /**
     * Si tiene «Aplicar»: todas menos abrir un grupo sin `newGroup`. Abrir un grupo, además, solo después de que el motor
     * le encuentre lugar.
     */
    public function isApplicable(): bool
    {
        return $this->kind !== ReanalysisSuggestionKind::OpenGroup || $this->newGroup !== null;
    }

    /**
     * Si pide el préstamo de un salón de otro departamento (HC-10): cambiar salón a uno ajeno, como lo dice «Qué cambia».
     */
    public function asksLoan(): bool
    {
        return array_any($this->moves, fn (array $move): bool => $move['to']->loanFrom !== null);
    }

    /**
     * Si el jefe puede cambiar cuántos lugares suma antes de aplicarla.
     */
    public function isEditable(): bool
    {
        return $this->kind->addsSeats() && $this->maxSeats > 1;
    }

    /**
     * Los grupos que toca: el suyo y los que reciben lugares.
     *
     * @return list<string>
     */
    public function groupIds(): array
    {
        return array_values(array_unique(array_filter([
            $this->groupId,
            ...array_map(fn (array $relocation): string => $relocation['groupId'], $this->relocations),
        ])));
    }
}
