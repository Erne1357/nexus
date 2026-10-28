# DESIGN.md — Sistema de diseño de Nexus

> **Estado: borrador.** El equipo todavía no tiene un enfoque visual definido. Este archivo fija lo mínimo para que las pantallas de R1 sean consistentes y deja marcado lo que falta decidir. Se completa durante la fase 0 (tema inicial) y se revisa después del piloto.

## Principios

1. **Primero la tarea, después el adorno.** Las personas que usan Nexus (jefes, coordinadores, docentes) tienen poco tiempo y mucha información: tablas densas, filtros visibles, acciones claras.
2. **El estado siempre a la vista.** Choques, pendientes y bloqueos se muestran donde se producen, con el mismo color y el mismo icono en toda la aplicación.
3. **Nada oculto.** Si una acción no está permitida por rol o por estado, el control se muestra deshabilitado con el motivo, no desaparece (excepto por alcance: otro campus u otro departamento no se ven).
4. **Una sola forma de hacer cada cosa.** Un tipo de tabla, un tipo de formulario, un tipo de modal. Se toman de MaryUI y no se reinventan.

## Base técnica

- **MaryUI** sobre **daisyUI** y **Tailwind 4**. Los componentes se usan tal cual; la personalización se hace con tokens de tema, no con CSS por pantalla.
- **Tema daisyUI `nexus`** (claro, por omisión) y `nexus-dark` (oscuro, si el sistema lo prefiere) definidos en `resources/css/app.css`, sin los temas de daisyUI. El usuario los alterna con el `<x-theme-toggle>` de MaryUI de la topbar, que recuerda su elección en el navegador. Paleta y contraste en [Tema](#tema).
- **Tipografía:** Instrument Sans (ya cargada por Vite). Tamaños: base 14 px en tablas y 16 px en formularios.
- **Iconos:** los que trae MaryUI (Heroicons).

## Layout

- Sidebar fija a la izquierda con la navegación por rol; topbar con selector de **campus** y **periodo** activos, campana de notificaciones (cuando existan) y menú de usuario.
- Implementado en `resources/views/layouts/app.blade.php` con `<x-nav>` y `<x-main>` de MaryUI: la barra lateral es `core::components.navigation` (entradas que declara cada módulo, ver `docs/arquitectura/arquitectura.md`, "Navegación"); el selector, `core::components.context-switcher` (fijo si el usuario trabaja en un solo campus); el menú de usuario lleva "Cambiar contraseña" y "Cerrar sesión"; `<x-toast />` queda listo para que cualquier página use el trait `Mary\Traits\Toast`. Sin sesión, el layout muestra solo el contenido.
- Contenido con ancho completo; las tablas largas usan paginación y filtros persistentes por usuario.
- En móvil (solo consulta): sidebar colapsable; la captura (editor de horarios, asignación) se diseña para escritorio.

## Patrones de pantalla previstos

| Patrón | Dónde se usa | Decisión pendiente |
|---|---|---|
| Listado con filtros, tabla y acción principal | Todos los catálogos | Densidad de fila |
| Formulario en modal (crear y editar) | Catálogos | Cuándo usar página completa en lugar de modal |
| Asistente de pasos | Importación | **Decidido (tarea 1.2):** `x-steps` de MaryUI como indicador, con los pasos hechos en `primary`; el contenido de cada paso en una `x-card` debajo. Lo que corre en la cola se muestra en la misma tarjeta con `x-progress` (indeterminado si no se sabe el total) y su texto, y la URL guarda en qué va (`?lote=`) para retomarlo |
| Checklist con contadores | Validación de catálogos | Cómo mostrar diferencias contra el periodo anterior |
| Cuadrícula día x hora | Editor de horarios, disponibilidad docente, vista por salón | Interacción por clic o arrastrar; colores por carrera o por estado |
| Ranking con desglose | Asignación docente | Cómo presentar el desglose sin exponer evaluaciones a quien no debe |
| Tablero de estado | Choques, avance de validación, carga por docente | Jerarquía visual de duro vs blando |
| Consulta pública | Horarios publicados | Impresión y PDF |

## Semántica de colores

| Significado | Token daisyUI |
|---|---|
| Choque duro, bloqueo | `error` |
| Advertencia blanda, pendiente | `warning` |
| Validado, cerrado, publicado | `success` |
| Información, sugerencia del sistema | `info` |
| Acción principal | `primary` |

## Tema

**Paleta inicial, pendiente de validación institucional.** Parte del guinda y el gris del ITCJ; cuando haya paleta oficial se cambian solo los tokens de `resources/css/app.css` y esta tabla. Contraste WCAG 2.x calculado con la luminancia relativa de cada par (AA pide 4.5:1 en texto normal).

| Token | `nexus` (claro) | Contraste | `nexus-dark` (oscuro) | Contraste |
|---|---|---|---|---|
| `base-content` sobre `base-100` | `#1f2122` / `#ffffff` | 16.17 | `#e6e6e6` / `#1c1d1f` | 13.52 |
| `base-content` sobre `base-200` | `#1f2122` / `#f4f4f4` | 14.70 | `#e6e6e6` / `#161718` | 14.38 |
| `primary` / `primary-content` (guinda) | `#691c32` / `#ffffff` | 11.58 | `#e597aa` / `#2b0a13` | 8.08 |
| `primary` como texto sobre `base-100` | `#691c32` / `#ffffff` | 11.58 | `#e597aa` / `#1c1d1f` | 7.50 |
| `secondary` / `secondary-content` (gris) | `#5f6261` / `#ffffff` | 6.17 | `#a9abaa` / `#1c1d1f` | 7.30 |
| `accent` / `accent-content` (guinda suave) | `#f3e4e8` / `#691c32` | 9.42 | `#4a1a28` / `#f2b8c6` | 8.47 |
| `neutral` / `neutral-content` | `#3a3c3d` / `#f4f4f4` | 10.08 | `#2e3032` / `#e6e6e6` | 10.62 |
| `info` / `info-content` | `#0b62a4` / `#ffffff` | 6.37 | `#6cb8ef` / `#06233a` | 7.44 |
| `success` / `success-content` | `#1b7a3a` / `#ffffff` | 5.40 | `#6fcf8c` / `#06290f` | 8.24 |
| `warning` / `warning-content` | `#f0b400` / `#2b2100` | 8.51 | `#f5c84c` / `#2b2100` | 10.03 |
| `error` / `error-content` | `#c62828` / `#ffffff` | 5.62 | `#f48a8a` / `#2e0606` | 7.73 |

- `warning` (1.87 sobre blanco) y `accent` se usan como **fondo** con su `-content` (alertas, insignias), nunca como color de texto sobre `base-100`.
- El rojo de `error` y el guinda de `primary` son de la misma familia: los choques y bloqueos llevan siempre también su icono, no solo el color. Revisarlo al validar la paleta.

## Accesibilidad

Contraste AA, foco visible, tablas con encabezados, formularios con etiquetas y mensajes de error asociados. Los browser tests incluyen navegación por teclado en el editor de horarios.

## Pendientes

- Paleta institucional y logotipo: validar la paleta inicial de [Tema](#tema).
- Diseño del editor de horarios (prototipo en fase 3 antes de implementar).
- Formato de los PDFs publicados (igual al que hoy conocen los alumnos o nuevo).
- Guía de textos: tono, mayúsculas, nombres de botones.
