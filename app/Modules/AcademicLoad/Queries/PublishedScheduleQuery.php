<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Lecturas de lo publicado (tarea 4.4): la consulta pública, los exportes (4.5) y lo que ve el docente con su carga
 * publicada (P8, Staffing). Lee solo `load_snapshots`, nunca las tablas vivas: de cada carga, su versión más reciente,
 * aunque la carga esté reabierta (P3: mientras se corrige, el público y los docentes siguen viendo la última versión;
 * decisiones del 10 de noviembre de 2026). La carga es por departamento y modalidad (M1), así que lo publicado se junta
 * por modalidad y periodo, con el campus de cada grupo; el campus es un filtro (PublishedSchedule::onlyCampus()).
 *
 * Los payloads de una modalidad y periodo se guardan en caché con una llave que lleva los ids de las versiones que lo
 * forman: una versión nueva cambia la llave, así que nunca hay que invalidar, y cada consulta cuesta una lectura de
 * ids. Cada payload se normaliza antes de guardarse (SnapshotPayload::normalize: las fotos v1 llevan el campus en la
 * raíz, MS13) y se guarda solo lo que se puede mostrar (PublishedSchedule::withoutPrivate: sin RFC, justificación ni
 * quién publicó), en arreglos y no objetos: la caché no deserializa clases (`cache.serializable_classes`), y así un
 * cambio de forma de PublishedSchedule no deja entradas viejas. Sin estado propio, para Octane.
 */
final class PublishedScheduleQuery
{
    /**
     * La última versión de cada carga de la modalidad y periodo, junta (PublishedSchedule), de todos los campus.
     */
    public function schedule(Term $term, Modality $modality): PublishedSchedule
    {
        return $this->scheduleOf($term, $modality, $this->snapshotIds($term, $modality));
    }

    /**
     * Lo publicado formado por esas versiones exactas (las de snapshotIds()), aunque después se publique otra: un
     * exporte arma su archivo con las versiones de su llave, no con lo que haya cuando corre la cola.
     *
     * @param  list<string>  $ids
     */
    public function scheduleOf(Term $term, Modality $modality, array $ids): PublishedSchedule
    {
        if ($ids === []) {
            return PublishedSchedule::fromPayloads([]);
        }

        $key = 'published-schedule:v2:'.$term->getKey().':'.$modality->getKey().':'.$this->hashOf($ids);

        return PublishedSchedule::fromPayloads(Cache::remember($key, now()->addDays(7), fn (): array => LoadSnapshot::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->pluck('payload')
            ->map(fn (array $payload): array => PublishedSchedule::withoutPrivate(SnapshotPayload::normalize($payload)))
            ->all()));
    }

    /**
     * La huella de lo publicado de la modalidad y periodo: cambia con cada versión nueva de cualquiera de sus cargas. Los
     * exportes la llevan en su llave (PublishedExport::key()); null si no hay nada publicado.
     */
    public function version(Term $term, Modality $modality): ?string
    {
        $ids = $this->snapshotIds($term, $modality);

        return $ids === [] ? null : $this->hashOf($ids);
    }

    /**
     * Los grupos publicados de un docente en todas las modalidades del periodo, por materia y grupo. Arma lo publicado
     * completo de cada modalidad y se queda con lo del docente: con pocas modalidades cuesta poco, y lo completo casi
     * siempre ya está en caché.
     *
     * @return list<PublishedGroup>
     */
    public function forTeacher(Term $term, string $teacherId): array
    {
        $modalities = Modality::withTrashed()
            ->whereIn('id', $this->publishedLoads()->select('modality_id')->where('term_id', $term->getKey()))
            ->get();

        $groups = $modalities->flatMap(fn (Modality $modality): array => $this->schedule($term, $modality)->teacher($teacherId))->all();
        usort($groups, PublishedGroup::compare(...));

        return $groups;
    }

