<?php

namespace App\Modules\AcademicLoad\Database\Seeders;

use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo de la publicación (tarea 4.3): publica con `PublishLoad` cada carga cerrada del periodo en los campus de la demo,
 * como lo haría el jefe del departamento (`jefe.<clave>` de DemoStaffSeeder; el admin si no lo hay). Lleva al punto 9
 * `reanalisis` («Empezando el re-análisis»): toda carga con grupos queda publicada con su versión 1. Las cargas sin
 * grupos siguen en borrador y no se publican (P9, confirmado el 10 de noviembre de 2026). Pasa por los vetos reales
 * (HC-15 al publicar): si uno veta, la demo se detiene con su motivo.
 */
final class PublicationDemoSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * @param  list<string>  $campuses
     */
    public function run(array $campuses = ['C2'], string $term = '20271'): void
    {
        $this->refuseProduction();

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe.");
        $admin = User::query()->where('username', 'admin')->firstOrFail();

        $loads = AcademicLoad::query()
            ->with(['department', 'modality'])
            ->where('term_id', $termModel->id)
            ->whereIn('modality_id', Campus::query()->whereIn('code', $campuses)->select('modality_id'))
            ->where('status', LoadStatus::Closed->value)
            ->get()
            ->sortBy(fn (AcademicLoad $load): string => $load->modality->code.$load->department->code);

        foreach ($loads as $load) {
            $publisher = User::query()->where('username', 'jefe.'.Str::lower($load->department->code))->first() ?? $admin;
            app(PublishLoad::class)->handle($load, $publisher, 'Primera publicación');
        }

        $this->command?->info("Publicadas {$loads->count()} cargas.");
    }
}
