{{--
    El historial de corridas (variante C, al pie): una tabla desde 768 px (corrida, alcance, motor, estado, duras, puntaje,
    cuántas sesiones cambian o el avance o el error, cuándo y quién) y renglones apilados en celular. Elegir una la abre
    arriba. La elegida va resaltada.
--}}
@php
    $extra = function ($each, ?int $written = null): array {
        $metrics = $each->metrics ?? [];

        return match (true) {
            $written !== null => ['written', $written],
            $each->status->isInProgress() => ['progress', $each->progress],
            $each->status === \App\Modules\Solver\Enums\SolverRunStatus::Failed => ['error', \Illuminate\Support\Str::limit((string) $each->error, 80)],
            isset($metrics['changed_sessions']) => ['count', (int) $metrics['changed_sessions']],
            default => ['none', null],
        };
    };
    $hardOf = fn ($each): ?int => isset($each->metrics['hard_violations']) ? (int) $each->metrics['hard_violations'] : null;
@endphp
<section class="mt-3 grid gap-2.5" aria-labelledby="history-title" data-testid="run-history">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="history-title" class="text-lg font-bold">{{ $scopeKind === 'department' ? 'Corridas de la carga' : 'Corridas que ves de tu carrera' }} <span class="font-mono font-normal text-neutral">{{ count($runs) }}</span></h2>
    </div>
    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <div class="overflow-x-auto max-md:hidden">
            <table class="table">
                <thead>
                    <tr class="text-neutral">
                        <th>Corrida</th>
                        <th>Alcance</th>
                        <th class="max-[1535px]:hidden">Motor</th>
                        <th>Estado</th>
                        <th>Duras</th>
                        <th class="text-end">Puntaje</th>
                        <th class="text-end">Cambian</th>
                        <th class="max-desktop:hidden">Lanzada</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($runs as $each)
                        @php([$kind, $value] = $extra($each['run'], $each['written'] ?? null))
                        @php($hard = $hardOf($each['run']))
                        <tr @class(['bg-[color-mix(in_oklab,var(--color-secondary)_55%,var(--color-base-100))]' => $each['run']->id === $run->id]) wire:key="history-{{ $each['run']->id }}">
                            <td><button type="button" class="link font-semibold whitespace-nowrap link-hover" wire:click="selectRun('{{ $each['run']->id }}')" aria-current="{{ $each['run']->id === $run->id ? 'true' : 'false' }}">Corrida <span class="font-mono">{{ $each['number'] }}</span></button></td>
                            <td class="whitespace-nowrap">{{ $scopeText($each['run']) }}</td>
                            <td class="whitespace-nowrap max-[1535px]:hidden">{{ $engines[$each['run']->engine] ?? $each['run']->engine }}</td>
                            <td><x-core::status-badge :tone="$each['run']->status->tone()" :icon="$each['run']->status->icon()" :label="$each['run']->status->label()" /></td>
                            <td>
                                @if ($hard === null)
                                    <span class="text-neutral">—</span>
                                @else
                                    <x-core::status-badge :tone="$hard > 0 ? 'error' : 'success'" :icon="$hard > 0 ? 'o-x-circle' : 'o-check-circle'" :label="$hard === 1 ? '1 dura' : $hard.' duras'" />
                                @endif
                            </td>
                            <td class="text-end font-mono">{{ isset($each['run']->metrics['soft_score']) ? number_format($each['run']->metrics['soft_score']) : '—' }}</td>
                            <td class="text-end">
                                @if ($kind === 'progress')
                                    <span class="inline-flex items-center gap-2"><progress class="progress progress-primary h-1.5 w-16" value="{{ $value }}" max="100" aria-label="Avance"></progress><span class="font-mono text-sm">{{ $value }} %</span></span>
                                @elseif ($kind === 'error')
                                    <span class="inline-block max-w-48 truncate align-middle text-sm text-error" title="{{ $each['run']->error }}">{{ $value }}</span>
                                @elseif ($kind === 'written')
                                    <span class="font-mono">{{ number_format($value) }}</span> <span class="text-sm text-neutral">escritas</span>
                                @elseif ($kind === 'count')
                                    <span class="font-mono">{{ number_format($value) }}</span>
                                @else
                                    <span class="text-neutral">—</span>
                                @endif
                            </td>
                            <td class="text-sm max-desktop:hidden">
                                <span class="block whitespace-nowrap">{{ $when($each['run']->created_at) }}</span>
                                <span class="block max-w-56 truncate text-neutral max-[1535px]:hidden" title="{{ $each['run']->creator->name ?? '' }}">{{ $each['run']->creator->name ?? '—' }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <ul class="divide-y divide-base-300 md:hidden">
            @foreach ($runs as $each)
                <li wire:key="history-m-{{ $each['run']->id }}">
                    <button type="button" wire:click="selectRun('{{ $each['run']->id }}')" @class(['grid w-full gap-1 px-3.5 py-3 text-left', 'bg-[color-mix(in_oklab,var(--color-secondary)_55%,var(--color-base-100))]' => $each['run']->id === $run->id])>
                        <span class="flex items-center gap-2">
                            <b>Corrida <span class="font-mono">{{ $each['number'] }}</span></b>
                            <x-core::status-badge :tone="$each['run']->status->tone()" :icon="$each['run']->status->icon()" :label="$each['run']->status->label()" />
                            @isset($each['run']->metrics['soft_score'])
                                <span class="ms-auto font-mono font-semibold">{{ number_format($each['run']->metrics['soft_score']) }}</span>
                            @endisset
                        </span>
                        <span class="text-sm">{{ $scopeText($each['run']) }} · {{ $engines[$each['run']->engine] ?? $each['run']->engine }}</span>
                        <span class="text-sm text-neutral">{{ $when($each['run']->created_at) }} · {{ $each['run']->creator->name ?? '—' }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</section>
