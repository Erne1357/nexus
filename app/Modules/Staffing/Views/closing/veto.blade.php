{{--
    Por qué no pasó la última acción sobre la carga (el veto de las guardas de HC-15, de Staffing o del diagrama), en
    palabras y sin ids de regla, como en la asignación. `$vetoTitle` y `$vetoReasons` son de ClosesLoads.
--}}
@if ($vetoReasons !== [])
    <div class="alert alert-error alert-soft grid-flow-row grid-cols-[auto_minmax(0,1fr)] items-start text-sm" role="alert" data-testid="veto-reasons">
        <x-icon name="o-x-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
        <div class="min-w-0">
            <p class="font-semibold">{{ $vetoTitle }}</p>
            <ul class="mt-1 list-disc space-y-0.5 ps-5">
                @foreach ($vetoReasons as $vetoReason)
                    <li>{{ $vetoReason }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
