<?php

namespace App\Modules\Demand\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Models\DemandForecast;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * La proyección guardada de un periodo en una modalidad (tarea 5.1), para quien está a la derecha de Demand: la
 * propuesta del coordinador, por carrera (5.5 y 5.6), y el tablero del departamento, por sus materias en todas las
 * carreras y campus (5.7), y su lente de carrera, por las materias de una carrera (forSubjects()). Solo lee
 * `demand_forecasts`; quien la autoriza es la pantalla que la usa.
 */
final class DemandForecastsQuery
{
    /**
     * @return Collection<string, Collection<string, DemandForecast>> id de materia => (id de campus, o '' sin campus) => fila
     */
    public function forCareer(Term $term, Modality $modality, Career $career): Collection
    {
        return DemandForecast::query()
            ->where('term_id', $term->id)
            ->where('modality_id', $modality->id)
            ->where('career_id', $career->id)
            ->orderBy('subject_id')
            ->orderBy('campus_id')
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $rows): Collection => $rows->keyBy(fn (DemandForecast $forecast): string => $forecast->campus_id ?? ''));
    }

    /**
     * Las filas de las materias del departamento, de todas las carreras y campus, con la carrera y el campus cargados.
     *
     * @return Collection<string, Collection<int, DemandForecast>> id de materia => filas
     */
    public function forDepartment(Term $term, Modality $modality, Department $department): Collection
    {
        return $this->bySubject($term, $modality, fn (Builder $query) => $query->where('subjects.department_id', $department->id));
    }

    /**
     * Las filas de esas materias, de todas las carreras y campus, con la carrera y el campus cargados, por clave de materia:
     * la lente de carrera del tablero (5.7), que muestra las materias de una carrera con lo que piden las demás.
     *
     * @param  list<string>  $subjectIds
     * @return Collection<string, Collection<int, DemandForecast>> id de materia => filas
     */
    public function forSubjects(Term $term, Modality $modality, array $subjectIds): Collection
    {
        if ($subjectIds === []) {
            return new Collection;
        }

        return $this->bySubject($term, $modality, fn (Builder $query) => $query->whereIn('demand_forecasts.subject_id', $subjectIds));
    }

    /**
     * Las filas del periodo y la modalidad de las materias que deja `$subjects`, por clave de materia, carrera y campus,
     * agrupadas por materia.
     *
     * @param  Closure(Builder<DemandForecast>): mixed  $subjects
     * @return Collection<string, Collection<int, DemandForecast>>
     */
    private function bySubject(Term $term, Modality $modality, Closure $subjects): Collection
    {
        return DemandForecast::query()
            ->with(['career', 'campus'])
            ->join('subjects', 'subjects.id', '=', 'demand_forecasts.subject_id')
            ->where('demand_forecasts.term_id', $term->id)
            ->where('demand_forecasts.modality_id', $modality->id)
            ->tap($subjects)
            ->orderBy('subjects.code')
            ->orderBy('demand_forecasts.career_id')
            ->orderBy('demand_forecasts.campus_id')
            ->select('demand_forecasts.*')
            ->get()
            ->groupBy('subject_id');
    }
}
