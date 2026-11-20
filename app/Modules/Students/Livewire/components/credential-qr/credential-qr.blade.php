{{--
    El QR del reverso (AC-20): el SVG del token de esta ventana de 30 s y la cuenta atrás. `wire:key` cambia con la ventana,
    así que al refrescar Alpine arranca de nuevo con los segundos que quedan. Si la pestaña estuvo oculta, al volver mide
    contra la hora en que debía vencer y pide el nuevo enseguida.
--}}
<div class="flex flex-col items-center gap-[1.6cqw]" data-testid="credential-qr-block">
    <div
        wire:key="credential-qr-{{ $qr['window'] }}"
        x-data="{
            total: 30,
            deadline: Date.now() + {{ $qr['seconds_left'] }} * 1000,
            left: {{ $qr['seconds_left'] }},
            timer: null,
            tick() {
                this.left = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000));
                if (this.left === 0) { clearInterval(this.timer); $wire.$refresh() }
            },
        }"
        x-init="timer = setInterval(() => tick(), 1000)"
        x-on:visibilitychange.document="tick()"
        class="flex flex-col items-center gap-[1.6cqw]"
    >
        <div role="img" aria-label="Código QR para verificar tu credencial; cambia cada 30 segundos" class="aspect-square w-[30cqw] rounded-[1.2cqw] bg-white p-[1.2cqw] [&>svg]:size-full" data-testid="credential-qr" data-qr-url="{{ $qr['url'] }}">{!! $qr['svg'] !!}</div>

        <div class="w-[30cqw]">
            <div class="h-[0.7cqw] overflow-hidden rounded-full bg-primary/15" aria-hidden="true">
                <div class="h-full rounded-full bg-accent transition-[width] duration-1000 ease-linear motion-reduce:transition-none" x-bind:style="`width: ${(left / total) * 100}%`" style="width: {{ round($qr['seconds_left'] / 30 * 100) }}%"></div>
            </div>
            <p class="mt-[0.9cqw] text-center text-[1.75cqw] leading-tight text-primary/80" aria-live="off">Se renueva en <span class="font-semibold tabular-nums" x-text="left">{{ $qr['seconds_left'] }}</span> s</p>
        </div>
    </div>
</div>
