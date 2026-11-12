<?php

namespace App\Modules\Imports\Sii;

use App\Modules\Core\Models\User;
use Closure;
use InvalidArgumentException;

/**
 * El contexto de una corrida: la exportación abierta, los catálogos curados a mano, quién la corre y los mapas que un
 * paso arma una vez y otros reutilizan. Vive lo que dura la corrida; no es un singleton.
 */
final class SiiMigration
{
    /** @var array<string, mixed> */
    private array $remembered = [];

    public function __construct(
        private readonly SiiExport $export,
        private readonly string $curatedDir,
        private readonly bool $dryRun = false,
        private readonly ?User $user = null,
    ) {}

    public function export(): SiiExport
    {
        return $this->export;
    }

    /**
     * Quién corre la migración (`--user`), para lo que un paso deja a su nombre fuera del motor de importación.
     */
    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * Si la corrida es un ensayo: un paso que escribe por su cuenta, sin el motor de importación, no debe escribir.
     */
    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Lee `<carpeta de curados>/<nombre>.csv`: una lista de filas por encabezado.
     *
     * @return list<array<string, string>>
     */
    public function curated(string $name): array
    {
        $path = rtrim($this->curatedDir, '/')."/{$name}.csv";

        if (! is_file($path)) {
            throw new InvalidArgumentException("No existe el catálogo curado {$name}.");
        }

        $stream = fopen($path, 'r');
        $header = fgetcsv($stream, escape: '');
        $rows = [];

        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $rows[] = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
        }

        fclose($stream);

        return $rows;
    }

    /**
     * Arma un valor la primera vez que se pide y lo devuelve igual las siguientes.
     */
    public function remember(string $key, Closure $build): mixed
    {
        if (! array_key_exists($key, $this->remembered)) {
            $this->remembered[$key] = $build();
        }

        return $this->remembered[$key];
    }
}
