<div>
    <x-header :title="'Hola, '.$name" subtitle="Las pantallas de tu rol aparecen en el menú conforme se habilitan." separator />

    @if ($blocks === [])
        <x-alert
            title="Todavía no tienes un rol vigente en Nexus."
            description="Pide al equipo de sistemas que te asigne el tuyo."
            icon="o-information-circle"
            class="alert-info"
        />
    @else
        @if ($calendar !== null)
            <div class="mb-6">
                @include('core::terms.mini-timeline', ['timeline' => $calendar['timeline'], 'caption' => $calendar['caption'], 'termUrl' => $calendar['termUrl']])
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($blocks as $block)
                <section class="rounded-box border border-base-300 bg-base-100 p-4" data-testid="role-block">
                    <h2 class="text-lg font-bold leading-tight">{{ $block['title'] }}</h2>
                    <p class="mt-1.5">{{ $block['description'] }}</p>

                    {{-- D12: lo suyo del docente, su acceso. --}}
                    @if ($block['account'] !== null)
                        <div class="mt-4 border-t border-base-300 pt-3" data-testid="role-block-account">
                            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Tu acceso</h3>
                            <dl class="mt-2 grid gap-2">
                                <div>
                                    <dt class="text-sm text-neutral">Usuario</dt>
                                    <dd class="font-mono font-semibold">{{ $block['account']['username'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-neutral">Correo institucional</dt>
                                    <dd @class(['[overflow-wrap:anywhere]', 'text-neutral' => $block['account']['email'] === null])>{{ $block['account']['email'] ?? 'Sin correo institucional' }}</dd>
                                </div>
                            </dl>
                            <x-button label="Cambiar contraseña" :link="route('user-password.edit')" icon="o-key" class="btn-outline btn-sm mt-3" no-wire-navigate />
                        </div>
                    @endif

                    @foreach ($block['links'] as $link)
                        <x-button :label="$link['label']" :link="$link['url']" :icon="$link['icon']" icon-right="o-arrow-right" class="btn-outline btn-sm mt-3" />
                    @endforeach
                </section>
            @endforeach
        </div>
    @endif
</div>
