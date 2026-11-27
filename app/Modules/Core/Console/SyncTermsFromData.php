<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Actions\CreateTerm;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\TermCodesInDataQuery;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Console\Command;

/**
 * Da de alta, con CreateTerm (bitácora), todos los periodos válidos que aparecen en los datos y que no existen
 * (TP-01, docs/dominio/reglas-de-negocio.md). Idempotente: los existentes no se tocan. Con `--dry-run` solo cuenta.
 *
 *     docker compose exec app php artisan core:sync-terms-from-data --dry-run
 */
final class SyncTermsFromData extends Command
{
    protected $signature = 'core:sync-terms-from-data {--dry-run : Solo cuenta lo que crearía, sin escribir}';

    protected $description = 'Da de alta los periodos que aparecen en los datos y no existen';

    private const array TYPES = ['1' => 'Ene-Jun', '2' => 'Verano', '3' => 'Ago-Dic'];

    public function handle(TermCodesInDataQuery $codes, CreateTerm $create, TermRules $rules): int
    {
        $found = $codes->get();
        $existing = Term::query()->pluck('code')->flip();
        $missing = array_values(array_filter($found['valid'], fn (string $code): bool => ! $existing->has($code)));
        $dry = (bool) $this->option('dry-run');

        $byType = array_fill_keys(array_keys(self::TYPES), 0);

        foreach ($missing as $code) {
            if (! $dry) {
                [$startsOn, $endsOn] = $rules->defaultDates($code);
                $create->handle(['code' => $code, 'name' => $rules->suggestedName($code), 'starts_on' => $startsOn, 'ends_on' => $endsOn]);
            }

            $byType[$code[4]]++;
        }

        $this->line(sprintf('%s: %d periodos válidos en los datos, %d ya existen.', $dry ? 'Simulación' : 'Listo', count($found['valid']), count($found['valid']) - count($missing)));

        foreach (self::TYPES as $suffix => $label) {
            $this->line(sprintf('  %s %s: %d', $dry ? 'Crearía' : 'Creados', $label, $byType[$suffix]));
        }

        $this->line(sprintf('Descartados como basura: %d valores distintos.', $found['junk']));

        return self::SUCCESS;
    }
}
