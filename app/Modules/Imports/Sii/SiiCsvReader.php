<?php

namespace App\Modules\Imports\Sii;

use Generator;
use RuntimeException;

/**
 * Lee un CSV exportado del SII en streaming (`fgets`; un archivo llega a 40 MB) con el mismo algoritmo
 * del perfilador (`.github/scripts/sii-profile.py`):
 *
 * - cada línea se convierte de Windows-1252 a UTF-8 si no es UTF-8 válido, y se quita el BOM;
 * - el SII escribe los textos entre comillas simples (`'10 '`, con `''` para escapar) y los números
 *   sin comillas; algunos archivos usan comillas dobles, así que se detecta en las primeras 30 líneas;
 * - los decimales van con coma y sin comillas (87,50), por lo que un promedio llega partido en dos
 *   campos. Un renglón con campos de más se repara solo si exactamente una combinación de columnas
 *   decimales (`SiiFile::decimalColumns()`) deja los campos con comillas en columnas de texto y los
 *   números en columnas numéricas; si no, va a `malformed()` y no se entrega;
 * - tras un renglón en blanco, lo que no se puede reparar es una anotación al final: se ignora.
 *
 * Los valores se recortan. Una columna repetida en el encabezado se distingue con el sufijo `__<posición>`.
 */
final class SiiCsvReader
{
    private const string DELIMITER = ',';

    private const int QUOTE_SAMPLE_LINES = 30;

    private const int QUOTE_LEARN_ROWS = 2000;

    /** @var list<string>|null */
    private ?array $header = null;

    private string $quote = '"';

    /** @var list<bool|null>|null */
    private ?array $quoted = null;

    private int $repaired = 0;

    /** @var list<int> */
    private array $malformed = [];

    public function __construct(private readonly string $path, private readonly SiiFile $file) {}

    /**
     * Encabezados normalizados (minúsculas, sin acentos, `_`), en el orden del archivo.
     *
     * @return list<string>
     */
    public function header(): array
    {
        $this->prepare();

        return $this->header;
    }

    /**
     * Renglones sanos o reparados; la clave es el número de línea del archivo (el encabezado es la 1) y el
     * valor, columna → texto recortado en UTF-8.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(): Generator
    {
        $this->prepare();
        $this->repaired = 0;
        $this->malformed = [];
        $width = count($this->header);
        $afterBlank = false;

        $handle = $this->open();
        try {
            $this->readLine($handle);
            $lineNo = 1;
            while (($line = $this->readLine($handle)) !== null) {
                $lineNo++;
                $tokens = $this->tokenize($line);
                if ($this->isBlank($tokens)) {
                    $afterBlank = true;

                    continue;
                }

                $values = array_column($tokens, 0);
                if (count($tokens) !== $width) {
                    $fixed = $this->repair($tokens);
                    if ($fixed === null) {
                        if ($afterBlank) {
                            break;
                        }
                        $this->malformed[] = $lineNo;

                        continue;
                    }
                    $this->repaired++;
                    $values = $fixed;
                }
                $afterBlank = false;

                yield $lineNo => array_combine($this->header, array_map('trim', $values));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Renglones que se repararon uniendo una coma decimal, contados en el último recorrido de `rows()`.
     */
    public function repaired(): int
    {
        return $this->repaired;
    }

    /**
     * Números de línea con otro número de columnas que no se pudieron reparar, del último recorrido.
     *
     * @return list<int>
     */
    public function malformed(): array
    {
        return $this->malformed;
    }

    /**
     * Lee el encabezado, detecta la comilla y aprende qué columnas vienen entre comillas.
     */
    private function prepare(): void
    {
        if ($this->header !== null) {
            return;
        }

        $handle = $this->open();
        try {
            $first = $this->readLine($handle);
            if ($first === null) {
                throw new RuntimeException("El archivo {$this->path} está vacío.");
            }

            $sample = [];
            while (count($sample) < self::QUOTE_SAMPLE_LINES - 1 && ($line = $this->readLine($handle)) !== null) {
                $sample[] = $line;
            }
            $body = implode("\n", $sample);
            $single = substr_count($body, self::DELIMITER."'") + substr_count("\n".$body, "\n'");
            $double = substr_count($body, self::DELIMITER.'"') + substr_count("\n".$body, "\n\"");
            $this->quote = $single > $double ? "'" : '"';

            $header = [];
            foreach ($this->tokenize($first) as [$name]) {
                $name = SiiFile::normalize($name);
                $name = $name === '' ? 'sin_nombre' : $name;
                $header[] = in_array($name, $header, true) ? $name.'__'.count($header) : $name;
            }
            $this->header = $header;
        } finally {
            fclose($handle);
        }

        $this->quoted = $this->learnQuotes();
    }

