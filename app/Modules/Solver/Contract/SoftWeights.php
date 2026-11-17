<?php

namespace App\Modules\Solver\Contract;

/** El peso de cada regla blanda SC-01 a SC-09. Cero la desactiva; una llave que falta vale cero. */
final readonly class SoftWeights
{
    public const RULES = ['SC-01', 'SC-02', 'SC-03', 'SC-04', 'SC-05', 'SC-06', 'SC-07', 'SC-08', 'SC-09'];

    /**
     * @param  array<string, int>  $weights  Una entrada por cada regla de `RULES`.
     */
    public function __construct(public array $weights) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::RULES, true)) {
                throw ContractException::at(ContractFields::path($path, (string) $key), 'regla blanda desconocida');
            }
        }

        $weights = [];
        foreach (self::RULES as $rule) {
            $weights[$rule] = ContractFields::int($data, $rule, $path, 0, null, false) ?? 0;
        }

        return new self($weights);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->weights;
    }
}
