<?php

namespace App\Modules\Solver\Contract;

/** La entrada del motor de horarios, versión 1 (docs/arquitectura/solver.md, «Contrato JSON»). */
final readonly class SolverInput
{
    public const VERSION = 1;

    /**
     * @param  list<CampusGrid>  $campuses
     * @param  list<GroupInput>  $groups
     * @param  list<TeacherInput>  $teachers
     * @param  list<RoomInput>  $rooms
     * @param  list<RoomAgreement>  $agreements
     * @param  list<SubjectRoomDefault>  $subjectRoomDefaults
     * @param  list<string>  $hardRules  Identificadores HC activos.
     * @param  list<HintSession>  $hintSessions  Solución inicial sugerida (opcional; el motor puede ignorarla).
     */
    public function __construct(
        public int $version,
        public string $runId,
        public string $termCode,
        public string $modalityCode,
        public array $campuses,
        public int $timeLimitSeconds,
        public Scope $scope,
        public int $travelMinutes,
        public int $weeklyHoursTolerance,
        public array $groups,
        public array $teachers,
        public array $rooms,
        public array $agreements,
        public array $subjectRoomDefaults,
        public array $hardRules,
        public SoftWeights $softWeights,
        public SolverOptions $options,
        public array $hintSessions = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $version = ContractFields::int($data, 'version', $path, 0);
        if ($version !== self::VERSION) {
            throw ContractException::at(ContractFields::path($path, 'version'), 'versión no soportada: '.$version.' (se soporta '.self::VERSION.')');
        }

        $input = new self(
            $version,
            ContractFields::string($data, 'run_id', $path),
            ContractFields::string(ContractFields::object($data, 'term', $path), 'code', ContractFields::path($path, 'term')),
            ContractFields::string(ContractFields::object($data, 'modality', $path), 'code', ContractFields::path($path, 'modality')),
            ContractFields::objects($data, 'campuses', $path, CampusGrid::class),
            ContractFields::int($data, 'time_limit_seconds', $path, 1),
            Scope::fromArray(ContractFields::object($data, 'scope', $path), ContractFields::path($path, 'scope')),
            ContractFields::int($data, 'travel_minutes', $path, 0),
            ContractFields::int($data, 'weekly_hours_tolerance', $path, 0, required: false) ?? 0,
            ContractFields::objects($data, 'groups', $path, GroupInput::class),
            ContractFields::objects($data, 'teachers', $path, TeacherInput::class),
            ContractFields::objects($data, 'rooms', $path, RoomInput::class, false),
            ContractFields::objects($data, 'agreements', $path, RoomAgreement::class, false),
            ContractFields::objects($data, 'subject_room_defaults', $path, SubjectRoomDefault::class, false),
            self::hardRules($data, $path),
            SoftWeights::fromArray(ContractFields::object($data, 'soft_weights', $path), ContractFields::path($path, 'soft_weights')),
            SolverOptions::fromArray(is_array($data['options'] ?? null) ? $data['options'] : [], ContractFields::path($path, 'options')),
            ContractFields::objects($data, 'hint_sessions', $path, HintSession::class, false),
        );

        $input->assertConsistent($path);

        return $input;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function hardRules(array $data, string $path): array
    {
        $rules = ContractFields::strings($data, 'hard_rules', $path);
        foreach ($rules as $i => $rule) {
            if (! preg_match('/^HC-\d{2}$/', $rule)) {
                throw ContractException::at(ContractFields::path(ContractFields::path($path, 'hard_rules'), $i), 'debe tener la forma HC-NN');
            }
        }

        return $rules;
    }

    private function assertConsistent(string $path): void
    {
        $campusCodes = [];
        foreach ($this->campuses as $i => $campus) {
            $at = ContractFields::path(ContractFields::path($path, 'campuses'), $i);
            if (in_array($campus->code, $campusCodes, true)) {
                throw ContractException::at(ContractFields::path($at, 'code'), "campus repetido: {$campus->code}");
            }
            $campusCodes[] = $campus->code;
        }

        $roomIds = array_map(fn (RoomInput $r): string => $r->id, $this->rooms);
        $teacherIds = array_map(fn (TeacherInput $t): string => $t->id, $this->teachers);

        foreach ($this->rooms as $i => $room) {
            if (! in_array($room->campusCode, $campusCodes, true)) {
                throw ContractException::at(ContractFields::path(ContractFields::path(ContractFields::path($path, 'rooms'), $i), 'campus_code'), "el campus {$room->campusCode} no está en campuses");
            }
        }

        $groupIds = [];
        $allGroupIds = array_map(fn (GroupInput $g): string => $g->id, $this->groups);
        foreach ($this->groups as $i => $group) {
            $at = ContractFields::path(ContractFields::path($path, 'groups'), $i);
            if (! in_array($group->campusCode, $campusCodes, true)) {
                throw ContractException::at(ContractFields::path($at, 'campus_code'), "el campus {$group->campusCode} no está en campuses");
            }
            if (in_array($group->id, $groupIds, true)) {
                throw ContractException::at(ContractFields::path($at, 'id'), "id de grupo repetido: {$group->id}");
            }
            $groupIds[] = $group->id;
            if ($group->teacherId !== null && ! in_array($group->teacherId, $teacherIds, true)) {
                throw ContractException::at(ContractFields::path($at, 'teacher_id'), 'el docente no está en teachers');
            }
            foreach ($group->candidateRoomIds as $j => $roomId) {
                if (! in_array($roomId, $roomIds, true)) {
                    throw ContractException::at(ContractFields::path(ContractFields::path($at, 'candidate_room_ids'), $j), 'el salón no está en rooms');
                }
            }
            foreach ($group->fixedSessions as $j => $session) {
                if ($session->roomId !== null && ! in_array($session->roomId, $roomIds, true)) {
                    throw ContractException::at(ContractFields::path(ContractFields::path(ContractFields::path($at, 'fixed_sessions'), $j), 'room_id'), 'el salón no está en rooms');
                }
            }
        }

        foreach ($this->hintSessions as $i => $hint) {
            $at = ContractFields::path(ContractFields::path($path, 'hint_sessions'), $i);
            if (! in_array($hint->groupId, $allGroupIds, true)) {
                throw ContractException::at(ContractFields::path($at, 'group_id'), 'el grupo no está en groups');
            }
            if ($hint->roomId !== null && ! in_array($hint->roomId, $roomIds, true)) {
                throw ContractException::at(ContractFields::path($at, 'room_id'), 'el salón no está en rooms');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $each = fn (array $list): array => array_map(fn (object $o): array => $o->toArray(), $list);

        $array = [
            'version' => $this->version,
            'run_id' => $this->runId,
            'term' => ['code' => $this->termCode],
            'modality' => ['code' => $this->modalityCode],
            'campuses' => $each($this->campuses),
            'time_limit_seconds' => $this->timeLimitSeconds,
            'scope' => $this->scope->toArray(),
            'travel_minutes' => $this->travelMinutes,
            'weekly_hours_tolerance' => $this->weeklyHoursTolerance,
            'groups' => $each($this->groups),
            'teachers' => $each($this->teachers),
            'rooms' => $each($this->rooms),
            'agreements' => $each($this->agreements),
            'subject_room_defaults' => $each($this->subjectRoomDefaults),
            'hard_rules' => $this->hardRules,
            'soft_weights' => $this->softWeights->toArray(),
            'options' => $this->options->toArray(),
        ];

        if ($this->hintSessions !== []) {
            $array['hint_sessions'] = $each($this->hintSessions);
        }

        return $array;
    }
}
