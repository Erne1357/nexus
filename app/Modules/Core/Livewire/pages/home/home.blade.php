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
                </section>
            @endforeach
        </div>
    @endif
</div>
