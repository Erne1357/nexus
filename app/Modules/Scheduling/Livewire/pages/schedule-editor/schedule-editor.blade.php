{{--
    Editor de horarios (tarea 3.5; maqueta A «Semana con bandeja», `.impeccable/mocks/horarios/a.html`; DESIGN.md,
    "Editor de horarios"):
    - Encabezado: «Horarios» con el periodo, y el resumen (campus, retícula, grupos, horas colocadas y las insignias de
      choques y avisos, que llevan al panel).
    - Selectores: Carrera, Retícula (la vigente con «Vigente», las viejas con «Prioridad» y sus grupos sin acomodar),
      Semestre, Docente o Salón según la vista y «Ver por». Un aviso por retícula vieja con grupos sin acomodar.
    - Desde 1200 px, a la izquierda y pegada arriba, la bandeja de grupos por materia y «Choques del semestre»; antes,
      arriba y abajo de la semana, plegables. A la derecha, la barra del grupo elegido (o del docente o salón) y la
      semana (scheduling::editor.week); bajo 768 px, la lista de horas de un día (scheduling::editor.day-list).
    - Los cajones de la sesión y del grupo, componentes aparte (scheduling::components.session-drawer y .group-drawer)
      que se abren con `$dispatchTo` sin volver a pintar la página.
    El teclado de la semana, el día del celular, «Ver» de un choque y el foco al cerrar un cajón son del componente
    Alpine `scheduleEditor` (resources/js/app.js). Esc quita la selección si no hay cajón ni menú abiertos.
--}}
<div class="space-y-4" x-data="scheduleEditor($wire.entangle('day'))"
    x-on:editor-placed.window="$nextTick(() => keep($event.detail.id))"
    x-on:keydown.escape.window="escape()"
    x-on:scroll.window.passive="placeTip()"
    x-on:resize.window="placeTip()"
    x-on:click.capture="remember($event)"
    x-on:open="drawerOpened()"
    x-on:close="drawerClosed()">
    <div>
        <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[1.625rem] leading-tight font-bold md:text-3xl">
            Horarios
            @if ($term)
                <x-core::status-badge tone="primary" :label="$term->name" />
            @endif
        </h1>
        @isset($plan)
            <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-neutral" data-testid="editor-facts">
                <span class="font-medium text-base-content">{{ $campus->name }}</span>
                <span>Retícula <span class="font-mono text-base-content">{{ $plan->code }}</span></span>
                <span>Grupos <span class="font-mono text-base-content">{{ count($groups) }}</span></span>
                <span>Horas colocadas <span class="font-mono text-base-content">{{ $placedHours }} de {{ $requiredHours }}</span></span>
                <a href="#choques" class="rounded-selector focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-testid="hard-count">
                    <x-core::status-badge :tone="$hardCount > 0 ? 'error' : 'success'" :label="$hardCount === 1 ? '1 choque' : $hardCount.' choques'" />
                </a>
                @if ($softCount > 0)
                    <a href="#choques" class="rounded-selector focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <x-core::status-badge tone="warning" icon="o-exclamation-triangle" :label="$softCount === 1 ? '1 aviso' : $softCount.' avisos'" />
                    </a>
                @endif
            </div>
        @endisset
    </div>

    @if ($career || $careers !== [])
        @include('scheduling::editor.controls')
    @endif

    @if ($message)
        <div class="flex flex-col items-center gap-2 rounded-box border border-dashed border-base-300 bg-base-100 px-5 py-10 text-center" data-testid="editor-empty">
            <x-icon name="o-calendar" class="h-6 w-6 text-neutral" aria-hidden="true" />
            <p class="max-w-prose text-neutral">{{ $message }}</p>
        </div>
    @else
        @foreach ($pending as $row)
            <div role="alert" class="alert alert-warning alert-soft flex flex-wrap items-center gap-x-3 gap-y-1 py-2" data-testid="priority-notice">
                <x-icon name="o-exclamation-triangle" class="h-5 w-5 shrink-0" aria-hidden="true" />
                <span><b class="font-medium text-base-content">Retícula <span class="font-mono">{{ $row['code'] }}</span>: {{ $row['unscheduled'] }} {{ $row['unscheduled'] === 1 ? 'grupo' : 'grupos' }} sin acomodar</b> · se acomodan primero</span>
                <x-button label="Ir" icon-right="o-arrow-right" class="btn-ghost btn-sm ms-auto underline" wire:click="choosePlan('{{ $row['id'] }}')" spinner="choosePlan" />
            </div>
        @endforeach

        <div class="grid items-start gap-4 desktop:grid-cols-[18.25rem_minmax(0,1fr)]">
            @if ($view === 'semestre')
                <div class="contents desktop:sticky desktop:top-[calc(var(--shell-top)+1rem)] desktop:order-1 desktop:flex desktop:max-h-[calc(100dvh-var(--shell-top)-2rem)] desktop:flex-col desktop:gap-3">
                    @include('scheduling::editor.tray')
                    @include('scheduling::editor.conflicts')
                </div>
            @endif

            <div @class(['order-2 grid min-w-0 content-start gap-2.5', 'desktop:col-span-2' => $view !== 'semestre'])>
                @include('scheduling::editor.bar')
                @include('scheduling::editor.week')
                @include('scheduling::editor.day-list')
            </div>
        </div>
    @endif

    @if ($term)
        <livewire:scheduling::components.session-drawer :term-id="$termId" :campus-id="$campusId" />
        <livewire:scheduling::components.group-drawer :term-id="$termId" :campus-id="$campusId" />
    @endif
</div>
