<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
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
 *
 * Con los payloads va lo que cambió la última versión de cada carga contra la anterior (tarea 8.5): el diff guardado al
 * publicarla (`load_snapshots.diff`, SnapshotDiff), leído en la misma consulta que los payloads, convertido a las marcas
 * públicas (PublishedChange) y, si canceló grupos, esos grupos de la versión anterior, en una consulta más. Una versión
 * publicada antes de la tarea 8.4 no guardó su diff: se calcula con las dos fotos, como el tablero de versiones. Todo
 * entra a la misma llave de caché, así que una consulta servida de la caché no cuesta nada más.
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

        $key = 'published-schedule:v3:'.$term->getKey().':'.$modality->getKey().':'.$this->hashOf($ids);
        $published = Cache::remember($key, now()->addDays(7), fn (): array => $this->published($ids));

        return PublishedSchedule::fromPayloads($published['payloads'], $published['changes']);
    }

    /**
     * Lo que se guarda en caché de esas versiones: sus payloads normalizados y sin lo privado, y por carga, lo que cambió
     * contra su versión anterior (las marcas de PublishedChange y los grupos cancelados). Dos consultas como mucho.
     *
     * @param  list<string>  $ids
     * @return array{payloads: list<array<string, mixed>>, changes: array<string, array{groups: array<string, array{kinds: list<string>, line: string, detail: string|null}>, cancelled: list<array<string, mixed>>}>}
     */
    private function published(array $ids): array
    {
        $snapshots = LoadSnapshot::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'academic_load_id', 'version', 'payload', 'diff']);
        $previous = $this->previousPayloads($snapshots->filter(fn (LoadSnapshot $snapshot): bool => $snapshot->version > 1 && ($snapshot->diff === null || $snapshot->diff['groups']['cancelled'] !== []))->all());
        $payloads = [];
        $changes = [];

        foreach ($snapshots as $snapshot) {
            $payload = SnapshotPayload::normalize($snapshot->payload);
            $before = $previous[$snapshot->academic_load_id] ?? null;
            $diff = $snapshot->diff ?? ($snapshot->version > 1 && $before !== null ? SnapshotDiff::between($before, $payload) : null);
            $payloads[] = PublishedSchedule::withoutPrivate($payload);

            if ($diff === null) {
                continue;
            }

            $cancelled = array_column($diff['groups']['cancelled'], 'id');
            $changes[$payload['load_id']] = [
                'groups' => array_map(fn (PublishedChange $change): array => $change->toArray(), PublishedChange::ofDiff($diff)),
                'cancelled' => array_values(array_map(PublishedSchedule::withoutPrivateGroup(...), array_filter($before['groups'] ?? [], fn (array $group): bool => in_array($group['id'], $cancelled, true)))),
            ];
        }

        return ['payloads' => $payloads, 'changes' => $changes];
    }

    /**
     * La versión anterior de cada una de esas versiones, normalizada, por id de carga, en una sola consulta.
     *
     * @param  array<int, LoadSnapshot>  $snapshots
     * @return array<string, array<string, mixed>>
     */
    private function previousPayloads(array $snapshots): array
    {
        if ($snapshots === []) {
            return [];
        }

        return LoadSnapshot::query()
            ->where(function (Builder $query) use ($snapshots): void {
                foreach ($snapshots as $snapshot) {
                    $query->orWhere(fn (Builder $previous): Builder => $previous->where('academic_load_id', $snapshot->academic_load_id)->where('version', $snapshot->version - 1));
                }
            })
            ->get(['academic_load_id', 'payload'])
            ->mapWithKeys(fn (LoadSnapshot $snapshot): array => [$snapshot->academic_load_id => SnapshotPayload::normalize($snapshot->payload)])
            ->all();
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
