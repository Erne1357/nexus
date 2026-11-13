{{--
    Asignación docente (tarea 3.9; maquetas C «Por materia» y A «Dos paneles» de .impeccable/mocks/staffing/jefe/; el
    alcance, maqueta U2, opción A): encabezado con el departamento, la modalidad, el periodo y la carga con su avance, y
    el filtro de campus; el aviso del estado de la carga; el renglón de la vista y los filtros, y la vista elegida. Todo lo que se pinta viene de AssignmentBoardQuery y
    GroupRankingQuery. Mientras algún grupo a la vista espera su ranking, la página consulta cada 5 s.

    Alpine solo mueve la vista y el foco: lleva la página al grupo de `?grupo=` (`data-scroll-to`) o al que pide
    `assign-scroll-to` («Siguiente sin docente», que además le pone el foco) y lo marca un momento con un anillo; si
    queda a más de dos pantallas salta sin animación. Pone el foco en el motivo cuando se abre (`assign-reason-opened`)
    y en el título del grupo elegido de los dos paneles (`assign-group-opened`), y desplaza la lista de grupos hasta el
    elegido si no se ve. Lo pendiente se intenta otra vez después de cada repintado.
--}}
<div class="space-y-5" data-testid="assign-page"
    @if ($board !== null && $polling) wire:poll.5s @endif
    @if ($scrollTo) data-scroll-to="{{ $scrollTo }}" @endif
    x-data="{
        target: null,
        flush() {
            const target = this.target
            if (! target) { return }
            const smooth = ! window.matchMedia('(prefers-reduced-motion: reduce)').matches
            if (target.scroll) {
                const group = this.$root.querySelector(`[data-group='${target.scroll}']`)
                if (! group) { return }
                this.target = null
                const far = Math.abs(group.getBoundingClientRect().top) > window.innerHeight * 2
                group.scrollIntoView({ block: 'start', behavior: smooth && ! far ? 'smooth' : 'auto' })
                if (target.focus) { group.focus({ preventScroll: true }) }
                group.classList.add('ring-2', 'ring-inset', 'ring-primary')
                setTimeout(() => group.classList.remove('ring-2', 'ring-inset', 'ring-primary'), 1600)
            } else if (target.reason) {
                const input = this.$root.querySelector('[data-testid=reason-input]')
                if (! input) { return }
                this.target = null
                input.focus({ preventScroll: true })
                input.closest('[data-testid=reason-box]')?.scrollIntoView({ block: 'nearest', behavior: smooth ? 'smooth' : 'auto' })
            } else if (target.title) {
                const title = this.$root.querySelector('[data-testid=group-title]')
                if (! title) { return }
                this.target = null
                title.focus({ preventScroll: window.innerWidth >= 1024 })
                this.revealSelected()
            }
        },
        revealSelected() {
            const list = this.$root.querySelector('[data-group-scroll]')
            const item = list?.querySelector('[aria-current=true]')
            if (! item || list.scrollHeight <= list.clientHeight) { return }
            const box = list.getBoundingClientRect()
            const row = item.getBoundingClientRect()
            if (row.top < box.top || row.bottom > box.bottom) { list.scrollTop += row.top - box.top - (box.height - row.height) / 2 }
        },
        init() {
            Livewire.hook('morphed', ({ component }) => { if (component.id === this.$wire.id) { this.flush() } })
            if (this.$root.dataset.scrollTo) { this.target = { scroll: this.$root.dataset.scrollTo }; this.$nextTick(() => this.flush()) }
            this.$nextTick(() => this.revealSelected())
        },
    }"
    x-on:assign-scroll-to.window="target = { scroll: $event.detail.id, focus: $event.detail.focus ?? false }; $nextTick(() => flush())"
    x-on:assign-reason-opened.window="target = { reason: true }; $nextTick(() => flush())"
    x-on:assign-group-opened.window="target = { title: true }; $nextTick(() => flush())">
    @if ($board === null)
        <h1 class="text-2xl font-bold leading-tight md:text-3xl">Asignación docente</h1>
        <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality?->code ?? $modalidad" class="w-full md:w-56" />
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="assign-empty">{{ $message }}</div>
    @else
        @include('staffing::assign.header')
        @include('staffing::assign.state')
        @include('staffing::assign.toolbar')

        @if ($view === 'paneles')
            @include('staffing::assign.panels')
        @else
            @include('staffing::assign.by-subject')
        @endif
    @endif
</div>
