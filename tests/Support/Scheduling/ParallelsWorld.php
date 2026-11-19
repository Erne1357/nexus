<?php

namespace Tests\Support\Scheduling;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;

/**
 * Armar paralelos (tarea 8.8): Ciencias Básicas (CB) imparte Cálculo Integral en Campus I a carreras de otros
 * departamentos, en Presencial y Ene-Jun 2027, con su carga en propuesta. Cada carrera tiene un plan, se ofrece en los
 * campus del mundo y pide la materia en su propuesta con su parte de CB aceptada, pendiente o regresada; los grupos
 * sueltos nacen como los crea CreateGroupsFromProposal (dueña = la carrera, cupo de toda la capacidad). Nombres y claves
 * ficticios.
 */
final class ParallelsWorld
{
    private int $codes = 0;

    /** @var array<string, CareerProposal> por id de carrera */
    private array $proposals = [];

    /** @var array<string, StudyPlan> por id de carrera */
    private array $plans = [];

    private function __construct(
        public readonly Term $term,
        public readonly Modality $modality,
        public readonly Campus $campus,
        public readonly Department $department,
        public readonly Subject $subject,
        public readonly AcademicLoad $load,
    ) {}

    public static function create(LoadStatus $status = LoadStatus::Proposed): self
    {
        $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
        $modality = Modality::query()->where('code', 'PRES')->sole();
        $campus = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $department = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
        $subject = Subject::factory()->create(['code' => 'ACF-0902', 'name' => 'Cálculo Integral', 'department_id' => $department->id, 'needs_lab' => false]);
        $load = AcademicLoad::factory()->inStatus($status)->create(['term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $department->id]);

        return new self($term, $modality, $campus, $department, $subject, $load);
    }

    /**
     * Una carrera de otro departamento (o del dado), ofrecida en los campus del mundo y en los que se pidan.
     */
    public function career(string $code, ?Department $department = null, Campus ...$campuses): Career
    {
        $career = Career::factory()->create(['code' => $code, 'name' => "Ingeniería {$code}", 'department_id' => ($department ?? Department::factory()->create(['code' => "D-{$code}"]))->id]);
        $this->plans[$career->id] = StudyPlan::factory()->create(['career_id' => $career->id]);

        foreach ([$this->campus, ...$campuses] as $campus) {
            CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id]);
        }

        return $career;
    }

    /**
     * Lo que la carrera pide de la materia (la del mundo o la dada) en el campus (el del mundo o el dado), con su parte de CB
     * en el estado dado: `accepted`, `pending` o `returned` (la propuesta regresada por CB). Una propuesta por carrera.
     */
    public function request(Career $career, int $expected, int $requested, string $part = 'accepted', ?Subject $subject = null, ?Campus $campus = null, int $packageGroups = 0): ProposalItem
    {
        $proposal = $this->proposals[$career->id] ??= CareerProposal::factory()->submitted()->create([
            'term_id' => $this->term->id,
            'modality_id' => $this->modality->id,
            'career_id' => $career->id,
            'status' => $part === 'returned' ? ProposalStatus::Returned : ProposalStatus::Submitted,
        ]);

        $factory = match ($part) {
            'accepted' => ProposalReview::factory()->accepted(),
            'returned' => ProposalReview::factory()->returned(),
            default => ProposalReview::factory(),
        };
        $review = ProposalReview::query()->where('career_proposal_id', $proposal->id)->where('department_id', $this->department->id)->first()
            ?? $factory->create(['career_proposal_id' => $proposal->id, 'department_id' => $this->department->id]);

        return ProposalItem::factory()->create([
            'career_proposal_id' => $review->career_proposal_id,
            'campus_id' => ($campus ?? $this->campus)->id,
            'subject_id' => ($subject ?? $this->subject)->id,
            'study_plan_id' => $this->plans[$career->id]->id,
            'semester' => 2,
            'eligible_students' => $expected,
            'expected_students' => $expected,
            'requested_groups' => $requested,
            'package_groups' => $packageGroups,
        ]);
    }

    /**
     * Los grupos sueltos que nacieron de la fila al aceptarla: dueña la carrera, cupo de toda la capacidad, clave
     * provisional consecutiva.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<Group>
     */
    public function groupsOf(ProposalItem $item, int $count = 1, array $attributes = []): array
    {
        $groups = [];

        for ($i = 0; $i < $count; $i++) {
            $groups[] = Group::factory()->forProposalItem($item)->withQuota()->create(['code' => (string) ++$this->codes, ...$attributes]);
        }

        return $groups;
    }

    public function withTeacher(Group $group): Group
    {
        $group->update(['teacher_id' => Teacher::factory()->create(['name' => 'Mtra. Laura Benavides'])->id]);

        return $group;
    }

    /**
     * Una sesión de teoría sin salón el día dado, de 7:00 a 7:00 más las horas.
     */
    public function withSession(Group $group, int $day = 1, int $hours = 2): GroupSession
    {
        return GroupSession::factory()->on($day, '07:00', sprintf('%02d:00', 7 + $hours))->create(['group_id' => $group->id]);
    }
}
