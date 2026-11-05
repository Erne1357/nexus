<?php

namespace Tests\Unit\Imports\Sii;

use App\Modules\Imports\Sii\SiiFile;

/**
 * CSV temporales del SII con valores sintéticos y los encabezados reales: textos entre comillas simples y
 * números sin comillas, como los exporta el SII.
 */
final class SiiFixtures
{
    private const array NUMERIC = [
        'semestre', 'creditos_aprobados', 'creditos_cursados', 'periodos_revalidacion',
        'capacidad_aula', 'promedio_periodo_anterior', 'promedio_aritmetico_acumulado',
        'promedio_final_alcanzado', 'indice_reprobacion_acumulado',
    ];

    public static function dir(): string
    {
        $dir = sys_get_temp_dir().'/sii-test-'.bin2hex(random_bytes(6));
        mkdir($dir);

        return $dir;
    }

    public static function clean(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }

    /**
     * @param  list<string>  $headers
     */
    public static function headerLine(array $headers): string
    {
        return implode(',', array_map(fn (string $h): string => "'".$h."'", $headers));
    }

    /**
     * Un renglón con todas las columnas llenas de `x` (o `1` si son numéricas) salvo las indicadas.
     *
     * @param  array<string, string>  $overrides  valor ya en crudo (con sus comillas) por columna
     */
    public static function line(SiiFile $file, array $overrides = []): string
    {
        $cells = [];
        foreach ($file->headers() as $header) {
            $cells[] = $overrides[$header]
                ?? (in_array($header, self::NUMERIC, true) ? '1' : "'x'");
        }

        return implode(',', $cells);
    }

    /**
     * @param  list<string>  $lines  renglones en crudo, sin el encabezado
     */
    public static function write(string $path, SiiFile $file, array $lines, ?string $header = null): void
    {
        file_put_contents($path, ($header ?? self::headerLine($file->headers()))."\r\n".implode("\r\n", $lines)."\r\n");
    }
}
