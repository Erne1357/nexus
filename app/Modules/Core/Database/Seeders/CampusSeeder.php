<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Modality as CatalogModality;
use Illuminate\Database\Seeder;

/**
 * Los cinco campus con su modalidad y su perfil de flujo completo en `settings`. Cada perfil
 * declara solo lo que los documentos dicen distinto al de CampusProfile::DEFAULTS (presencial de
 * PROJECT.md). EAD, ENL y TNMV se siembran inactivos: su perfil se define en la planeación de la
 * fase 10 y por ahora solo llevan lo documentado:
 *
 * - EAD: virtual de lunes a jueves y presencial viernes y sábado (glosario, PROJECT.md).
 * - TNMV: docentes del TecNM, es decir, externos al ITCJ (glosario, PROJECT.md).
 * - ENL: nada documentado más allá de su modalidad.
 * - C1: los jefes corrigen sus salones al validar los catálogos (CV2, confirmado el 2 de noviembre de 2026).
 *   En los demás campus no, mientras se confirma (CV-S5, supuesto por confirmar).
 *
 * Crea los campus que faltan (también entre los borrados). A uno existente solo le agrega las claves del
 * perfil que no tiene en `settings`: volver a sembrar no pisa un perfil ya editado.
 *
 * Cada campus queda ligado a su modalidad del catálogo por el puente `modality_id` (ADR-0025; se quita al contraer),
 * al crearlo y también si ya existía sin él. El catálogo lo siembra su migración.
 */
class CampusSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, modality: Modality, is_active: bool, profile: array<string, mixed>}>
     */
    private const array CAMPUSES = [
        ['code' => 'C1', 'name' => 'Campus I', 'modality' => Modality::Presencial, 'is_active' => true, 'profile' => ['heads_edit_rooms' => true]],
        ['code' => 'C2', 'name' => 'Campus II', 'modality' => Modality::Presencial, 'is_active' => true, 'profile' => []],
        ['code' => 'EAD', 'name' => 'EAD', 'modality' => Modality::Mixta, 'is_active' => false, 'profile' => ['days' => [1, 2, 3, 4, 5, 6]]],
        ['code' => 'ENL', 'name' => 'EnLínea', 'modality' => Modality::EnLinea, 'is_active' => false, 'profile' => []],
        ['code' => 'TNMV', 'name' => 'TecNM Virtual', 'modality' => Modality::VirtualTecnm, 'is_active' => false, 'profile' => ['allow_external_teachers' => true]],
    ];

    public function run(): void
    {
        foreach (self::CAMPUSES as $campus) {
            $profile = CampusProfile::fromArray($campus['profile'])->toArray();

            $modalityId = CatalogModality::withTrashed()->where('code', $campus['modality']->catalogCode())->value('id');

            $record = Campus::withTrashed()->firstOrCreate(['code' => $campus['code']], [
                'name' => $campus['name'],
                'modality' => $campus['modality'],
                'modality_id' => $modalityId,
                'settings' => $profile,
                'is_active' => $campus['is_active'],
            ]);

            if (! $record->wasRecentlyCreated) {
                $missing = array_diff_key($profile, $record->settings ?? []);

                if ($missing !== []) {
                    $record->forceFill(['settings' => [...($record->settings ?? []), ...$missing]]);
                }

                if ($record->modality_id === null) {
                    $record->forceFill(['modality_id' => CatalogModality::withTrashed()->where('code', $record->modality->catalogCode())->value('id')]);
                }

                $record->save();
            }
        }
    }
}
