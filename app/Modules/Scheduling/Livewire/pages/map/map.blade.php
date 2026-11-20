{{--
    El mapa del campus del Inicio (tarea 1.24.1). El visor 3D y su panel los arma resources/js/ubicatec con los datos de
    #ubicatec-data; wire:ignore evita que Livewire los vuelva a pintar. Estilos aislados bajo #ubicatec-root (ADR-0030).
--}}
<div id="ubicatec-root" wire:ignore data-base="{{ asset('ubicatec') }}/" data-initial="{{ $lugar }}" data-testid="campus-map" class="absolute inset-0">
    @assets
        @vite(['resources/css/ubicatec.css', 'resources/js/ubicatec/main.js'])
    @endassets
    <script type="application/json" id="ubicatec-data">@json($map)</script>
    <three-d-stage name="ubicatec-campus" background="#E9E4DC"></three-d-stage>
    <div id="labels"></div>
    <div id="tip"></div>
    <aside id="panel">
      <div class="ut-ph">
        <div class="ut-grab" id="grab"></div><div class="ut-brand"><img class="ut-mk" src="{{ asset('ubicatec/img/logo_itcj.webp') }}" alt="ITCJ"><div><h1>Mapa del campus</h1><p>{{ collect([$map['campus'], $map['term']['name'] ?? null])->filter()->implode(' · ') }}</p></div></div>
        <div class="ut-search" id="sbox">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
          <input id="q" placeholder="Salón, materia, grupo, docente, oficina…" autocomplete="off" aria-label="Buscar en el mapa">
          <span class="ut-kbd">/</span><button class="ut-clr" id="clr" aria-label="Limpiar búsqueda">✕</button>
        </div>
        <div class="ut-chips" id="chips"></div>
      </div>
      <div class="ut-pb" id="pb"></div>
    </aside>
    <section id="drawer">
      <div class="ut-dwh"><h2 id="dwt">Horario</h2><button class="ut-x" id="dwx" aria-label="Cerrar horario">✕</button></div>
      <div class="ut-legend" id="dwl"></div>
      <div class="ut-grid"><div class="ut-sched" id="sched"></div></div>
    </section>
    <div id="ctl">
      <div id="occbar"><span>Ocupación</span><select id="oday" aria-label="Día"></select><input id="ohour" type="range" min="7" max="20" step="1" aria-label="Hora"><span id="ohl" class="ut-mono">10:00</span><div class="ut-ramp"></div></div>
      <div class="ut-seg"><button id="v3" class="ut-on">3D</button><button id="v2">2D</button></div>
      <div class="ut-seg"><button id="vocc">Ocupación</button><button id="vnight">Noche</button><button id="vreset">Centrar</button></div>
    </div>
    <div id="floors"><span class="ut-fl-t" id="fl-name">Edificio</span><div class="ut-seg" id="fl-seg"></div><button class="ut-fl-x" id="fl-x" title="Cerrar planta" aria-label="Cerrar planta">✕</button></div>
    <div id="kindlg"></div>
    <div id="pickhint">Toca el mapa para fijar tu punto de partida</div>
    <div id="compass"><div class="ut-nd"></div><span class="ut-nl">N</span></div>
</div>
