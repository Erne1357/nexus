<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Imports\Sii\PlazaKeys;
use App\Modules\Imports\Sii\TeacherRoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Llena la clave de categoría (`teacher_positions.category`, PL-01) de las plazas ya importadas que no la tienen, con
 * la de `sii_archivo.docentes2.claves_plazas` del mismo docente y tipo de plaza (PlazaKeys; la primera si hay varias).
 * Solo llena nulos: nunca pisa una categoría, así que correrla dos veces no cambia nada. Con `$dryRun` solo cuenta.
 * Cada plaza que cambia deja bitácora. Los datos salen de la base, no del código ni de un archivo del repositorio.
 */
final class FillPositionCategories
{
    public const string TABLE = 'sii_archivo.docentes2';

    /**
     * @return array{filled: int, already: int, unmatched_rows: int, no_category: int}
     *                                                                                 filled: plazas a las que se les puso (o se les pondría) categoría; already: plazas que ya la traían;
     *                                                                                 unmatched_rows: renglones de docentes2 sin docente en Nexus con ese RFC; no_category: plazas de Nexus sin clave en el SII.
     */
    public function handle(bool $dryRun = false): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new \RuntimeException('No existe '.self::TABLE.': corre antes imports:migrate-sii.');
        }

        $teachers = Teacher::query()->pluck('id', 'rfc');
        /** @var array<string, array<string, string>> $categories categoría por id de docente y tipo de plaza */
        $categories = [];
        $unmatched = 0;

        foreach (DB::table(self::TABLE)->orderBy('_linea')->get(['rfc', 'claves_plazas']) as $row) {
            $teacherId = $teachers[TeacherRoster::normalizeRfc((string) $row->rfc)] ?? null;

            if ($teacherId === null) {
                $unmatched++;

                continue;
            }

            foreach (PlazaKeys::parse((string) $row->claves_plazas) as $plaza) {
                if ($plaza['kind'] !== null) {
                    $categories[$teacherId][$plaza['kind']->value] ??= $plaza['category'];
                }
            }
        }

        $filled = 0;
        $already = 0;
        $none = 0;

        $write = function () use ($categories, $dryRun, &$filled, &$already, &$none): void {
            foreach (TeacherPosition::query()->whereIn('teacher_id', array_keys($categories))->orderBy('id')->get() as $position) {
                if ($position->category !== null) {
                    $already++;

                    continue;
                }

                $category = $categories[$position->teacher_id][$position->kind->value] ?? null;

                if ($category === null) {
                    $none++;

                    continue;
                }

                $filled++;

                if ($dryRun) {
                    continue;
                }

                $position->update(['category' => $category]);

                activity()
                    ->performedOn($position)
                    ->event(ActivityEvent::Updated)
                    ->withChanges(['attributes' => ['category' => $category], 'old' => ['category' => null]])
                    ->withProperties(['teacher_id' => $position->teacher_id])
                    ->log('Llenó la categoría de la plaza desde la exportación del SII (PL-01)');
            }
        };

        $dryRun ? $write() : DB::transaction($write);

        return ['filled' => $filled, 'already' => $already, 'unmatched_rows' => $unmatched, 'no_category' => $none];
    }
}
