<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalPackagesChanged;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Rules\ProposalPackageGuard;
use App\Modules\AcademicLoad\Rules\ProposalPackageRules;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Corrige el nuevo ingreso esperado de una columna de la propuesta (tarea 5.6b; DM-09; confirmado el 16 y 17 de noviembre
 * de 2026: Nexus lo precarga y el coordinador lo puede corregir). De 0 a 1000. Lo sugerido se guarda aparte
 * (`suggested_new_students`) y lo corregido queda marcado (`captured_at`, `captured_by`): la proyección ya no lo mueve
 * sola. Sin cambios no escribe. Como los paquetes sugeridos salen del nuevo ingreso vigente, después pone al día los
 * paquetes sin tocar de esa columna (SyncPackageCounts) y, con ellos, los esperados y los grupos de las filas sin tocar de
 * primer semestre de la columna (SyncProposalSuggestions: los de la proyección menos su nuevo ingreso más el corregido;
 * supuesto por confirmar, pedido el 17 de noviembre de 2026). Las filas corregidas no se tocan.
 *
 * En una transacción bloquea la propuesta, en borrador o regresada. Quién puede lo decide
 * CareerProposalPolicy::updateColumn antes de llamarla (M7).
 */
final class UpdateProposalIntake
{
    public function __construct(
        private readonly ProposalPackageGuard $guard,
        private readonly ProposalPackageRules $rules,
        private readonly SyncPackageCounts $sync,
        private readonly SyncProposalSuggestions $suggestions,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(CareerProposal $proposal, ?Campus $campus, int $newStudents, User $actor): ProposalIntake
    {
        $this->rules->ensureNewStudents($newStudents);

        return DB::transaction(function () use ($proposal, $campus, $newStudents, $actor): ProposalIntake {
            $proposal = $this->guard->lockEditable($proposal->getKey());

            $intake = ProposalIntake::query()
                ->where('career_proposal_id', $proposal->getKey())
                ->where(fn ($query) => $campus === null ? $query->whereNull('campus_id') : $query->where('campus_id', $campus->getKey()))
                ->lockForUpdate()
                ->first();

            if ($intake === null) {
                throw ValidationException::withMessages(['campus' => 'Esa columna no está en la propuesta.']);
            }

            if ($intake->new_students === $newStudents) {
                return $intake;
            }

            $old = $intake->new_students;
            $intake->forceFill(['new_students' => $newStudents, 'captured_at' => now(), 'captured_by' => $actor->getKey()])->save();

            $this->sync->handle($proposal, $actor);
            // Las filas sin tocar de primer semestre de la columna siguen al nuevo ingreso corregido (SyncProposalSuggestions).
            $this->suggestions->handle($proposal->term_id, $proposal->modality_id, $intake->campus_id, [$proposal->career_id]);

            activity()
                ->performedOn($intake)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['new_students' => $newStudents], 'old' => ['new_students' => $old]])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'campus_id' => $intake->campus_id])
                ->log('Corrigió el nuevo ingreso'.($campus === null ? '' : " de {$campus->code}")." en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalPackagesChanged::dispatch($proposal->getKey());

            return $intake;
        });
    }
}
