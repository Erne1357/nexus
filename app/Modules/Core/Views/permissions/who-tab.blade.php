{{--
    Pestaña «¿Quién puede…?» (core::pages.permissions): un buscador de permisos por etiqueta o clave; a la izquierda la
    lista (360 px desde 1200 px; antes, una caja baja que se desplaza) y a la derecha el elegido (`?permiso=`): su
    etiqueta, su clave, su grupo, cómo se acota, a qué unidades aplica (AZ-02, CF-S1), y si es candado (CF-03) o
    excepción del admin; la tabla de los puestos que lo tienen, por qué (Todo, Fábrica, otro rol o Suelto) y cuántas
    personas; y los que no lo tienen, con el motivo de las reglas (CF-04) o «Dar» como suelto, avisando si le valdría en
    todo el Tec (15 de noviembre de 2026). Espera `$who`.
--}}
<div>
    <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2">
        <div class="w-full md:w-[440px]">
            <x-input wire:model.live.debounce.300ms="whoSearch" placeholder="Busca un permiso o su clave" aria-label="Buscar permiso" icon="o-magnifying-glass" clearable />
        </div>
        <span class="text-sm text-neutral">{{ number_format($who['total']) }} {{ $who['total'] === 1 ? 'permiso' : 'permisos' }}</span>
    </div>

    <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-5 desktop:grid-cols-[360px_minmax(0,1fr)]">
        <nav aria-label="Permisos" class="max-h-72 overflow-y-auto rounded-box border border-base-300 bg-base-100 desktop:sticky desktop:top-[calc(var(--shell-top)+1rem)] desktop:max-h-[calc(100vh-var(--shell-top)-2rem)]">
            @forelse ($who['list'] as $option)
                @php($chosen = $who['selected'] !== null && $who['selected']['key'] === $option['key'])
                <button
                    type="button"
                    wire:key="who-option-{{ $option['key'] }}"
                    wire:click="$set('permissionKey', '{{ $option['key'] }}')"
                    data-testid="who-option"
                    data-key="{{ $option['key'] }}"
                    @if ($chosen) aria-current="true" @endif
                    @class([
                        'grid w-full gap-px border-t border-base-300 px-3.5 py-2 text-start first:border-t-0',
                        'hover:bg-base-200' => ! $chosen,
                        'bg-secondary shadow-[inset_3px_0_0_var(--color-primary)]' => $chosen,
                    ])
                >
                    <span>{{ $option['label'] }}</span>
                    <span class="break-all font-mono text-[12.5px] text-neutral">{{ $option['key'] }}</span>
                </button>
            @empty
                <p class="px-4 py-4 text-sm text-neutral">Ningún permiso coincide.</p>
            @endforelse
        </nav>

        @if ($who['selected'] !== null)
            @php($selected = $who['selected'])
            <section class="min-w-0 space-y-4 rounded-box border border-base-300 bg-base-100 p-4 md:p-5" aria-labelledby="who-title" data-testid="who-result">
                <div class="grid gap-0.5 rounded-box border border-base-300 bg-secondary/40 px-3.5 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="who-title" class="font-bold">{{ $selected['label'] }}</h2>
                        @if ($selected['admin_only'])
                            <x-core::status-badge tone="neutral" icon="o-lock-closed" label="Solo del admin" />
                        @endif
                        @if ($selected['admin_denied'])
                            <x-core::status-badge tone="neutral" icon="o-minus-circle" label="Excepción del admin" />
                        @endif
                    </div>
                    <span class="break-all font-mono text-[12.5px] text-neutral">{{ $selected['key'] }}</span>
                    <span class="text-sm text-neutral">{{ $selected['group_label'] }} · {{ $selected['scope_label'] }}@if ($selected['applies_label'] !== null) · {{ $selected['applies_label'] }}@endif</span>
                </div>

                <div class="overflow-x-auto rounded-box border border-base-300">
                    <table class="table [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40">
                        <thead>
                            <tr>
                                <th>Puesto</th>
                                <th>Por qué</th>
                                <th class="text-end">Personas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($selected['has'] as $holder)
                                <tr wire:key="who-has-{{ $holder['value'] }}" data-testid="who-has-{{ $holder['value'] }}">
                                    <td class="font-semibold">{{ $holder['label'] }}</td>
                                    <td>
                                        <span class="[&>span]:justify-start">@include('core::permissions.chips', ['chips' => $holder['chips']])</span>
                                    </td>
                                    <td class="text-end font-mono text-[15px] tabular-nums">{{ number_format($holder['holders']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-sm text-neutral">{{ number_format($selected['people']) }} {{ $selected['people'] === 1 ? 'persona lo tiene' : 'personas lo tienen' }} hoy.</p>

                @if ($selected['missing'] !== [])
                    <section class="space-y-2" aria-labelledby="who-missing-title">
                        <h3 id="who-missing-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">No lo tienen</h3>
                        <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300">
                            @foreach ($selected['missing'] as $missing)
                                <li wire:key="who-missing-{{ $missing['value'] }}" data-testid="who-missing-{{ $missing['value'] }}" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-2.5">
                                    <span class="font-semibold">{{ $missing['label'] }}</span>
                                    @if ($missing['problem'] !== null)
                                        <span class="flex basis-full items-start gap-1.5 text-[12.5px] text-neutral">
                                            <x-icon :name="$selected['admin_only'] ? 'o-lock-closed' : 'o-no-symbol'" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            <span>{{ $missing['problem'] }}</span>
                                        </span>
                                    @else
                                        @if ($missing['wide'])
                                            <span class="order-last flex basis-full items-start gap-1.5 text-[12.5px] text-warning" data-testid="wide-notice">
                                                <x-icon name="o-globe-alt" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                <span>Valdrá en todo el Tec.</span>
                                            </span>
                                        @endif
                                        <x-button
                                            label="Dar"
                                            icon="o-plus"
                                            class="btn-ghost btn-sm max-md:h-11"
                                            wire:click="grantFromWho('{{ $missing['value'] }}', '{{ $selected['key'] }}')"
                                            x-confirm="{{ $missing['label'] }} lo recibe como permiso suelto desde su siguiente acción.{{ $missing['wide'] ? ' Valdrá en todo el Tec.' : '' }}"
                                            data-confirm-title="¿Dar «{{ $selected['label'] }}» a {{ $missing['label'] }}?"
                                            data-confirm-button="Dar permiso"
                                            data-confirm-tone="warning"
                                            spinner="grantFromWho('{{ $missing['value'] }}', '{{ $selected['key'] }}')"
                                            :disabled="! $canManage"
                                            aria-label="Dar «{{ $selected['label'] }}» a {{ $missing['label'] }}"
                                        />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </section>
        @endif
    </div>
</div>
