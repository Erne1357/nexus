<?php

namespace App\Modules\Imports\Sii;

/**
 * Lo que dejó la primera pasada completa por un archivo del SII: renglones malformados (no se migran), reparados por
 * coma decimal y la anotación al final. Da una incidencia por tipo con la cuenta y los números de línea (los primeros
 * `LISTED`), nunca el contenido del renglón. Guarda solo las cuentas y esas primeras líneas.
 */
final readonly class SiiFileRead
{
    public const int LISTED = 50;

    /** @var array{int, list<int>} */
    private array $malformed;

    /** @var array{int, list<int>} */
    private array $repaired;

    /** @var array{int, list<int>} */
    private array $trailing;

    /**
     * @param  string  $file  nombre del archivo de la exportación, sin carpeta
     * @param  list<int>  $malformed
     * @param  list<int>  $repaired
     * @param  list<int>  $trailing
     */
    public function __construct(public string $file, array $malformed, array $repaired, array $trailing)
    {
        $this->malformed = [count($malformed), array_slice($malformed, 0, self::LISTED)];
        $this->repaired = [count($repaired), array_slice($repaired, 0, self::LISTED)];
        $this->trailing = [count($trailing), array_slice($trailing, 0, self::LISTED)];
    }

    /**
     * Renglones que no se pudieron leer y no se migran.
     */
    public function unreadable(): int
    {
        return $this->malformed[0];
    }

    /**
     * @return list<array{level: 'error'|'warning', ref: string, message: string}>
     */
    public function incidents(): array
    {
        $out = [];

        if ($this->malformed[0] > 0) {
            $out[] = ['level' => 'error', 'ref' => 'malformados', 'message' => "{$this->malformed[0]} renglón(es) que no se pudieron leer (otro número de columnas o una comilla sin cerrar): no se migran. ".$this->lines($this->malformed)];
        }

        if ($this->repaired[0] > 0) {
            $out[] = ['level' => 'warning', 'ref' => 'reparados', 'message' => "{$this->repaired[0]} renglón(es) con coma decimal se repararon uniendo el número y se migran. ".$this->lines($this->repaired)];
        }

        if ($this->trailing[0] > 0) {
            $out[] = ['level' => 'warning', 'ref' => 'anotación al final', 'message' => "{$this->trailing[0]} línea(s) que no son renglones después del último renglón en blanco: se ignoran como anotación al final del archivo. ".$this->lines($this->trailing)];
        }

        return $out;
    }

    /**
     * @param  array{int, list<int>}  $lines
     */
    private function lines(array $lines): string
    {
        [$count, $listed] = $lines;
        $more = $count - count($listed);

        return 'Líneas '.implode(', ', $listed).($more > 0 ? " y {$more} más." : '.');
    }
}
