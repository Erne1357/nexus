<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guarda el panel de un paquete (tarea 5.6b): sus materias (UpdateProposalPackageSubjects) y luego su nombre
 * (RenameProposalPackage), en una sola transacción. Si el nombre no se acepta o la propuesta deja de editarse entre las dos,
 * no queda nada a medias: tampoco cambian las materias. Cada una deja su bitácora y su aviso, que salen al terminar la
 * transacción. Quién puede lo decide CareerProposalPolicy::managePackages.
 */
final class UpdateProposalPackage
{
    public function __construct(
        private readonly UpdateProposalPackageSubjects $subjects,
        private readonly RenameProposalPackage $rename,
    ) {}

    /**
     * @param  list<string>  $subjectIds
     *
     * @throws ValidationException
     */
    public function handle(ProposalPackage $package, array $subjectIds, ?string $name, User $actor): ProposalPackage
    {
        return DB::transaction(fn (): ProposalPackage => $this->rename->handle($this->subjects->handle($package, $subjectIds, $actor), $name, $actor));
    }
}
