<?php

namespace App\Modules\Core\Demo;

use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * Lo que recibe cada paso y cada revisión de la demo por puntos: el volumen, sus campus, el periodo que se arma
 * (`20271`), la semilla (la misma semilla da los mismos datos) y la consola para imprimir avance.
 */
final readonly class DemoContext
{
    /**
     * @param  list<string>  $campuses  códigos de campus
     */
    public function __construct(
        public DemoVolume $volume,
        public array $campuses,
        public string $termCode = '20271',
        public int $seed = 2027,
        public ?Command $command = null,
    ) {}

    public static function for(DemoVolume $volume, int $seed = 2027, ?Command $command = null): self
    {
        return new self($volume, $volume->campuses(), seed: $seed, command: $command);
    }

    public function term(): Term
    {
        return Term::query()->where('code', $this->termCode)->firstOrFail();
    }

    public function admin(): User
    {
        return User::query()->where('username', 'admin')->firstOrFail();
    }

    public function info(string $line): void
    {
        $this->command?->info($line);
    }

    /**
     * Corre un seeder con sus parámetros, como `Seeder::callWith()`: con contenedor y, si hay, con la consola.
     *
     * @param  class-string<Seeder>  $seederClass
     * @param  array<string, mixed>  $parameters
     */
    public function runSeeder(string $seederClass, array $parameters = []): void
    {
        $seeder = app($seederClass)->setContainer(app());

        if ($this->command !== null) {
            $seeder->setCommand($this->command);
        }

        $seeder->__invoke($parameters);
    }

    /**
     * Corre un seeder de la carga una vez por cada modalidad del volumen (DemoVolume::modalities()), con su clave en
     * `modality`: la carga es por departamento y modalidad (M1), y los pasos y las revisiones leen las modalidades del
     * mismo lugar en vez de fiarse de la que cada seeder trae por omisión.
     *
     * @param  class-string<Seeder>  $seederClass
     * @param  array<string, mixed>  $parameters
     */
    public function runSeederPerModality(string $seederClass, array $parameters = []): void
    {
        foreach ($this->volume->modalities() as $modality) {
            $this->runSeeder($seederClass, [...$parameters, 'modality' => $modality]);
        }
    }
}
