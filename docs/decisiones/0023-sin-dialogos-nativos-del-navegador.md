# ADR-0023 — Sin diálogos nativos del navegador: modales para lo corto, drawers para lo largo

**Estado:** Aceptada · **Fecha:** 2026-11-10

## Contexto
Varias pantallas confirman con el diálogo del navegador. Casi siempre es la directiva `wire:confirm` de Livewire, que por dentro llama a `window.confirm()`; también hay dos `confirm()` en `resources/js/app.js`. Ese diálogo:
- no sigue la identidad de Nexus ni sus tokens (DESIGN.md);
- no deja dar formato ni explicar las consecuencias;
- en el celular se ve distinto en cada navegador;
- las pruebas de navegador lo tratan aparte.

En otros lugares ya se confirma con modales y drawers de MaryUI, así que hoy la aplicación tiene dos maneras de hacer lo mismo.

El usuario lo decidió el 10 de noviembre de 2026: en Nexus nunca se usan `confirm()`, `alert()` ni nada parecido del navegador. Lo pequeño va en un modal y lo que lleva mucha información en un drawer, que es su preferido.

## Decisión
1. **Prohibido** en código de la aplicación (Blade, Alpine y `resources/js`): `window.confirm()` y `confirm()`, `alert()`, `prompt()` y la directiva `wire:confirm`.
2. **Confirmaciones y avisos cortos** (una pregunta y una o dos líneas, como «¿Desactivar el salón 105?»): `x-modal` de MaryUI, a través de un solo componente de Core para confirmar.
   - El botón principal lleva el color de la acción (`btn-error` al borrar o desactivar) y siempre hay «Cancelar».
   - El foco entra en «Cancelar» y Esc cierra.
   - La acción se llama solo al confirmar.
3. **Mucha información** (una lista de lo que va a cambiar, el detalle de un registro o consecuencias que no caben en dos líneas): `x-drawer` de MaryUI. Es lo preferido cuando hay duda.
4. **Única excepción:** el aviso del navegador al cerrar o recargar la pestaña con cambios sin guardar (`beforeunload`), porque ningún sitio puede reemplazarlo. Al navegar dentro de Nexus, el mismo aviso va en un modal.
5. **No aplica a la consola:** `$this->confirm()` de los comandos de Artisan.

## Consecuencias
- Hoy hay 33 diálogos nativos en Core, Academic, Facilities, Faculty, CatalogValidation, Imports y Scheduling. Se reemplazan en el issue #374, que también agrega:
  - el componente de Core;
  - una prueba de arquitectura que falla si vuelve a aparecer alguno.
- Mientras #374 no entre, ninguna pantalla nueva agrega `wire:confirm` ni `confirm()`: confirma con un modal o un drawer.
- `DESIGN.md` y la skill `nexus-module` dejan de recomendar `wire:confirm` en el menú de renglón. `DESIGN.md` ya dice que se cambia; la skill se actualiza con #374.
- Las pruebas de navegador que aceptan el diálogo nativo pasan a pulsar el botón del modal.

## Alternativas descartadas
- **Seguir con `wire:confirm`:** es lo más corto de escribir, pero es justo lo que el usuario no quiere: no lleva la identidad de Nexus ni deja explicar.
- **Una librería de diálogos aparte** (SweetAlert y similares): una dependencia más para lo que MaryUI ya da con `x-modal` y `x-drawer`.
- **Todo en drawer:** para una pregunta de una línea, un panel lateral es demasiado. El usuario prefiere modal para lo pequeño.
