<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\CareerProposalStarted;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Rules\ProposalAvailability;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Students\Queries\EligibleStudentsQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Arma la propuesta de una carrera en un campus y un periodo (reglas-de-negocio.md, "Propuesta de carrera"), o
 * devuelve la que ya existe, esté en el estado que esté y sin tocarla: es idempotente.
 *
 * La primera vez exige el periodo abierto en el campus (`term_openings`) y la carrera ofrecida en él
 * (`career_campus` activa y sin borrar, que lee con bloqueo compartido); si no, una ValidationException sobre
 * `term` o `career` (supuestos por confirmar). Crea la propuesta en borrador y una fila por cada materia activa
 * y sin borrar de la retícula de los planes activos y sin borrar de la carrera (HC-13), una sola vez por materia,
 * con el plan y el semestre del primer plan por clave. Cada fila lleva los elegibles del momento
 * (EligibleStudentsQuery), esperados = elegibles y los grupos sugeridos (SuggestedGroups; confirmado el 2 de
 * noviembre de 2026). La propuesta guarda los alumnos posibles: los distintos que son elegibles para alguna de
 * sus materias (confirmado el 4 de noviembre de 2026). Deja bitácora de la propuesta y, después del commit, avisa con CareerProposalStarted.
 *
 * La propuesta se inserta ignorando la que ya exista (la unicidad por periodo, campus y carrera): si otra sesión
 * la armó entre la revisión y la escritura, devuelve esa sin agregarle filas. Quién puede armarla lo decide
 * CareerProposalPolicy::create antes de llamarla; quien la arma se recibe como parámetro.
 */
final class StartCareerProposal
{
    /**
     * Los tipos de materia que no entran a la propuesta (confirmado el 2 de noviembre de 2026).
     *
     * @var list<string>
     */
    private const array OUTSIDE_PROPOSAL = [SubjectKind::Residencia->value, SubjectKind::ServicioSocial->value, SubjectKind::Complementaria->value];

    public function __construct(private readonly EligibleStudentsQuery $eligibleStudents, private readonly ProposalAvailability $availability) {}

    /**
     * @throws ValidationException si el periodo no está abierto en el campus o la carrera no se ofrece en él
     */
    public function handle(Term $term, Campus $campus, Career $career, User $actor): CareerProposal
    {
        $existing = $this->keyed($term, $campus, $career)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($term, $campus, $career, $actor): CareerProposal {
            $this->availability->ensure($term, $campus, $career);

            $now = now();
            $inserted = CareerProposal::query()->insertOrIgnore([
                'term_id' => $term->getKey(),
                'campus_id' => $campus->getKey(),
                'career_id' => $career->getKey(),
                'status' => ProposalStatus::Draft->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $proposal = $this->keyed($term, $campus, $career)->firstOrFail();

            if ($inserted === 0) {
                return $proposal;
            }

            $rows = $this->rows($proposal, $career, $campus);

            if ($rows !== []) {
                ProposalItem::query()->insert($rows);
            }

            $proposal->forceFill([
                'eligible_students' => $this->eligibleStudents->distinctFor($career, $campus, array_column($rows, 'subject_id')),
            ])->saveQuietly();

            activity()
                ->performedOn($proposal)
                ->causedBy($actor)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => [
                    'term_id' => $proposal->term_id,
                    'campus_id' => $proposal->campus_id,
                    'career_id' => $proposal->career_id,
                    'status' => $proposal->status->value,
                ]])
                ->withProperties(['items' => count($rows)])
                ->log("Armó la propuesta de {$career->code} en {$campus->code} para el periodo {$term->code}");

            CareerProposalStarted::dispatch($proposal);

            return $proposal;
        });
    }

    /**
     * @return Builder<CareerProposal>
     */
    private function keyed(Term $term, Campus $campus, Career $career): Builder
    {
        return CareerProposal::query()
            ->where('term_id', $term->getKey())
            ->where('campus_id', $campus->getKey())
            ->where('career_id', $career->getKey());
    }

    /**
     * Las filas de la propuesta, listas para insertar: una por materia de la retícula, del primer plan por clave.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(CareerProposal $proposal, Career $career, Campus $campus): array
    {
        $eligible = $this->eligibleStudents->countsFor($career, $campus);
        $suggested = SuggestedGroups::fromSettings();
        $now = now();

        return $this->placements($career)
            ->map(function (object $placement) use ($proposal, $eligible, $suggested, $now): array {
                $students = $eligible[$placement->subject_id] ?? 0;

                return [
                    'career_proposal_id' => $proposal->getKey(),
                    'subject_id' => $placement->subject_id,
                    'study_plan_id' => $placement->study_plan_id,
                    'semester' => (int) $placement->semester,
                    'eligible_students' => $students,
                    'expected_students' => $students,
                    'requested_groups' => $suggested->for($students, (bool) $placement->needs_lab),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Las materias activas de la retícula de los planes activos de la carrera, sin lo borrado ni lo quitado de la
     * retícula, una vez cada una: la del primer plan por clave. Sin residencia, servicio social ni actividades
     * complementarias, que no abren grupos de clase, y sin las materias sin horas de clase, tengan o no capturado
     * su tipo, porque el SII no lo trae (confirmado el 2 de noviembre de 2026).
     *
     * @return Collection<int, object{subject_id: string, study_plan_id: string, semester: int, needs_lab: bool}>
     */
    private function placements(Career $career): Collection
    {
        return StudyPlanSubject::query()
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->where('study_plans.career_id', $career->getKey())
            ->where('study_plans.is_active', true)
            ->whereNull('study_plans.deleted_at')
            ->where('subjects.is_active', true)
            ->whereNull('subjects.deleted_at')
            ->where(fn ($query) => $query->whereNull('subjects.kind')->orWhereNotIn('subjects.kind', self::OUTSIDE_PROPOSAL))
            ->whereRaw('subjects.theory_hours + subjects.practice_hours > 0')
            ->orderBy('study_plans.code')
            ->orderBy('study_plan_subjects.semester')
            ->orderBy('subjects.code')
            ->toBase()
            ->get(['study_plan_subjects.subject_id', 'study_plan_subjects.study_plan_id', 'study_plan_subjects.semester', 'subjects.needs_lab'])
            ->unique('subject_id');
    }
}
