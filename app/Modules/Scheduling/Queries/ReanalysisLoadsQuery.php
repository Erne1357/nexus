<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Navigation\TermAvailability;
use App\Modules\Core\Queries\KeyLookup;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Qué cargas abre «Re-análisis de la carga» (tarea 8.2, pantalla; RA-01 y RA-07) y a quién: las del periodo activo y la
 * modalidad de la pantalla que ya se publicaron alguna vez (`current_version` > 0), que son las que se re-analizan.
 *
 * - Quien ve la carga (`academic_load.load.view`: el jefe de su departamento, el jefe de proyecto docente, Subdirección y
 *   el admin) ve toda la carga de sus departamentos.
 * - Quien no la ve pero ve la propuesta de una carrera (`academic_load.proposal.view`: el coordinador) ve solo el diff de
 *   sus carreras (RA-07), en las cargas de los departamentos que imparten materias de sus retículas vigentes. Las carreras
 *   salen de su alcance en el servidor (Actor), nunca del navegador.
 *
 * Sin estado propio, para Octane: la condición del menú se guarda una vez y no lleva nada de la petición.
 */
final class ReanalysisLoadsQuery
{
    /** @var (Closure(Actor): ?string)|null */
    private static ?Closure $menuCondition = null;

    /**
     * La condición del menú de «Re-análisis de la carga» (MN-02): disponible mientras el periodo activo tenga alguna carga
     * publicada alguna vez, porque antes no hay nada que re-analizar (supuesto por confirmar). No es la de las pantallas de
     * la carga (MN-04), que se esconden justo cuando todo está publicado.
     *
     * @return Closure(Actor): ?string
     */
    public static function menuCondition(): Closure
    {
        return self::$menuCondition ??= TermAvailability::of(static fn (Term $term): ?string => app(self::class)->whyUnavailable($term));
    }

    public function whyUnavailable(Term $term): ?string
    {
        return AcademicLoad::query()->where('term_id', $term->getKey())->where('current_version', '>', 0)->exists()
            ? null
            : "No hay cargas publicadas en {$term->name}";
    }

    /**
     * Las carreras cuyo diff ve quien no ve la carga (RA-07): las de su alcance donde ve la propuesta en la modalidad, por
     * clave. Vacío si no coordina ninguna ahí.
     *
     * @return list<string>
     */
    public function careerIdsFor(Actor $context, string $modalityId): array
    {
        return Career::query()->orderBy('code')->get(['id', 'code'])
            ->filter(fn (Career $career): bool => $context->allows(AcademicLoadPermission::ProposalView, Target::of(modality: $modalityId, career: $career->getKey())))
            ->modelKeys();
    }

    /**
     * Las claves de unas carreras, en orden: «Solo ISIC» en el encabezado del coordinador.
     *
     * @param  list<string>  $careerIds
     * @return list<string>
     */
    public function careerCodes(array $careerIds): array
    {
        return Career::withTrashed()->whereIn('id', $careerIds)->orderBy('code')->pluck('code')->all();
    }

    /**
     * Qué ve el usuario de una carga: null si la ve completa (`view` de AcademicLoadPolicy); si no, las carreras de su
     * alcance (RA-07), que pueden ser ninguna.
     *
     * @return list<string>|null
     */
    public function careerScopeOf(Actor $context, AcademicLoad $load): ?array
    {
        $user = $context->user();

        if ($user !== null && Gate::forUser($user)->allows('view', $load)) {
            return null;
        }

        return $this->careerIdsFor($context, $load->modality_id);
    }

    /**
     * Las cargas publicadas alguna vez del periodo y la modalidad que el usuario abre aquí, por clave del departamento, con
     * su departamento, modalidad y periodo: con `$careerIds` null, las de los departamentos donde ve la carga; con
     * carreras, las de los departamentos que imparten materias de sus retículas vigentes.
     *
     * @param  list<string>|null  $careerIds
     * @return Collection<int, AcademicLoad>
     */
    public function loadsFor(Actor $context, string $termId, string $modalityId, ?array $careerIds): Collection
    {
        $query = AcademicLoad::query()
            ->join('departments', 'departments.id', '=', 'academic_loads.department_id')
            ->where('academic_loads.term_id', $termId)
            ->where('academic_loads.modality_id', $modalityId)
            ->where('academic_loads.current_version', '>', 0)
            ->select('academic_loads.*')
            ->orderBy('departments.code')
            ->with(['department' => fn ($department) => $department->withTrashed(), 'modality', 'term']);

        if ($careerIds === null) {
            return $context->constrain($query, AcademicLoadPermission::LoadView, [
                'modality' => 'academic_loads.modality_id',
                'unit' => 'academic_loads.department_id',
            ])->get();
        }

        return $query->whereIn('academic_loads.department_id', fn (QueryBuilder $subjects) => $subjects
            ->select('subjects.department_id')
            ->from('subjects')
            ->join('study_plan_subjects', 'study_plan_subjects.subject_id', '=', 'subjects.id')
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->where('study_plans.is_current', true)
            ->whereNull('study_plans.deleted_at')
            ->whereIn('study_plans.career_id', $careerIds))
            ->get();
    }

    /**
     * Un departamento por un id del cliente, para decir que no tiene carga publicada; null si no existe.
     */
    public function department(string $id): ?Department
    {
        /** @var Department|null */
        return KeyLookup::find(Department::query()->withTrashed(), $id);
    }

    /**
     * Si el usuario ve la carga de un departamento en la modalidad aunque no tenga una publicada.
     */
    public function seesDepartment(Actor $context, string $departmentId, string $modalityId): bool
    {
        return $context->allows(AcademicLoadPermission::LoadView, Target::of(modality: $modalityId, unit: $departmentId));
    }

    /**
     * Una carga por su id (el de la página, bloqueado), con su departamento, modalidad y periodo; 404 si no existe.
     */
    public function findOrFail(string $id): AcademicLoad
    {
        /** @var AcademicLoad */
        return KeyLookup::findOrFail(AcademicLoad::query()->with(['department' => fn ($department) => $department->withTrashed(), 'modality', 'term']), $id);
    }
}
