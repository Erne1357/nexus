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
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($blocks as $block)
                <x-card :title="$block['title']" shadow>
                    {{ $block['description'] }}
                </x-card>
            @endforeach
        </div>
    @endif
</div>
