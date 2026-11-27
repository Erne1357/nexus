{{--
    El panel «Abrir el periodo» de una modalidad (core::pages.term; U4 opción A), dentro del x-drawer: «Abrir {periodo} en
    {modalidad}» (o «Completar la apertura …» si ya estaba abierto) con «Revisa lo que se crea antes de abrir.»; «Se
    crea», una tarjeta por lo que dicen los módulos de la derecha (hoy, las cargas en borrador, con sus departamentos
    como chips); y «Con qué trabaja»: el perfil de flujo, los campus y el calendario. Confirmar llama a `openIn` o
    `completeIn`; `close()` es del x-drawer. Espera `$term`, `$openModality` y `$openPreview` (TermQuery::openingPreview).
--}}
@php
    $completing = $openPreview['completing'];
    $verb = $completing ? 'Completar la apertura de' : 'Abrir';
    $campuses = $openPreview['campuses'];
@endphp

<div class="flex h-dvh flex-col" data-testid="open-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="open-drawer-title" class="text-xl font-bold leading-tight">{{ $verb }} {{ $term->name }} en {{ $openModality->name }}</h2>
            <p class="mt-0.5 text-sm text-neutral">{{ $completing ? 'Se crea solo lo que falta.' : 'Revisa lo que se crea antes de abrir.' }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:size-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 overflow-y-auto px-5 py-4">
        <section class="space-y-3" aria-labelledby="open-creates">
            <h3 id="open-creates" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Se crea</h3>

            @forelse ($openPreview['items'] as $item)
                <div class="flex gap-4 rounded-box border border-base-300 bg-base-100 p-4" data-testid="open-item">
                    <span class="w-10 shrink-0 text-end font-mono text-2xl font-semibold leading-none tabular-nums">{{ $item['count'] }}</span>
                    <div class="min-w-0 space-y-2">
                        <p>{{ $item['text'] }}</p>
                        @if ($item['chips'] !== [])
                            <ul class="flex flex-wrap gap-1.5" aria-label="Departamentos">
                                @foreach ($item['chips'] as $chip)
                                    <li class="rounded border border-base-300 bg-base-100 px-1.5 py-0.5 font-mono text-[13px] font-semibold">{{ $chip }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @empty
                <p class="rounded-box border border-base-300 px-4 py-3 text-neutral">Abrir solo registra que el periodo se abrió en la modalidad.</p>
            @endforelse
        </section>

        <section class="space-y-3" aria-labelledby="open-works">
            <h3 id="open-works" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Con qué trabaja</h3>

            <dl class="grid grid-cols-[6rem_minmax(0,1fr)] gap-x-4 gap-y-3">
                <dt class="text-neutral">Perfil</dt>
                <dd data-testid="open-profile">
                    @if ($openPreview['profile'] === null)
                        <x-core::status-badge tone="error" label="Perfil inválido" />
                    @else
                        {{ $openPreview['profile'] }}
                    @endif
                </dd>

                <dt class="text-neutral">Campus</dt>
                <dd data-testid="open-campuses">
                    @if ($campuses === [])
                        Ningún campus activo.
                    @else
                        {{ \Illuminate\Support\Arr::join($campuses, ', ', ' y ') }}.
                    @endif
                </dd>

                <dt class="text-neutral">Calendario</dt>
                <dd data-testid="open-calendar">
                    @if ($openPreview['ownWindows'] === 0)
                        Usa el calendario general del periodo. En cada etapa puedes darle una ventana propia.
                    @else
                        Tiene ventana propia en {{ $openPreview['ownWindows'] }} {{ $openPreview['ownWindows'] === 1 ? 'etapa' : 'etapas' }}; en las demás usa la general.
                    @endif
                </dd>
            </dl>
        </section>
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-base-300 bg-base-100 py-3 ps-5 pe-4">
        <p class="text-sm text-neutral">Queda en la bitácora con tu nombre.</p>
        <div class="ms-auto flex gap-2 max-md:w-full">
            <x-button label="Cancelar" class="max-md:h-11 max-md:flex-1" @click="close()" />
            <x-button
                :label="($completing ? 'Completar apertura' : 'Abrir en '.$openModality->name)"
                icon="o-check"
                class="btn-primary max-md:h-11 max-md:flex-1"
                wire:click="{{ $completing ? 'completeIn' : 'openIn' }}('{{ $openModality->id }}')"
                spinner="{{ $completing ? 'completeIn' : 'openIn' }}('{{ $openModality->id }}')"
                data-testid="confirm-open"
            />
        </div>
    </footer>
</div>
