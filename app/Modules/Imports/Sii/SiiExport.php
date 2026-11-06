<?php

namespace App\Modules\Imports\Sii;

use InvalidArgumentException;

/**
 * Una carpeta con la exportación del SII. Al abrirla busca cada archivo por su nombre normalizado
 * (`alumnos todos.csv` es `alumnos_todos`) y compara sus encabezados con el contrato de `SiiFile`.
 */
final class SiiExport
{
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

        return new SiiCsvReader($path, $file);
    }

    public function dir(): string
    {
        return $this->dir;
    }
}
