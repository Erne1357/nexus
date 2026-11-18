<?php

namespace App\Modules\Solver\Support;

use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Dónde vive la entrada o la salida del contrato de una corrida (tarea 6.8; docs/arquitectura/solver.md, «Ciclo de
 * vida»): en la fila (`input`, `output`, jsonb) si su JSON cabe en ROW_LIMIT_BYTES y si no, en un archivo
 * `solver/runs/<id>/<input|output>.json` del disco `local`, que la aplicación y la cola comparten (`storage/app`, un
 * volumen en producción), con la ruta en `input_path` u `output_path`. La entrada de la carga más grande de la demo
 * masiva (2 MB) va a archivo; la de un departamento típico, en la fila. Así cada corrida se puede reproducir.
 *
 * Sin estado, para Octane.
 */
final class RunPayloads
{
    public const string DISK = 'local';

    public const int ROW_LIMIT_BYTES = 1_048_576;

    /**
     * Las columnas de la corrida para guardar la entrada o la salida; si va a archivo, lo escribe.
     *
     * @param  'input'|'output'  $kind
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>|string|null>
     */
    public static function attributes(string $runId, string $kind, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($json) <= self::ROW_LIMIT_BYTES) {
            return [$kind => $payload, "{$kind}_path" => null];
        }

        $path = "solver/runs/{$runId}/{$kind}.json";
        Storage::disk(self::DISK)->put($path, $json);

        return [$kind => null, "{$kind}_path" => $path];
    }

    /**
     * La entrada o la salida guardada, de la fila o de su archivo.
     *
     * @param  'input'|'output'  $kind
     * @return array<string, mixed>
     *
     * @throws RuntimeException si la corrida no la tiene o su archivo ya no está
     */
    public static function read(SolverRun $run, string $kind): array
    {
        $label = $kind === 'input' ? 'entrada' : 'salida';

        if (is_array($run->{$kind})) {
            return $run->{$kind};
        }

        $path = $run->{"{$kind}_path"} ?? throw new RuntimeException("La corrida no tiene {$label}.");
        $json = Storage::disk(self::DISK)->get($path) ?? throw new RuntimeException("No se encontró el archivo de la {$label} de la corrida.");

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Borra el archivo que attributes() escribió para una corrida que al final no se guardó.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function discard(array $attributes): void
    {
        foreach (['input_path', 'output_path'] as $column) {
            if (is_string($attributes[$column] ?? null)) {
                Storage::disk(self::DISK)->delete($attributes[$column]);
            }
        }
    }

    public static function input(SolverRun $run): SolverInput
    {
        return SolverInput::fromArray(self::read($run, 'input'));
    }

    public static function output(SolverRun $run): ?SolverOutput
    {
        return $run->output === null && $run->output_path === null ? null : SolverOutput::fromArray(self::read($run, 'output'));
    }
}
