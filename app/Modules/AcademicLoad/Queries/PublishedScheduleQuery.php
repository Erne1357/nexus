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
 * Los payloads de un campus y periodo se guardan en caché con una llave que lleva los ids de las versiones que lo
 * forman: una versión nueva cambia la llave, así que nunca hay que invalidar, y cada consulta cuesta una lectura de
 * ids. Se guarda solo lo que se puede mostrar (PublishedSchedule::withoutPrivate: sin RFC, justificación ni quién
 * publicó), en arreglos y no objetos: la caché no deserializa clases (`cache.serializable_classes`), y así un cambio
 * de forma de PublishedSchedule no deja entradas viejas. Sin estado propio, para Octane.
 */
final class PublishedScheduleQuery
{
    /**
     * La última versión de cada carga del campus y periodo, junta (PublishedSchedule).
     */
    public function schedule(Term $term, Campus $campus): PublishedSchedule
    {
        $ids = $this->latestIds($term, $campus);

        if ($ids === []) {
            return PublishedSchedule::fromPayloads([]);
        }

        $key = 'published-schedule:v1:'.$term->getKey().':'.$campus->getKey().':'.$this->hashOf($ids);

        return PublishedSchedule::fromPayloads(Cache::remember($key, now()->addDays(7), fn (): array => LoadSnapshot::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->pluck('payload')
            ->map(PublishedSchedule::withoutPrivate(...))
            ->all()));
    }

    /**
     * La huella de lo publicado del campus y periodo: cambia con cada versión nueva de cualquiera de sus cargas. Los
     * exportes la llevan en su llave (PublishedExport::key()); null si no hay nada publicado.
     */
    public function version(Term $term, Campus $campus): ?string
    {
        $ids = $this->latestIds($term, $campus);

        return $ids === [] ? null : $this->hashOf($ids);
    }

    /**
     * Los grupos publicados de un docente en todos los campus del periodo («Mi carga» cruza campus), por materia y
     * grupo. Arma lo publicado completo de cada campus y se queda con lo del docente: con dos campus cuesta poco, y lo
     * completo casi siempre ya está en caché; si crecen los campus, conviene una lectura por docente.
     *
     * @return list<PublishedGroup>
     */
    public function forTeacher(Term $term, string $teacherId): array
    {
        $campuses = Campus::query()
            ->whereIn('id', $this->publishedLoads()->select('campus_id')->where('term_id', $term->getKey()))
            ->get();

        $groups = $campuses->flatMap(fn (Campus $campus): array => $this->schedule($term, $campus)->teacher($teacherId))->all();
        usort($groups, PublishedGroup::compare(...));

        return $groups;
    }

    /**
     * Las cargas del campus y periodo que ya salieron de borrador (el departamento aceptó partes, así que tienen
     * grupos) y todavía no tienen una versión publicada, por departamento: solo para el personal con sesión (P7). Una
     * carga en borrador no tiene grupos y no cuenta (P9).
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
            ->whereNotIn('id', LoadSnapshot::query()->select('academic_load_id'))
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
        $published = $this->publishedLoads()->distinct()->get(['term_id', 'campus_id']);
        $terms = Term::query()->whereIn('id', $published->pluck('term_id'))->orderByDesc('starts_on')->get();
        $campuses = Campus::query()->where('is_active', true)->whereIn('id', $published->pluck('campus_id'))->orderBy('code')->get();

        $contexts = $terms->map(fn (Term $term): array => [
            'term' => $term,
            'campuses' => $campuses->filter(fn (Campus $campus): bool => $published->contains(fn (AcademicLoad $load): bool => $load->term_id === $term->getKey() && $load->campus_id === $campus->getKey()))->values()->all(),
        ])->filter(fn (array $context): bool => $context['campuses'] !== [])->values()->all();

        return $contexts;
    }

    /**
     * @return list<string> los ids de la última versión de cada carga del campus y periodo, en orden
     */
    private function latestIds(Term $term, Campus $campus): array
    {
        return LoadSnapshot::query()
            ->latestVersion()
            ->whereIn('academic_load_id', AcademicLoad::query()->select('id')->where('term_id', $term->getKey())->where('campus_id', $campus->getKey()))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<string>  $ids
     */
    private function hashOf(array $ids): string
    {
        return hash('xxh128', implode(',', $ids));
    }

    /**
     * Las cargas con al menos una versión publicada (snapshot), la misma fuente que schedule().
     *
     * @return Builder<AcademicLoad>
     */
    private function publishedLoads(): Builder
    {
        return AcademicLoad::query()->whereIn('id', LoadSnapshot::query()->select('academic_load_id'));
    }
}
