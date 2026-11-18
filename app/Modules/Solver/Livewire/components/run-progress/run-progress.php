<?php

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Support\RunPayloads;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * El avance de la corrida en curso en la pantalla de corridas (tarea 6.10; revisión del Riel A en #594): un componente
 * aparte para que el `wire:poll` de cada 2 segundos lea solo el estado, el avance, el mejor puntaje y el tiempo de esa
 * corrida, sin volver a armar la página (el historial, las cifras de la carga, las opciones de lanzar). El límite de
 * tiempo se lee de la entrada una sola vez, al montar. Cuando la corrida deja de estar en curso, avisa a la página con
 * `run-finished` para que se pinte con su resultado, y deja de leer.
 *
 * También cubre `applying` («Aplicando…», SV-05): mientras el job escribe el horario sigue el poll.
 *
 * Lo ve quien ve la corrida (SolverRunPolicy::view), en cada tic.
 */
new class extends Component
{
    #[Locked]
    public string $runId;

    /** El tiempo máximo de la corrida, en segundos, leído de su entrada al montar. */
    #[Locked]
    public int $limit = 0;

    public function mount(string $runId): void
    {
        $run = $this->run($runId, withInput: true);
        $this->runId = $run->getKey();
        $this->limit = (int) (RunPayloads::read($run, 'input')['time_limit_seconds'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $run = $this->run($this->runId);
        $live = $run->status->isInProgress() || $run->status->isApplying();

        if (! $live) {
            $this->dispatch('run-finished');
        }

        return [
            'run' => $run,
            'live' => $live,
            'elapsed' => $run->started_at === null ? 0 : (int) $run->started_at->diffInSeconds(now()),
        ];
    }

    /**
     * La corrida con solo lo que pinta el avance (sin su salida, y su entrada solo al montar), con la policy de verla.
     */
    private function run(string $id, bool $withInput = false): SolverRun
    {
        $columns = ['id', 'term_id', 'modality_id', 'scope_kind', 'department_id', 'career_id', 'status', 'progress', 'best_score', 'started_at', 'cancel_requested_at', 'created_by', 'updated_at'];
        $run = KeyLookup::find(SolverRun::query()->select($withInput ? [...$columns, 'input', 'input_path'] : $columns), $id) ?? abort(404);
        $this->authorize('view', $run);

        return $run;
    }
};
