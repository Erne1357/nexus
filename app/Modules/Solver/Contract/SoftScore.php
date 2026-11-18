<?php

namespace App\Modules\Solver\Contract;

/** El puntaje blando: total y penalización por regla SC-01 a SC-09. Menor es mejor. */
final readonly class SoftScore
{
    /**
     * @param  array<string, int>  $byRule
     */
    public function __construct(public int $total, public array $byRule) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $total = ContractFields::int($data, 'total', $path, 0);
        $byRulePath = ContractFields::path($path, 'by_rule');
        $byRule = [];
        foreach (ContractFields::object($data, 'by_rule', $path) as $rule => $value) {
            if (! in_array($rule, SoftWeights::RULES, true)) {
                throw ContractException::at(ContractFields::path($byRulePath, (string) $rule), 'regla blanda desconocida');
            }
            $byRule[$rule] = ContractFields::int([$rule => $value], $rule, $byRulePath, 0);
        }

        return new self($total, $byRule);
    }

    /**
     * @return array{total: int, by_rule: array<string, int>}
     */
    public function toArray(): array
    {
        return ['total' => $this->total, 'by_rule' => $this->byRule];
    }
}
