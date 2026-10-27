# DESIGN.md — Sistema de diseño de Nexus

> **Estado: borrador.** El equipo todavía no tiene un enfoque visual definido. Este archivo fija lo mínimo para que las pantallas de R1 sean consistentes y deja marcado lo que falta decidir. Se completa durante la fase 0 (tema inicial) y se revisa después del piloto.

## Principios

1. **Primero la tarea, después el adorno.** Las personas que usan Nexus (jefes, coordinadores, docentes) tienen poco tiempo y mucha información: tablas densas, filtros visibles, acciones claras.
2. **El estado siempre a la vista.** Choques, pendientes y bloqueos se muestran donde se producen, con el mismo color y el mismo icono en toda la aplicación.
3. **Nada oculto.** Si una acción no está permitida por rol o por estado, el control se muestra deshabilitado con el motivo, no desaparece (excepto por alcance: otro campus u otro departamento no se ven).
4. **Una sola forma de hacer cada cosa.** Un tipo de tabla, un tipo de formulario, un tipo de modal. Se toman de MaryUI y no se reinventan.

## Base técnica

- **MaryUI** sobre **daisyUI** y **Tailwind 4**. Los componentes se usan tal cual; la personalización se hace con tokens de tema, no con CSS por pantalla.
- **Tema daisyUI `nexus`** (claro) y `nexus-dark` (oscuro) definidos en `resources/css/app.css`. Pendiente: paleta institucional del ITCJ (guinda y gris del folleto como punto de partida) y verificación de contraste AA.
- **Tipografía:** Instrument Sans (ya cargada por Vite). Tamaños: base 14 px en tablas y 16 px en formularios.
- **Iconos:** los que trae MaryUI (Heroicons).

## Layout

- Sidebar fija a la izquierda con la navegación por rol; topbar con selector de **campus** y **periodo** activos, campana de notificaciones y menú de usuario.
- Contenido con ancho completo; las tablas largas usan paginación y filtros persistentes por usuario.
- En móvil (solo consulta): sidebar colapsable; la captura (editor de horarios, asignación) se diseña para escritorio.

## Patrones de pantalla previstos

| Patrón | Dónde se usa | Decisión pendiente |
|---|---|---|
| Listado con filtros, tabla y acción principal | Todos los catálogos | Densidad de fila |
| Formulario en modal (crear y editar) | Catálogos | Cuándo usar página completa en lugar de modal |
| Asistente de pasos | Importación | Indicador de progreso |
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

## Accesibilidad

Contraste AA, foco visible, tablas con encabezados, formularios con etiquetas y mensajes de error asociados. Los browser tests incluyen navegación por teclado en el editor de horarios.

## Pendientes

- Paleta institucional y logotipo.
- Diseño del editor de horarios (prototipo en fase 3 antes de implementar).
- Formato de los PDFs publicados (igual al que hoy conocen los alumnos o nuevo).
- Guía de textos: tono, mayúsculas, nombres de botones.
