<?php

namespace App\Modules\Imports\Sii;

use InvalidArgumentException;

/**
 * Una carpeta con la exportación del SII. Al abrirla busca cada archivo por su nombre normalizado
 * (`alumnos todos.csv` es `alumnos_todos`) y compara sus encabezados con el contrato de `SiiFile`.
 *
 * Recuerda lo que dejó la primera pasada completa por cada archivo (malformados, reparados, anotación al final) para
 * que la corrida lo reporte una vez por archivo aunque varios pasos lo lean (`takeReads()`). Vive lo que dura la
 * corrida.
 */
final class SiiExport
{
    /** @var array<string, SiiFileRead> por valor de SiiFile */
    private array $reads = [];

    /** @var array<string, true> */
    private array $taken = [];

    /**
     * @param  array<string, string>  $paths  ruta por valor de SiiFile
     */
    private function __construct(private readonly string $dir, private readonly array $paths) {}

    /**
     * @throws SiiContractBroken si falta un archivo obligatorio o cambian sus encabezados
     */
    public static function open(string $dir): self
    {
        $dir = rtrim($dir, '/');
        $found = [];
        foreach (glob($dir.'/*') ?: [] as $path) {
            if (! is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
                continue;
            }
            $found[SiiFile::normalize(pathinfo($path, PATHINFO_FILENAME))] ??= $path;
        }

        $paths = [];
        $differences = [];
        foreach (SiiFile::cases() as $file) {
            $path = $found[$file->value] ?? null;
            if ($path === null) {
                if ($file->required()) {
                    $differences[$file->value] = ['missing' => $file->headers(), 'extra' => []];
                }

                continue;
            }

            $paths[$file->value] = $path;
            $actual = (new SiiCsvReader($path, $file))->header();
            $missing = array_values(array_diff($file->headers(), $actual));
            $extra = array_values(array_diff($actual, $file->headers()));
            if ($missing !== [] || $extra !== []) {
                $differences[$file->value] = ['missing' => $missing, 'extra' => $extra];
            }
        }

        if ($differences !== []) {
            throw new SiiContractBroken($differences);
        }

        return new self($dir, $paths);
    }

    public function reader(SiiFile $file): SiiCsvReader
    {
        $path = $this->paths[$file->value] ?? throw new InvalidArgumentException("La exportación no trae el archivo {$file->value}.");

        return new SiiCsvReader($path, $file, function (SiiCsvReader $reader) use ($file, $path): void {
            $this->reads[$file->value] ??= new SiiFileRead(basename($path), $reader->malformed(), $reader->repaired(), $reader->trailing());
        });
    }

    /**
     * El nombre del archivo en la carpeta (sin la carpeta), para señalar una línea en las incidencias.
     */
    public function fileName(SiiFile $file): string
    {
        return basename($this->paths[$file->value] ?? throw new InvalidArgumentException("La exportación no trae el archivo {$file->value}."));
    }

    /**
     * Lo leído de los archivos que terminaron su primera pasada completa desde la última llamada, en el orden en que
     * terminaron; cada archivo sale una sola vez por corrida.
     *
     * @return list<SiiFileRead>
     */
    public function takeReads(): array
    {
        $new = array_diff_key($this->reads, $this->taken);
        $this->taken += array_fill_keys(array_keys($new), true);

        return array_values($new);
    }

    public function dir(): string
    {
        return $this->dir;
    }
}
