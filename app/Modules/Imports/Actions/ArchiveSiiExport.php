<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use Illuminate\Support\Facades\DB;
use Pdo\Pgsql;
use RuntimeException;

/**
 * Guarda la exportación del SII tal cual, renglón por renglón, en el esquema `sii_archivo` de PostgreSQL: un archivo de
 * solo lectura para rastrear cualquier dato de Nexus hasta su origen, que solo consulta el administrador en la base
 * (decisión del 10 de noviembre de 2026, confirmada). No es parte del modelo de dominio y no se edita.
 *
 * Una tabla por archivo presente, con el valor de SiiFile como nombre (`alumnos_todos`, `historia_alumno`, …): `_linea`
 * (número de línea del CSV, el encabezado es la 1; llave primaria) y las columnas del contrato (`SiiFile::headers()`)
 * como `text not null`, en ese orden, menos las de EXCLUDED. El contrato no deja pasar columnas de más (SiiExport::open),
 * así que no hay otras. Los valores son los que entrega SiiCsvReader (UTF-8, recortados, coma decimal reparada), sin
 * otra conversión; un campo vacío es la cadena vacía, nunca null. Los renglones que el lector no puede leer no se
 * guardan: su cuenta queda en `_cargas.ilegibles` y sus líneas, en `_incidencias.csv`.
 *
 * `sii_archivo._cargas` dice qué se cargó en la última corrida: `archivo` (= nombre de la tabla), `nombre_en_carpeta`,
 * `renglones`, `ilegibles`, `columnas_excluidas`, `carpeta` de la exportación y `cargado_en`.
 *
 * Cada carga reemplaza el archivo completo en una sola transacción: borra todas las tablas del esquema (también la de un
 * archivo que esta vez no vino, para que refleje solo esta exportación) y las vuelve a crear; si algo falla, queda el
 * archivo anterior. Borra sin CASCADE: si alguien creó una vista sobre una tabla del archivo, la carga falla en lugar de
 * borrarla. Los renglones entran con COPY (`Pdo\Pgsql::copyFromArray`) en bloques de CHUNK, sin leer el archivo entero a
 * memoria: la historia trae unos dos millones.
 *
 * El esquema vive fuera de las migraciones: la carga lo crea si no existe, y `migrate:fresh`, que solo borra las tablas
 * de los esquemas del `search_path`, no lo toca. Con `$dryRun` lee y cuenta sin escribir nada.
 */
final class ArchiveSiiExport
{
    public const string SCHEMA = 'sii_archivo';

    public const string LOADS = '_cargas';

    private const int CHUNK = 5000;

    /**
     * Columnas que nunca se guardan, por archivo. El NIP del alumno y la `contrasena` de `acceso` (un MD5 sin sal) son contraseñas del SII; la foto y la firma son la
     * imagen de la persona. Ninguna sirve para rastrear un dato de Nexus y no deben copiarse fuera del SII. El lector las
     * descarta en cuanto lee el renglón (`SiiCsvReader::rows()` con columnas).
     *
     * @var array<string, list<string>>
     */
    public const array EXCLUDED = [
        SiiFile::Docentes->value => ['foto', 'firma'],
        SiiFile::Alumnos->value => ['foto', 'firma', 'nip'],
        SiiFile::Acceso->value => ['contrasena'],
    ];

    /**
     * Las columnas que se guardan de un archivo, en el orden del contrato.
     *
     * @return list<string>
     */
    public static function columns(SiiFile $file): array
    {
        return array_values(array_diff($file->headers(), self::EXCLUDED[$file->value] ?? []));
    }

    /**
     * @return array<string, int> renglones guardados (o que se guardarían, con `$dryRun`) por valor de SiiFile, en el
     *                            orden de SiiFile
     */
    public function handle(SiiExport $export, bool $dryRun = false): array
    {
        $files = array_values(array_filter(SiiFile::cases(), fn (SiiFile $file): bool => $export->has($file)));

        if ($dryRun) {
            return array_combine(
                array_map(fn (SiiFile $file): string => $file->value, $files),
                array_map(fn (SiiFile $file): int => iterator_count($export->reader($file)->rows(self::columns($file))), $files),
            );
        }

        return DB::transaction(function () use ($export, $files): array {
            $this->reset();
            $counts = [];

            foreach ($files as $file) {
                $counts[$file->value] = $this->load($export, $file);
            }

            return $counts;
        });
    }

    /**
     * Crea el esquema si no existe, borra todas sus tablas y vuelve a crear `_cargas`.
     */
    private function reset(): void
    {
        DB::statement('create schema if not exists '.self::SCHEMA);
        DB::statement('comment on schema '.self::SCHEMA." is 'Archivo de solo lectura de la exportación original del SII; imports:migrate-sii lo reemplaza en cada corrida. No se edita.'");

        foreach (DB::table('pg_tables')->where('schemaname', self::SCHEMA)->pluck('tablename') as $table) {
            DB::statement('drop table '.$this->table($table));
        }

        DB::statement('create table '.$this->table(self::LOADS).' (
            archivo text primary key,
            nombre_en_carpeta text not null,
            renglones integer not null,
            ilegibles integer not null,
            columnas_excluidas text[] not null,
            carpeta text not null,
            cargado_en timestamptz not null default now()
        )');
    }

    /**
     * Crea la tabla de un archivo, la llena con COPY por bloques y anota la carga en `_cargas`. Devuelve los renglones.
     */
    private function load(SiiExport $export, SiiFile $file): int
    {
        $columns = self::columns($file);
        $table = $this->table($file->value);
        $definitions = array_map(fn (string $column): string => $this->quote($column).' text not null', $columns);

        DB::statement("create table {$table} (\"_linea\" integer primary key, ".implode(', ', $definitions).')');

        $reader = $export->reader($file);
        $chunk = [];
        $rows = 0;

        foreach ($reader->rows($columns) as $line => $row) {
            $chunk[] = $line."\t".implode("\t", array_map($this->escape(...), $row))."\n";
            $rows++;

            if (count($chunk) === self::CHUNK) {
                $this->copy($table, $chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->copy($table, $chunk);
        }

        $excluded = self::EXCLUDED[$file->value] ?? [];

        DB::table(self::SCHEMA.'.'.self::LOADS)->insert([
            'archivo' => $file->value,
            'nombre_en_carpeta' => $export->fileName($file),
            'renglones' => $rows,
            'ilegibles' => count($reader->malformed()),
            'columnas_excluidas' => '{'.implode(',', $excluded).'}',
            'carpeta' => $export->dir(),
        ]);

        return $rows;
    }

    /**
     * @param  list<string>  $lines  renglones en el formato de texto de COPY, con su salto de línea
     */
    private function copy(string $table, array $lines): void
    {
        $pdo = DB::connection()->getPdo();

        if (! $pdo instanceof Pgsql) {
            throw new RuntimeException('El archivo del SII necesita una conexión de PostgreSQL.');
        }

        if (! $pdo->copyFromArray($table, $lines)) {
            throw new RuntimeException("Falló la copia a {$table}.");
        }
    }

    /**
     * Escapa un valor para el formato de texto de COPY: la diagonal invertida, el tabulador y los saltos de línea. Así
     * `\N` llega como texto y nunca como null.
     */
    private function escape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', "\t" => '\t', "\n" => '\n', "\r" => '\r']);
    }

    private function table(string $name): string
    {
        return self::SCHEMA.'.'.$this->quote($name);
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
