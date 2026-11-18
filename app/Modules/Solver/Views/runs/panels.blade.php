{{--
    Los paneles de la pantalla de corridas (ADR-0023: nunca los diálogos del navegador):
    - el puntaje de la corrida en un drawer (regla, peso y puntos; las violaciones duras, lo que se queda y el log);
    - lanzar en un drawer: alcance, motor, límite de tiempo y los pesos de solo lectura (los fija Subdirección en
      «Parámetros»), con el aviso de tronco común y el orden sugerido;
    - aceptar en un `x-modal` con el resumen de lo que se escribe y de lo que se queda, y la entrada vencida en otro.
--}}
@php
    $limitLabel = fn (int $seconds): string => $seconds % 60 === 0 ? intdiv($seconds, 60).' min' : $seconds.' s';
    $number = $row['number'] ?? '';
@endphp

<x-drawer wire:model.live="showScore" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="score-title" data-testid="score-drawer">
    @if ($showScore && $run && $output)
        <div class="flex h-full flex-col">
            <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                <div class="min-w-0">
                    <h2 id="score-title" class="text-xl font-bold">Puntaje de la corrida <span class="font-mono">{{ $number }}</span></h2>
                    <p class="text-sm text-neutral">{{ $scopeText($run) }} · {{ $engines[$run->engine] ?? $run->engine }} · menos es mejor</p>
                </div>
                <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto" wire:click="$set('showScore', false)" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
            </header>
            <div class="grid flex-1 content-start gap-5 overflow-y-auto px-5 py-4">
                <section class="overflow-hidden rounded-box border border-base-300">
                    <table class="table table-sm">
                        <thead><tr class="text-neutral"><th>Regla</th><th class="text-end">Peso</th><th class="text-end">Puntos</th></tr></thead>
                        <tbody>
                            @foreach ($softRules as $rule => $name)
                                <tr>
                                    <td><span class="font-mono font-semibold">{{ $rule }}</span> {{ $name }}</td>
                                    <td class="text-end font-mono text-neutral">×{{ $weights[$rule] ?? 0 }}</td>
                                    <td class="text-end font-mono">{{ number_format($output->softScore->byRule[$rule] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr class="text-base-content"><td class="font-bold">Puntaje blando</td><td></td><td class="text-end font-mono text-base font-bold">{{ number_format($output->softScore->total) }}</td></tr></tfoot>
                    </table>
                </section>
                <section class="grid gap-2">
                    <h3 class="font-bold">Violaciones duras</h3>
                    @forelse ($output->hardViolations as $violation)
                        <div class="flex gap-2 rounded-field border border-error/40 bg-error/10 px-3 py-2 text-sm"><span class="shrink-0 font-mono font-semibold text-error">{{ $violation->rule }}</span><span>{{ $violation->message }}</span></div>
                    @empty
                        <p class="flex items-center gap-2 text-sm text-success"><x-icon name="o-check-circle" class="h-4 w-4" aria-hidden="true" />Sin violaciones duras.</p>
                    @endforelse
                </section>
                @if ($comparison)
                    <section class="grid gap-2">
                        <h3 class="font-bold">Se quedan como están</h3>
                        @forelse ($comparison->kept() as $entry)
                            <div class="grid gap-0.5 rounded-field border border-base-300 px-3 py-2 text-sm">
                                <b class="font-mono">{{ $entry['group']['title'] }} <span class="font-sans font-normal text-neutral">{{ $entry['group']['campus'] }} · {{ $entry['group']['subject_name'] }}</span></b>
                                <span>{{ implode(' ', $entry['reasons']) }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-neutral">Ninguno: todos los grupos se escriben.</p>
                        @endforelse
                    </section>
                @endif
                @if ($output->log !== [])
                    <details class="rounded-box border border-base-300 px-3 py-2 text-sm">
                        <summary class="cursor-pointer font-semibold">Bitácora del motor</summary>
                        <pre class="mt-2 font-mono text-xs whitespace-pre-wrap text-neutral">{{ implode("\n", $output->log) }}</pre>
                    </details>
                @endif
            </div>
            <p class="border-t border-base-300 px-5 py-3 text-sm text-neutral">Puntos = veces que se rompe la regla × su peso; menos es mejor.</p>
        </div>
    @endif
</x-drawer>

<x-drawer wire:model.live="showLaunch" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="launch-title" data-testid="launch-drawer">
    @if ($showLaunch)
        <div class="flex h-full flex-col">
            <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                <div class="min-w-0">
                    <h2 id="launch-title" class="text-xl font-bold">{{ $launch['again'] ? 'Volver a correr' : 'Lanzar una corrida' }}</h2>
                    <p class="text-sm text-neutral">{{ $scopeName }} · {{ $shownModality->name }} · {{ $term->name }}</p>
                </div>
                <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto" wire:click="$set('showLaunch', false)" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
            </header>
            <form wire:submit="launch" class="grid flex-1 content-start gap-5 overflow-y-auto px-5 py-4" id="launch-form">
                @if ($launch['again'])
                    <p class="alert alert-soft text-sm"><x-icon name="o-arrow-path" class="h-5 w-5" aria-hidden="true" />Con el alcance, el motor y el límite de la corrida que falló o quedó vencida.</p>
                @endif
                @include('solver::runs.trunk', ['compact' => false])

                <fieldset class="grid gap-2">
                    <legend class="mb-2 font-semibold">Alcance</legend>
                    <label @class(['flex items-start gap-3 rounded-field border border-base-300 px-3 py-2.5', 'cursor-pointer has-checked:border-primary has-checked:bg-primary/5' => $launch['department'], 'text-neutral' => ! $launch['department']])>
                        <input type="radio" class="radio radio-sm mt-0.5" value="department" wire:model.live="launchScope" @disabled(! $launch['department']) @if (! $launch['department']) aria-describedby="why-dept" @endif />
                        <span class="grid gap-0.5">
                            <b class="text-base-content">Toda la carga del departamento</b>
                            <small class="text-sm">{{ $scopeKind === 'department' ? number_format($facts['groups']).' grupos en todos sus campus; es la que se acepta' : 'La de cada departamento; es la que se acepta' }}</small>
                            @unless ($launch['department'])
                                <small id="why-dept" class="flex items-center gap-1 text-sm"><x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" />Las de la carga las lanza el jefe del departamento.</small>
                            @endunless
                        </span>
                    </label>
                    <label @class(['flex items-start gap-3 rounded-field border border-base-300 px-3 py-2.5', 'cursor-pointer has-checked:border-primary has-checked:bg-primary/5' => $launch['careers'] !== [], 'text-neutral' => $launch['careers'] === []])>
                        <input type="radio" class="radio radio-sm mt-0.5" value="career" wire:model.live="launchScope" @disabled($launch['careers'] === []) @if ($launch['careers'] === []) aria-describedby="why-career" @endif />
                        <span class="grid min-w-0 gap-1">
                            <b class="text-base-content">{{ $scopeKind === 'career' ? 'Tu carrera' : 'Una carrera' }}</b>
                            <small class="text-sm">Para explorar y comparar; los demás grupos entran fijos y no se acepta.</small>
                            @if (count($launch['careers']) > 1)
                                <label class="select select-sm w-full">
                                    <span class="sr-only">Carrera</span>
                                    <select wire:model="launchCareer" aria-label="Carrera">
                                        @foreach ($launch['careers'] as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['code'] }} · {{ $option['name'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @elseif ($launch['careers'] !== [])
                                <small class="text-sm"><span class="font-mono font-semibold text-base-content">{{ $launch['careers'][0]['code'] }}</span> {{ $launch['careers'][0]['name'] }}</small>
                            @else
                                <small id="why-career" class="flex items-center gap-1 text-sm"><x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" />Las de una carrera las lanza su coordinador.</small>
                            @endif
                        </span>
                    </label>
                </fieldset>

                <div class="grid gap-4 md:grid-cols-2">
                    <fieldset class="grid gap-2">
                        <legend class="mb-2 font-semibold">Motor</legend>
                        <div class="join w-full" role="radiogroup" aria-label="Motor">
                            @foreach ($engines as $key => $label)
                                <button type="button" role="radio" aria-checked="{{ $launchEngine === $key ? 'true' : 'false' }}" wire:click="$set('launchEngine', '{{ $key }}')"
                                    @class(['btn join-item grow', 'btn-primary' => $launchEngine === $key])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </fieldset>
                    <fieldset class="grid gap-2">
                        <legend class="mb-2 font-semibold"><label for="launch-limit">Límite de tiempo</label></legend>
                        <label class="select w-full">
                            <select id="launch-limit" wire:model.number="launchLimit" data-testid="launch-limit">
                                @foreach ($launch['limits'] as $seconds)
                                    <option value="{{ $seconds }}">{{ $limitLabel($seconds) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </fieldset>
                </div>

                <fieldset class="grid gap-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <legend class="font-semibold">Pesos del puntaje</legend>
                        @if ($can['settings'])
                            <a href="{{ route('core.settings') }}" wire:navigate class="btn btn-ghost btn-sm"><x-icon name="o-adjustments-horizontal" class="h-4 w-4" aria-hidden="true" />Cambiar en Parámetros</a>
                        @endif
                    </div>
                    <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300 text-sm" data-testid="launch-weights">
                        @foreach ($softRules as $rule => $name)
                            <li class="grid grid-cols-[3.75rem_minmax(0,1fr)_auto] items-center gap-2 px-3 py-1.5">
                                <span class="font-mono font-semibold">{{ $rule }}</span>
                                <span>{{ $name }}</span>
                                <span class="font-mono font-semibold">{{ $launch['weights'][$rule] ?? 0 }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-sm text-neutral">Los fija Subdirección para todo el Tec; todas las corridas usan los mismos.</p>
                </fieldset>
            </form>
            <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-base-300 px-5 py-3 max-sm:flex-col-reverse max-sm:items-stretch">
                <span class="me-auto text-sm text-neutral max-sm:me-0">{{ $launchScope === 'career' ? 'Es para explorar; la acepta el jefe del departamento.' : 'Nada se escribe hasta que aceptas una corrida.' }}</span>
                <button type="button" class="btn btn-ghost max-sm:h-11" wire:click="$set('showLaunch', false)">Cancelar</button>
                <x-button type="submit" form="launch-form" class="btn-primary max-sm:h-11" icon="o-play" label="Lanzar corrida" spinner="launch" data-testid="launch-submit" />
            </footer>
        </div>
    @endif
</x-drawer>

@if ($run && $comparison && $run->status === \App\Modules\Solver\Enums\SolverRunStatus::Done)
    <x-modal wire:model="showAccept" box-class="max-w-lg" role="alertdialog" aria-labelledby="accept-title" aria-describedby="accept-text" data-testid="accept-modal">
        @if ($showAccept)
            @php($all = $comparison->stats())
            @php($keptAll = $comparison->kept())
            <div class="grid gap-3 pe-6">
                <h2 id="accept-title" class="text-lg leading-snug font-bold">¿Aceptar la corrida {{ $number }}?</h2>
                <p id="accept-text">Se escriben <b class="font-mono">{{ number_format($all['changes']) }}</b> sesiones en <b class="font-mono">{{ $all['groups'] }}</b> grupos: <span class="font-mono">{{ $all['new'] }}</span> nuevas, <span class="font-mono">{{ $all['moved'] }}</span> movidas, <span class="font-mono">{{ $all['room'] }}</span> con otro salón y <span class="font-mono">{{ $all['removed'] }}</span> que se quitan. Las <span class="font-mono">{{ $all['same'] }}</span> sin cambio no se tocan y después se recalculan los choques.</p>
                @if (count($campusChanges) > 1)
                    <div class="divide-y divide-base-300 rounded-box border border-base-300">
                        @foreach ($campusChanges as $code => $count)
                            @php($inCampus = $comparison->stats($code))
                            <div class="flex items-center gap-2 px-3 py-2">
                                <x-core::campus-tag :campus="$campuses[$code] ?? (object) ['code' => $code, 'name' => $code]" />
                                <span class="grow">{{ $campuses[$code]->name ?? $code }}</span>
                                <span><b class="font-mono">{{ $inCampus['changes'] }}</b> sesiones · <span class="font-mono">{{ $inCampus['groups'] }}</span> grupos</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($keptAll !== [])
                    <div role="note" class="alert alert-warning alert-soft items-start text-sm" data-testid="accept-kept">
                        <x-icon name="o-exclamation-triangle" class="h-5 w-5 shrink-0" aria-hidden="true" />
                        <div class="grid gap-1 text-base-content">
                            <b>{{ count($keptAll) === 1 ? '1 grupo se queda como está' : count($keptAll).' grupos se quedan como están' }}</b>
                            <span>El motor no los acomodó o chocarían con lo que no se mueve; conservan su horario de hoy.</span>
                            <ul class="grid gap-0.5">
                                @foreach (array_slice($keptAll, 0, 5) as $entry)
                                    <li><span class="font-mono font-semibold">{{ $entry['group']['title'] }}</span> · {{ $entry['reasons'][0] ?? '' }}</li>
                                @endforeach
                                @if (count($keptAll) > 5)
                                    <li class="text-neutral">y {{ count($keptAll) - 5 }} más (en «Lo que queda»).</li>
                                @endif
                            </ul>
                        </div>
                    </div>
                @endif
                <p class="text-sm text-neutral">Antes de escribir se revisa que la carga siga igual que cuando se lanzó.</p>
            </div>
            <x-slot:actions>
                <div class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row">
                    <button type="button" class="btn btn-ghost max-sm:h-11" wire:click="$set('showAccept', false)" autofocus>Cancelar</button>
                    <x-button class="btn-primary max-sm:h-11" icon="o-check" label="Aceptar y escribir" wire:click="apply" spinner="apply" data-testid="accept-confirm" />
                </div>
            </x-slot:actions>
        @endif
    </x-modal>
@endif

@if ($run)
    <x-modal wire:model="showStale" box-class="max-w-md" role="alertdialog" aria-labelledby="stale-title" aria-describedby="stale-text" data-testid="stale-modal">
        @if ($showStale)
            <div class="grid gap-2 pe-6">
                <h2 id="stale-title" class="text-lg leading-snug font-bold">La carga cambió desde la corrida {{ $number }}</h2>
                <p id="stale-text" class="text-neutral">Corrió {{ mb_strtolower($when($run->created_at)) }}; desde entonces cambió la carga o lo que el motor tomó como fijo. No se escribió nada: vuelve a correrla.</p>
            </div>
            <x-slot:actions>
                <div class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row">
                    <button type="button" class="btn btn-ghost max-sm:h-11" wire:click="$set('showStale', false)">Cerrar</button>
                    @if ($can['launch'])
                        <button type="button" class="btn btn-primary max-sm:h-11" wire:click="rerun" autofocus data-testid="stale-again"><x-icon name="o-arrow-path" class="h-4 w-4" aria-hidden="true" />Volver a correr</button>
                    @endif
                </div>
            </x-slot:actions>
        @endif
    </x-modal>
@endif
