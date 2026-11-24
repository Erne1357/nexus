<?php

namespace App\Modules\Core\Queries;

final readonly class MigrationPlan
{
    /**
     * @param  list<string>  $pending  en el código, no en la base
     * @param  list<string>  $unknown  en la base, no en el código (pasa tras volver a una imagen anterior)
     * @param  list<string>  $duplicates  con el mismo nombre en dos carpetas
     * @param  list<string>  $pendingContracts  las pendientes marcadas con #[Contract]
     */
    public function __construct(
        public array $pending = [],
        public array $unknown = [],
        public array $duplicates = [],
        public array $pendingContracts = [],
    ) {}

    /** @return array{pending: list<string>, unknown: list<string>, duplicates: list<string>, pending_contracts: list<string>} */
    public function toArray(): array
    {
        return [
            'pending' => $this->pending,
            'unknown' => $this->unknown,
            'duplicates' => $this->duplicates,
            'pending_contracts' => $this->pendingContracts,
        ];
    }
}
