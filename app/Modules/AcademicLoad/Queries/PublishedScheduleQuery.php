<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Lecturas de lo publicado (tarea 4.4): la consulta pública, los exportes (4.5) y lo que ve el docente con su carga
 * publicada (P8, Staffing). Lee solo `load_snapshots`, nunca las tablas vivas: de cada carga, su versión más reciente,
 * aunque la carga esté reabierta (P3: mientras se corrige, el público y los docentes siguen viendo la última versión;
 * decisiones del 10 de noviembre de 2026).
 *
 * Lo publicado de un campus y periodo se guarda en caché con una llave que lleva los ids de las versiones que lo
 * forman: una versión nueva cambia la llave, así que nunca hay que invalidar, y cada consulta cuesta una lectura de
 * ids. Sin estado propio, para Octane.
 */
final class PublishedScheduleQuery
{
    /**
     * La última versión de cada carga del campus y periodo, junta (PublishedSchedule).
     */
    public function schedule(Term $term, Campus $campus): PublishedSchedule
    {
        $ids = $this->latestSnapshots()
            ->whereIn('academic_load_id', AcademicLoad::query()->select('id')->where('term_id', $term->getKey())->where('campus_id', $campus->getKey()))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return PublishedSchedule::fromPayloads([]);
        }

        $key = 'published-schedule:'.$term->getKey().':'.$campus->getKey().':'.hash('xxh128', implode(',', $ids));

        return Cache::remember($key, now()->addDays(7), fn (): PublishedSchedule => PublishedSchedule::fromPayloads(
            LoadSnapshot::query()->whereIn('id', $ids)->orderBy('id')->pluck('payload')->all(),
        ));
    }

    /**
     * Los grupos publicados de un docente en todos los campus del periodo («Mi carga» cruza campus), por materia y
     * grupo.
     *
     * @return list<PublishedGroup>
     */
    public function forTeacher(Term $term, string $teacherId): array
    {
        $campuses = Campus::query()
            ->whereIn('id', AcademicLoad::query()->select('campus_id')->where('term_id', $term->getKey())->where('current_version', '>', 0))
            ->get();

        $groups = $campuses->flatMap(fn (Campus $campus): array => $this->schedule($term, $campus)->teacher($teacherId))->all();
        usort($groups, fn (PublishedGroup $a, PublishedGroup $b): int => strcmp($a->subject['code'], $b->subject['code']) ?: strnatcmp($a->code, $b->code));

        return $groups;
    }

    /**
     * Las cargas del campus y periodo que tienen grupos y todavía no tienen una versión publicada, por departamento:
     * solo para el personal con sesión (P7). Una carga en borrador no tiene grupos y no cuenta (P9).
     *
     * @return list<AcademicLoad>
     */
    public function unpublished(Term $term, Campus $campus): array
    {
        return AcademicLoad::query()
            ->with('department')
            ->where('term_id', $term->getKey())
            ->where('campus_id', $campus->getKey())
            ->where('status', '!=', LoadStatus::Draft)
            ->where('current_version', 0)
            ->get()
            ->sortBy(fn (AcademicLoad $load): string => $load->department->code)
            ->values()
            ->all();
    }

    /**
     * Los periodos con algo publicado, del más reciente al más viejo, cada uno con los campus que publicaron: lo que
     * ofrece el selector de la consulta pública.
     *
     * @return list<array{term: Term, campuses: list<Campus>}>
     */
    public function contexts(): array
    {
        $published = AcademicLoad::query()->where('current_version', '>', 0)->distinct()->get(['term_id', 'campus_id']);
        $terms = Term::query()->whereIn('id', $published->pluck('term_id'))->orderByDesc('code')->get();
        $campuses = Campus::query()->whereIn('id', $published->pluck('campus_id'))->orderBy('code')->get();

        return $terms->map(fn (Term $term): array => [
            'term' => $term,
            'campuses' => $campuses->filter(fn (Campus $campus): bool => $published->contains(fn (AcademicLoad $load): bool => $load->term_id === $term->getKey() && $load->campus_id === $campus->getKey()))->values()->all(),
        ])->all();
    }

    /**
     * @return Builder<LoadSnapshot>
     */
    private function latestSnapshots(): Builder
    {
        return LoadSnapshot::query()->latestVersion();
    }
}
