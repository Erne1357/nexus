{{--
    Con `$inline` (la lista) todo va en un renglón.
    Los contadores de una revisión (CatalogReviewQuery::summary): activos e inactivos, las altas y las bajas contra
    la foto de la validación anterior con su lista (CV3) o «Primer periodo en Nexus» si no hay, y las observaciones
    abiertas. `$summary` es el arreglo de summary().
--}}
@php($inline ??= false)
<div @class(['space-y-3.5' => ! $inline, 'flex flex-wrap items-center gap-x-8 gap-y-3 border-t border-base-300 pt-3.5' => $inline])>
    <dl @class(['grid grid-cols-2 gap-3 border-y border-base-300 py-3' => ! $inline, 'flex gap-8' => $inline])>
        <div class="flex flex-col-reverse"><dt class="text-sm text-neutral">Activos</dt><dd class="font-mono text-2xl font-semibold leading-tight">{{ $summary['active'] }}</dd></div>
        <div class="flex flex-col-reverse"><dt class="text-sm text-neutral">Inactivos</dt><dd class="font-mono text-2xl font-semibold leading-tight">{{ $summary['inactive'] }}</dd></div>
    </dl>

    @if ($summary['added'] === null)
        <p class="flex items-center gap-2 rounded-field border border-info/30 bg-info/10 px-2.5 py-2 text-sm font-medium" data-testid="first-term"><x-icon name="o-information-circle" class="h-4 w-4 shrink-0 text-info" aria-hidden="true" />Primer periodo en Nexus</p>
    @else
        <div @class(['grid grid-cols-2 gap-2 text-sm' => ! $inline, 'flex gap-2 text-sm' => $inline]) data-testid="changes">
            @foreach ([['key' => 'added', 'sign' => '+', 'one' => 'alta', 'many' => 'altas', 'tone' => 'success'], ['key' => 'removed', 'sign' => '−', 'one' => 'baja', 'many' => 'bajas', 'tone' => 'error']] as $change)
                @php($list = $summary[$change['key']])
                <details @class(['relative z-10 rounded-field border px-2.5 py-2', 'border-success/40 bg-success/10' => $change['tone'] === 'success', 'border-error/40 bg-error/10' => $change['tone'] === 'error'])>
                    <summary class="flex cursor-pointer items-baseline gap-2 marker:content-none">
                        <b class="font-mono text-lg font-semibold">{{ $change['sign'] }}{{ count($list) }}</b>
                        <span>{{ count($list) === 1 ? $change['one'] : $change['many'] }}</span>
                    </summary>
                    @if ($list !== [])
                        <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs">
                            @foreach ($list as $record)
                                <li><span class="font-mono">{{ $record['code'] }}</span> {{ $record['name'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                </details>
            @endforeach
        </div>
    @endif

    <p class="flex min-h-6.5 items-center text-sm">
        @if ($summary['open_flags'] > 0)
            <x-core::status-badge tone="warning" icon="o-chat-bubble-left-ellipsis" :label="trans_choice('{1} 1 observación abierta|[2,*] :count observaciones abiertas', $summary['open_flags'])" />
        @else
            <span class="text-neutral">Sin observaciones abiertas</span>
        @endif
    </p>
</div>