    /**
     * Las cargas de la modalidad y periodo que ya salieron de borrador (el departamento aceptó partes, así que tienen
     * grupos) y todavía no tienen una versión publicada, por departamento: solo para el personal con sesión (P7). Una
     * carga en borrador no tiene grupos y no cuenta (P9).
     *
     * @return list<AcademicLoad>
     */
    public function unpublished(Term $term, Modality $modality): array
    {
        return AcademicLoad::query()
            ->with('department')
            ->where('term_id', $term->getKey())
            ->where('modality_id', $modality->getKey())
            ->where('status', '!=', LoadStatus::Draft)
            ->whereNotIn('id', LoadSnapshot::query()->select('academic_load_id'))
            ->get()
            ->sortBy(fn (AcademicLoad $load): string => $load->department->code)
            ->values()
            ->all();
    }

    /**
     * Los periodos con algo publicado, del más reciente al más viejo, cada uno con las modalidades activas que
     * publicaron, por clave: lo que ofrece la consulta pública.
     *
     * @return list<array{term: Term, modalities: list<Modality>}>
     */
    public function contexts(): array
    {
        $published = $this->publishedLoads()->distinct()->get(['term_id', 'modality_id']);
        $terms = Term::query()->whereIn('id', $published->pluck('term_id'))->orderByDesc('starts_on')->get();
        $modalities = Modality::query()->where('is_active', true)->whereIn('id', $published->pluck('modality_id'))->orderBy('code')->get();

        return $terms->map(fn (Term $term): array => [
            'term' => $term,
            'modalities' => $modalities->filter(fn (Modality $modality): bool => $published->contains(fn (AcademicLoad $load): bool => $load->term_id === $term->getKey() && $load->modality_id === $modality->getKey()))->values()->all(),
        ])->filter(fn (array $context): bool => $context['modalities'] !== [])->values()->all();
    }

    /**
     * Los ids de la última versión de cada carga de la modalidad y periodo, en orden: lo que hoy está publicado.
     *
     * @return list<string>
     */
    public function snapshotIds(Term $term, Modality $modality): array
    {
        return LoadSnapshot::query()
            ->latestVersion()
            ->whereIn('academic_load_id', AcademicLoad::query()->select('id')->where('term_id', $term->getKey())->where('modality_id', $modality->getKey()))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Los departamentos de esas versiones, sin leer sus payloads: lo que necesitan los exportes del personal para armar
     * su llave y su permiso en cada recarga de la página de espera. Por clave del departamento.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, code: string, name: string, snapshot_id: string}> por id de departamento
     */
    public function departmentsOf(array $ids): array
    {
        return LoadSnapshot::query()
            ->whereIn('load_snapshots.id', $ids)
            ->join('academic_loads', 'academic_loads.id', '=', 'load_snapshots.academic_load_id')
            ->join('departments', 'departments.id', '=', 'academic_loads.department_id')
            ->orderBy('departments.code')
            ->get(['departments.id', 'departments.code', 'departments.name', 'load_snapshots.id as snapshot_id'])
            ->mapWithKeys(fn (LoadSnapshot $row): array => [(string) $row->getAttribute('id') => [
                'id' => (string) $row->getAttribute('id'),
                'code' => (string) $row->getAttribute('code'),
                'name' => (string) $row->getAttribute('name'),
                'snapshot_id' => (string) $row->getAttribute('snapshot_id'),
            ]])
            ->all();
    }

    /**
     * @param  list<string>  $ids
     */
    public function hashOf(array $ids): string
    {
        return hash('xxh128', implode(',', $ids));
    }

    /**
     * Las modalidades activas con grupos publicados en ese campus (por clave) en el periodo, por clave: de lo publicado,
     * no del campus, que es solo el lugar (MS3). Para llevar una liga vieja por campus a su modalidad.
     *
     * @return list<Modality>
     */
    public function modalitiesWithGroupsIn(Term $term, string $campusCode): array
    {
        return Modality::query()
            ->where('is_active', true)
            ->whereIn('id', $this->publishedLoads()->select('modality_id')->where('term_id', $term->getKey()))
            ->orderBy('code')
            ->get()
            ->filter(fn (Modality $modality): bool => ! $this->schedule($term, $modality)->onlyCampus($campusCode)->isEmpty())
            ->values()
            ->all();
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
