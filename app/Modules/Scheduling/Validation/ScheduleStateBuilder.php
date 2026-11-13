<?php

namespace App\Modules\Scheduling\Validation;

use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateQuota;
use App\Modules\Scheduling\Validation\State\StateRoom;
use App\Modules\Scheduling\Validation\State\StateRoomBlock;
use App\Modules\Scheduling\Validation\State\StateRoomLoan;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\State\StateSubject;
use App\Modules\Scheduling\Validation\State\StateTeacher;

/**
 * Arma un ScheduleState pieza por pieza: lo usa ScheduleStateQuery al leer la base y lo usan las pruebas unitarias
 * de las reglas para describir un horario sin base. Las horas entran como `HH:MM`.
 */
final class ScheduleStateBuilder
{
    /** @var array<string, StateGroup> */
    private array $groups = [];

    /** @var array<string, list<StateSession>> */
    private array $sessions = [];

    /** @var array<string, StateSubject> */
    private array $subjects = [];

    /** @var array<string, StateRoom> */
    private array $rooms = [];

    /** @var array<string, StateTeacher> */
    private array $teachers = [];

    /** @var array<string, list<StateRoomBlock>> */
    private array $blocks = [];

    /** @var array<string, list<StateQuota>> */
    private array $quotas = [];

    /** @var array<string, list<StateRoomLoan>> */
    private array $loans = [];

    /** @var array<string, ModalityProfile> por modalidad */
    private array $profiles = [];

    /** @var array<string, true> */
    private array $offerings = [];

    /** @var array<string, true> */
    private array $activePlans = [];

    /** @var array<string, mixed> */
    private array $settings = [];

    /** @var array<string, string> */
    private array $campusCodes = [];

    public function __construct(private readonly string $termId = 'term') {}

    public function group(StateGroup $group): self
    {
        $this->groups[$group->id] = $group;
        $this->sessions[$group->id] ??= [];

        return $this;
    }

    /**
     * Una sesión de un grupo ya agregado; las horas en `HH:MM`.
     */
    public function session(StateSession|string $groupIdOrSession, ?int $day = null, ?string $startsAt = null, ?string $endsAt = null, ?string $roomId = null, ?string $id = null): self
    {
        $session = $groupIdOrSession instanceof StateSession
            ? $groupIdOrSession
            : new StateSession($id ?? $groupIdOrSession.'-'.count($this->sessions[$groupIdOrSession] ?? []), $groupIdOrSession, (int) $day, Minutes::of((string) $startsAt), Minutes::of((string) $endsAt), $roomId);

        $this->sessions[$session->groupId][] = $session;

        return $this;
    }

    public function subject(StateSubject $subject): self
    {
        $this->subjects[$subject->id] = $subject;

        return $this;
    }

    public function room(StateRoom $room): self
    {
        $this->rooms[$room->id] = $room;

        return $this;
    }

    public function teacher(StateTeacher $teacher): self
    {
        $this->teachers[$teacher->id] = $teacher;

        return $this;
    }

    public function block(StateRoomBlock $block): self
    {
        $this->blocks[$block->roomId][] = $block;

        return $this;
    }

    public function quota(StateQuota $quota): self
    {
        $this->quotas[$quota->groupId][] = $quota;

        return $this;
    }

    public function loan(StateRoomLoan $loan): self
    {
        $this->loans[$loan->groupId][] = $loan;

        return $this;
    }

    /**
     * El perfil de flujo de una modalidad: el que leen las reglas para los grupos de esa modalidad.
     */
    public function profile(string $modalityId, ModalityProfile $profile): self
    {
        $this->profiles[$modalityId] = $profile;

        return $this;
    }

    public function campusCode(string $campusId, string $code): self
    {
        $this->campusCodes[$campusId] = $code;

        return $this;
    }

    public function offering(string $careerId, string $campusId): self
    {
        $this->offerings["{$careerId}|{$campusId}"] = true;

        return $this;
    }

    public function activePlan(string $studyPlanId): self
    {
        $this->activePlans[$studyPlanId] = true;

        return $this;
    }

    public function setting(string $key, mixed $value): self
    {
        $this->settings[$key] = $value;

        return $this;
    }

    public function build(): ScheduleState
    {
        return new ScheduleState(
            $this->termId,
            $this->groups,
            $this->sessions,
            $this->subjects,
            $this->rooms,
            $this->teachers,
            $this->blocks,
            $this->quotas,
            $this->loans,
            $this->profiles,
            $this->offerings,
            $this->activePlans,
            $this->settings,
            $this->campusCodes,
        );
    }
}
