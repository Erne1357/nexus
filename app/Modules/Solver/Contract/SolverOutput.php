<?php

namespace App\Modules\Solver\Contract;

/** La salida del motor de horarios, versión 1 (docs/arquitectura/solver.md, «Contrato JSON»). */
final readonly class SolverOutput
{
    /**
     * @param  list<ProposedSession>  $sessions
     * @param  list<TeacherSuggestion>  $teacherSuggestions
     * @param  list<UnscheduledGroup>  $unscheduled
     * @param  list<HardViolation>  $hardViolations  Vacía para `optimal` y `feasible`.
     * @param  list<RelaxedRule>  $relaxedRules
     * @param  list<string>  $log
     */
    public function __construct(
        public int $version,
        public string $runId,
        public RunStatus $status,
        public array $sessions,
        public array $teacherSuggestions,
        public array $unscheduled,
        public array $hardViolations,
        public SoftScore $softScore,
        public array $relaxedRules,
        public RunMetrics $metrics,
        public array $log,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $version = ContractFields::int($data, 'version', $path, 0);
        if ($version !== SolverInput::VERSION) {
            throw ContractException::at(ContractFields::path($path, 'version'), 'versión no soportada: '.$version.' (se soporta '.SolverInput::VERSION.')');
        }

        $status = ContractFields::enum($data, 'status', $path, RunStatus::class);
        $hardViolations = ContractFields::objects($data, 'hard_violations', $path, HardViolation::class);
        if ($hardViolations !== [] && in_array($status, [RunStatus::Optimal, RunStatus::Feasible], true)) {
            throw ContractException::at(ContractFields::path($path, 'hard_violations'), "debe estar vacía cuando status es {$status->value}");
        }

        return new self(
            $version,
            ContractFields::string($data, 'run_id', $path),
            $status,
            ContractFields::objects($data, 'sessions', $path, ProposedSession::class),
            ContractFields::objects($data, 'teacher_suggestions', $path, TeacherSuggestion::class, false),
            ContractFields::objects($data, 'unscheduled', $path, UnscheduledGroup::class),
            $hardViolations,
            SoftScore::fromArray(ContractFields::object($data, 'soft_score', $path), ContractFields::path($path, 'soft_score')),
            ContractFields::objects($data, 'relaxed_rules', $path, RelaxedRule::class, false),
            RunMetrics::fromArray(ContractFields::object($data, 'metrics', $path), ContractFields::path($path, 'metrics')),
            ContractFields::strings($data, 'log', $path, false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $each = fn (array $list): array => array_map(fn (object $o): array => $o->toArray(), $list);

        return [
            'version' => $this->version,
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'sessions' => $each($this->sessions),
            'teacher_suggestions' => $each($this->teacherSuggestions),
            'unscheduled' => $each($this->unscheduled),
            'hard_violations' => $each($this->hardViolations),
            'soft_score' => $this->softScore->toArray(),
            'relaxed_rules' => $each($this->relaxedRules),
            'metrics' => $this->metrics->toArray(),
            'log' => $this->log,
        ];
    }
}
