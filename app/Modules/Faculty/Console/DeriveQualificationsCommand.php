<?php

namespace App\Modules\Faculty\Console;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Actions\DeriveQualificationsFromHistory;
use Illuminate\Console\Command;

/**
 * Deriva el perfil «Apto» de los docentes desde el historial de los últimos 6 semestres (D-02). Sin `--term` usa el
 * periodo que contiene hoy y, si no hay, el último que terminó.
 */
final class DeriveQualificationsCommand extends Command
{
    protected $signature = 'faculty:derive-qualifications {--term= : Clave del periodo, p. ej. 20271}';

    protected $description = 'Deriva el perfil Apto de los docentes del historial de los últimos 6 semestres (D-02)';

    public function handle(DeriveQualificationsFromHistory $derive): int
    {
        $code = $this->option('term');

        if (blank($code)) {
            $term = Term::query()->where('starts_on', '<=', today())->where('ends_on', '>=', today())->first()
                ?? Term::query()->where('ends_on', '<', today())->orderByDesc('ends_on')->first();

            if ($term === null) {
                $this->error('No hay un periodo que contenga hoy ni uno que ya terminó: usa --term.');

                return self::FAILURE;
            }

            $code = $term->code;
        }

        $result = $derive->handle((string) $code);

        $this->info("Periodo {$code}: {$result['created']} creadas, {$result['removed']} quitadas, {$result['kept_manual']} manuales intactas.");

        return self::SUCCESS;
    }
}
