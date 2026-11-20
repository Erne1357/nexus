# ADR-0030 — three.js para el mapa del campus

**Estado:** Aceptada · **Fecha:** 2026-11-20 · Tarea 1.24.1 (#721) · Rama padre `riel/mapa-demo`

## Contexto
El usuario entregó el 20 de noviembre de 2026 un mapa del Campus I (UbicaTec) para que sea el Inicio de Nexus de todos los puestos, dentro de la app y solo con sesión. El mapa dibuja los edificios, sus plantas y los espacios en 3D y 2D desde GeoJSON reales, con buscador, ficha de cada lugar, horario y ocupación por hora. Está escrito sobre three.js (WebGL) con módulos de JavaScript propios; Livewire no lo vuelve a pintar (`wire:ignore`).

## Decisión
- Se agrega `three` **0.184.0** exacta como dependencia de ejecución del front (`package.json`, `dependencies`), compilada por Vite. No hay CDN: el bundle sale del mismo servidor.
- El mapa tiene sus propias entradas de Vite (`resources/js/ubicatec/main.js` y `resources/css/ubicatec.css`), que solo carga la página del mapa; las demás páginas no descargan three.js.
- `build.target` pasa a `es2022` porque el mapa usa `await` de nivel superior e importaciones dinámicas de three.js. Los navegadores con los que se usa Nexus (Chrome, Edge, Firefox y Safari recientes) lo soportan.
- Los datos del mapa (edificios, salones, departamentos, docentes, materias, grupos y clases del periodo en curso) se embeben en la página como JSON desde una Query de Scheduling; no hay API para la UI (CLAUDE.md §3).

## Consecuencias
- El bundle del mapa pesa lo que three.js (~600 KB sin comprimir) y solo se baja en el Inicio.
- Los estilos del mapa se aíslan bajo `#ubicatec-root` y sus clases llevan prefijo `ut-`, para no chocar con Tailwind ni con daisyUI.
- Actualizar three.js es un cambio de versión exacta con su propia revisión visual del mapa.

## Alternativas descartadas
- **Mapa 2D con SVG o Leaflet:** el entregado ya resuelve plantas, 3D y ocupación; rehacerlo en 2D perdería lo que el usuario pidió como inicio.
- **Cargar three.js de un CDN:** depende de un tercero en cada visita y del acceso a internet del servidor del Tec.
- **Página aparte a pantalla completa:** el usuario eligió el mapa dentro de la app, con la banda y el menú (D5, 20 de noviembre de 2026).