    /**
     * Qué columnas vienen entre comillas en los primeros renglones sanos (null: siempre vacía, no se sabe).
     *
     * @return list<bool|null>
     */
    private function learnQuotes(): array
    {
        $width = count($this->header);
        $votes = array_fill(0, $width, [0, 0]);
        $healthy = 0;

        $handle = $this->open();
        try {
            $this->readLine($handle);
            while ($healthy < self::QUOTE_LEARN_ROWS && ($line = $this->readLine($handle)) !== null) {
                $tokens = $this->tokenize($line);
                if (count($tokens) !== $width) {
                    continue;
                }
                $healthy++;
                foreach ($tokens as $j => [$value, $wasQuoted]) {
                    if (trim($value) !== '') {
                        $votes[$j][(int) $wasQuoted]++;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return array_map(
            static fn (array $v): ?bool => $v[0] + $v[1] === 0 ? null : $v[1] > $v[0],
            $votes,
        );
    }

    /**
     * @param  list<array{string, bool}>  $tokens
     */
    private function isBlank(array $tokens): bool
    {
        foreach ($tokens as [$value]) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Partes de un renglón como (valor, venía entre comillas).
     *
     * @return list<array{string, bool}>
     */
    private function tokenize(string $line): array
    {
        $quote = $this->quote;
        $out = [];
        $i = 0;
        $n = strlen($line);

        while (true) {
            if ($i < $n && $line[$i] === $quote) {
                $j = $i + 1;
                $buf = '';
                while ($j < $n) {
                    if ($line[$j] === $quote) {
                        if ($j + 1 < $n && $line[$j + 1] === $quote) {
                            $buf .= $quote;
                            $j += 2;

                            continue;
                        }
                        break;
                    }
                    $buf .= $line[$j];
                    $j++;
                }
                $out[] = [$buf, true];
                $k = strpos($line, self::DELIMITER, $j + 1);
                if ($k === false) {
                    return $out;
                }
                $i = $k + 1;
            } else {
                $k = strpos($line, self::DELIMITER, $i);
                if ($k === false) {
                    $out[] = [substr($line, $i), false];

                    return $out;
                }
                $out[] = [substr($line, $i, $k - $i), false];
                $i = $k + 1;
            }
        }
    }

    /**
     * Une las comas decimales de un renglón con campos de más; null si no hay una única forma de hacerlo.
     *
     * @param  list<array{string, bool}>  $tokens
     * @return list<string>|null
     */
    private function repair(array $tokens): ?array
    {
        $extra = count($tokens) - count($this->header);
        $decimals = array_values(array_filter(
            $this->header,
            fn (string $name): bool => in_array($name, $this->file->decimalColumns(), true),
        ));
        if ($extra <= 0 || $extra > count($decimals)) {
            return null;
        }

        $found = [];
        foreach ($this->combinations($decimals, $extra) as $split) {
            $row = [];
            $t = 0;
            $ok = true;
            foreach ($this->header as $j => $name) {
                if ($t >= count($tokens)) {
                    $ok = false;
                    break;
                }
                if (in_array($name, $split, true)) {
                    if ($t + 1 >= count($tokens)
                        || $tokens[$t][1] || $tokens[$t + 1][1]
                        || ! $this->isDigits($tokens[$t][0]) || ! $this->isDigits($tokens[$t + 1][0])) {
                        $ok = false;
                        break;
                    }
                    $row[] = trim($tokens[$t][0]).'.'.trim($tokens[$t + 1][0]);
                    $t += 2;

                    continue;
                }
                [$value, $wasQuoted] = $tokens[$t];
                if (trim($value) !== '' && $this->quoted[$j] !== null && $this->quoted[$j] !== $wasQuoted) {
                    $ok = false;
                    break;
                }
                $row[] = $value;
                $t++;
            }
            if ($ok && $t === count($tokens)) {
                $found[] = $row;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    private function isDigits(string $value): bool
    {
        return preg_match('/^\s*-?\d+\s*$/', $value) === 1;
    }

    /**
     * @param  list<string>  $items
     * @return Generator<int, list<string>>
     */
    private function combinations(array $items, int $size, int $start = 0): Generator
    {
        if ($size === 0) {
            yield [];

            return;
        }
        for ($i = $start; $i <= count($items) - $size; $i++) {
            foreach ($this->combinations($items, $size - 1, $i + 1) as $rest) {
                yield [$items[$i], ...$rest];
            }
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo {$this->path}.");
        }

        return $handle;
    }

    /**
     * Siguiente línea sin salto final, en UTF-8 y sin BOM; null al terminar el archivo.
     *
     * @param  resource  $handle
     */
    private function readLine($handle): ?string
    {
        $line = fgets($handle);
        if ($line === false) {
            return null;
        }
        if (str_starts_with($line, "\xEF\xBB\xBF") && ftell($handle) === strlen($line)) {
            $line = substr($line, 3);
        }
        $line = rtrim($line, "\r\n");

        return mb_check_encoding($line, 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
    }
}
