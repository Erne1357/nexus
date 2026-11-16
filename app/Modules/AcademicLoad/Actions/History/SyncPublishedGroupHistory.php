<?php

namespace App\Modules\AcademicLoad\Actions\History;

use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lleva a `historical_groups` (`source = nexus`) lo que publicó una carga (tarea 5.6): reemplaza, en una transacción,
 * las filas `nexus` de esa carga (las de cualquiera de sus versiones) con los grupos de su última versión. Una versión
 * nueva reemplaza a la anterior: un grupo que ya no está desaparece del historial.
 *
 * De cada grupo del snapshot (leído normalizado, MS13): materia, código, campus del grupo (nulo si no lo tiene), cupo
 * (`capacity`) y, como carrera exclusiva, la única carrera de sus lugares (nula si comparte entre varias). Un grupo
 * compartido entre carreras es un solo grupo en Nexus, así que no hay `parallel_of`. `enrolled` queda nulo: en R1 la
 * inscripción sigue en el SII. La modalidad es la de la carga. Si dos cargas dieran el mismo (periodo, materia, grupo) la
 * última escritura gana (la llave única; supuesto por confirmar).
 */
final class SyncPublishedGroupHistory
{
    /**
     * @return int cuántos grupos quedaron en el historial para esa carga
     */
    public function handle(AcademicLoad $load): int
    {
        $snapshots = LoadSnapshot::query()->where('academic_load_id', $load->getKey())->orderByDesc('version')->get();
        $latest = $snapshots->first();

        if ($latest === null) {
            return 0;
        }

        $payload = SnapshotPayload::normalize($latest->payload);
        $now = now();

        $rows = array_map(fn (array $group): array => [
            'id' => (string) Str::uuid7(),
            'term_code' => $payload['term']['code'],
            'modality_id' => $load->modality_id,
            'campus_id' => $group['campus']['id'] ?? null,
            'subject_id' => $group['subject']['id'],
            'group_code' => $group['code'],
            'capacity' => $group['capacity'],
            'enrolled' => null,
            'career_id' => $this->careerOf($group),
            'parallel_of' => null,
            'source' => HistoricalGroupSource::Nexus->value,
            'load_snapshot_id' => $latest->getKey(),
            'recorded_at' => $latest->created_at,
            'created_at' => $now,
            'updated_at' => $now,
        ], $payload['groups']);

        DB::transaction(function () use ($snapshots, $rows): void {
            DB::table('historical_groups')->whereIn('load_snapshot_id', $snapshots->modelKeys())->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('historical_groups')->upsert($chunk, ['term_code', 'subject_id', 'group_code', 'source'],
                    ['modality_id', 'campus_id', 'capacity', 'enrolled', 'career_id', 'parallel_of', 'load_snapshot_id', 'recorded_at', 'updated_at']);
            }
        });

        return count($rows);
    }

    /**
     * La carrera exclusiva del grupo: la única de sus lugares, o nula si comparte entre varias.
     *
     * @param  array<string, mixed>  $group
     */
    private function careerOf(array $group): ?string
    {
        $careers = array_values(array_unique(array_column(array_column($group['placements'], 'career'), 'id')));

        return count($careers) === 1 ? $careers[0] : null;
    }
}
