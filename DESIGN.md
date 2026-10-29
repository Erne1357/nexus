---
name: Nexus
description: Fuente de verdad académica del ITCJ; la credencial institucional con un menú por áreas que se despliega en su lugar.
colors:
  azul-espacio: "#092d48"
  rojo-tec: "#c2201e"
  platino: "#f3f4f7"
  gris-polvo: "#e6dedb"
  gris-tenue: "#666567"
  gris-sobre-polvo: "#59585a"
  blanco: "#ffffff"
  filete: "#e3e0de"
  banda-tenue: "#b4c3d1"
  info: "#2b5c8a"
  exito: "#1f6f4f"
  advertencia: "#8a5a00"
  error: "#b42318"
  oscuro-fondo: "#0a1826"
  oscuro-superficie: "#0f2133"
  oscuro-banda: "#071a2b"
  oscuro-seleccion: "#26384a"
  oscuro-filete: "#1f3346"
  oscuro-texto: "#e7ecf1"
  oscuro-tenue: "#9aa6b2"
  oscuro-accion: "#a9c7e3"
typography:
  titulo:
    fontFamily: "Atkinson Hyperlegible Next, ui-sans-serif, system-ui, sans-serif"
    fontSize: "30px"
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: "-0.015em"
  cuerpo:
    fontFamily: "Atkinson Hyperlegible Next, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.45
  formulario:
    fontFamily: "Atkinson Hyperlegible Next, ui-sans-serif, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.45
  etiqueta:
    fontFamily: "Atkinson Hyperlegible Next, ui-sans-serif, system-ui, sans-serif"
    fontSize: "11.5px"
    fontWeight: 500
    letterSpacing: "0.09em"
  clave:
    fontFamily: "Atkinson Hyperlegible Mono, ui-monospace, monospace"
    fontSize: "15px"
    fontWeight: 600
  palabra-de-inicio:
    fontFamily: "Atkinson Hyperlegible Next, ui-sans-serif, system-ui, sans-serif"
    fontSize: "112px"
    fontWeight: 200
    lineHeight: 0.95
    letterSpacing: "-0.045em"
rounded:
  insignia: "4px"
  campo: "6px"
  caja: "8px"
  tarjeta: "10px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  banda:
    backgroundColor: "{colors.azul-espacio}"
    textColor: "{colors.blanco}"
    height: "64px"
  liebre:
    backgroundColor: "{colors.rojo-tec}"
    textColor: "{colors.blanco}"
    width: "72px"
    height: "64px"
  boton-principal:
    backgroundColor: "{colors.azul-espacio}"
    textColor: "{colors.blanco}"
    rounded: "{rounded.campo}"
    height: "40px"
    padding: "0 16px"
  campo:
    backgroundColor: "{colors.blanco}"
    textColor: "{colors.azul-espacio}"
    rounded: "{rounded.campo}"
    height: "40px"
  menu-entrada-activa:
    backgroundColor: "{colors.gris-polvo}"
    textColor: "{colors.azul-espacio}"
    rounded: "{rounded.campo}"
  insignia-exito:
    backgroundColor: "#e6f3ec"
    textColor: "{colors.exito}"
    rounded: "{rounded.insignia}"
    height: "26px"
  insignia-neutra:
    backgroundColor: "#f1efee"
    textColor: "{colors.gris-sobre-polvo}"
    rounded: "{rounded.insignia}"
    height: "26px"
---

# Design System: Nexus

> **Norma.** Toda pantalla nueva sigue este archivo. Los tokens viven en `resources/css/app.css` (temas daisyUI `nexus` y `nexus-dark`) y este archivo los describe; si cambian, cambian los dos en el mismo PR. La decisión y sus alternativas están en [ADR-0015](docs/decisiones/0015-identidad-visual.md). La pantalla de referencia aprobada es [docs/diseno/pantalla-de-referencia.html](docs/diseno/pantalla-de-referencia.html): ábrela en el navegador y cambia el ancho de la ventana.

## Overview

**La credencial con áreas.** La sesión porta su credencial: arriba, una banda azul con la liebre del Tec, la ruta y la credencial de quien trabaja (nombre, rol, campus y periodo). A la izquierda, un menú por áreas que, al tocar la liebre, se despliega en su lugar y muestra todo el sistema. Las pantallas de trabajo son tablas densas y formularios: moderno, serio e institucional, sin adornos que estorben la tarea.

Nexus apunta a sustituir todo el SII (académico, servicios escolares, recursos humanos, recursos financieros), así que el diseño sirve igual a quien usa tres opciones que a quien usa decenas de módulos, en PC de oficina, laptop, proyector y celular ([PRODUCT.md](PRODUCT.md)).

**Principios**
1. **Primero la tarea, después el adorno.** Tablas densas, filtros visibles, acciones claras.
2. **El estado siempre a la vista.** Choques, pendientes y bloqueos se ven donde ocurren, con la misma insignia en toda la aplicación.
3. **Nada oculto por permiso.** Lo que el rol o el estado no permite se muestra deshabilitado con su motivo en un tooltip; solo lo de otro alcance (otro campus u otro departamento) no se muestra.
4. **Una sola forma de hacer cada cosa.** Un tipo de tabla, de formulario, de modal y de insignia, tomados de MaryUI con los tokens de este archivo.
5. **Sirve en cualquier pantalla,** del celular al proyector.

**Base técnica.** MaryUI sobre daisyUI y Tailwind 4. Los componentes se usan tal cual y la identidad entra por tokens; no hay CSS por pantalla (CLAUDE.md, "Convenciones"). Iconos: Heroicons de MaryUI, contorno de 1.5. El movimiento del menú usa Alpine, que ya viene con Livewire.

**Pendientes:** códigos oficiales de color (los de aquí son aproximaciones de los institucionales), inicios por rol con la palabra gigante y los mosaicos vivos, diseño del editor de horarios (prototipo en fase 3), formato de los PDF publicados y guía de textos (tono, mayúsculas, nombres de botones).

## Colors

Estrategia: **neutros con un color de marca que hace trabajos concretos.** El azul es la casa (banda, texto, acción principal); el rojo es la firma y aparece en muy pocos lugares.

### Primary
- **Azul espacio** `#092d48`: banda, texto (14.18:1 sobre blanco), títulos y acción principal (`btn-primary`).

### Secondary
- **Rojo Tec** `#c2201e`: **solo** el cuadro de la liebre, la franja de 3 px bajo la banda (y bajo el encabezado de la credencial) y el punto de «estás aquí» del menú. Token daisyUI `accent`.

### Neutral
- **Platino** `#f3f4f7`: fondo de la página (`base-200`).
- **Blanco** `#ffffff`: superficies (tablas, menú, modales, credencial) (`base-100`).
- **Gris polvo** `#e6dedb`: superficies de navegación y selección: la franja de iconos de área, la entrada activa del menú, el encabezado de tabla en su versión clara (`#f6f3f2`) (`secondary`).
- **Gris tenue** `#666567`: texto secundario sobre blanco o platino (`neutral`). Sobre gris polvo se usa **`#59585a`**, porque el gris tenue ahí da 4.4:1.
- **Filete** `#e3e0de`: líneas y bordes (`base-300`).

### Estados
| Significado | Token | Claro | Oscuro |
|---|---|---|---|
| Choque duro, bloqueo, error | `error` | `#b42318` / blanco · 6.57 | `#f48a8a` / `#2e0606` · 7.73 |
| Advertencia, pendiente | `warning` | `#8a5a00` / blanco · 5.93 | `#f5c84c` / `#2b2100` · 10.03 |
| Validado, cerrado, publicado | `success` | `#1f6f4f` / blanco · 6.10 | `#6fcf8c` / `#06290f` · 8.24 |
| Información, en revisión | `info` | `#2b5c8a` / blanco · 7.00 | `#8cbfee` / `#06233a` · 8.25 |

### Tema oscuro (`nexus-dark`)
Fondo `#0a1826`, superficies `#0f2133`, banda `#071a2b`, selección `#26384a`, filete `#1f3346`, texto `#e7ecf1` (13.74:1), texto secundario `#9aa6b2` (6.59:1), acción principal `#a9c7e3` con texto `#06192a` (10.14:1). La liebre y la franja conservan el rojo Tec. Se elige en la credencial (Claro u Oscuro) y por omisión sigue al sistema.

### Named Rules
- **El rojo es firma, no mensaje.** El rojo Tec nunca va en texto, insignias, botones ni fondos grandes. Así no se confunde con el rojo de error ni se lee como bandera.
- **El color nunca va solo.** Todo estado lleva icono y texto además del color.
- **Contraste medido.** Todo par de texto pasa WCAG AA (4.5:1) en claro y oscuro; los valores de arriba están medidos. Un token nuevo entra con su medición.

## Typography

**Atkinson Hyperlegible Next** para todo y **Atkinson Hyperlegible Mono** para claves, cifras, horarios y atajos de teclado. Las dos se diseñaron para leerse a distancia y en pantallas pobres, lo que sirve en el proyector. Reemplazan a Instrument Sans y se cargan con el plugin de fuentes de Vite.

### Hierarchy
| Uso | Tamaño y peso |
|---|---|
| Título de pantalla (`x-header`) | 30 px, 700, interlineado 1.15 (26 px en celular) |
| Texto de tabla y de la aplicación | 14 px, 400 |
| Formularios | 16 px |
| Etiquetas de encabezado de tabla y de campos de la credencial | 11.5 px, 500, mayúsculas, espaciado 0.09em |
| Claves (`C2`, `2A`, `B-12`) y cifras | Mono 15 px, 600, cifras tabulares |
| Palabra de inicio (solo en inicios por rol) | 112 px, 200, minúsculas, espaciado -0.045em |

### Named Rules
- **Ningún texto funcional por debajo de 11 px.**
- **Las claves y las cifras van en Mono,** para que las columnas se alineen. Mono no es adorno: solo datos, claves y atajos.

## Layout

### Estructura (shell)
- **Banda** de 64 px, fija arriba, en azul espacio:
  - **Izquierda:** la liebre en un cuadro rojo de 72 px, que es el botón del menú completo; luego «Nexus» y la ruta (área › pantalla).
  - **Derecha:** el buscador «Buscar o ir a… Ctrl K», que abre el menú con el cursor en su buscador, y **la credencial**.
  - Debajo, la **franja roja** de 3 px.
- **La credencial** es un botón con avatar, nombre y, debajo, «rol · campus · periodo». Se abre como tarjeta con:
  - encabezado azul y su franja roja;
  - selectores de **campus** y **periodo** (los de `core::components.context-switcher`, con `SetActiveContext`);
  - **tema** claro u oscuro;
  - **Cambiar contraseña** y **Cerrar sesión**.
- **Menú cerrado** de 288 px: franja de iconos de área (64 px, gris polvo) y las secciones del área actual (224 px, blanco). Solo aparecen las áreas con al menos una entrada visible para el usuario.
- **Contenido** a la derecha del menú, sobre platino, con un margen de 32 px. Llega hasta 1680 px de ancho; en monitores más anchos queda alineado al menú y el resto es fondo.

### Responsivo
| Ancho | Banda | Menú | Tabla |
|---|---|---|---|
| ≥ 1200 px | Completa; el buscador crece de 260 a 520 px | Iconos + secciones; abierto en 5 columnas (hasta 1480 px) | Todas las columnas |
| 768–1199 px | Sin el área en la ruta; credencial con avatar y nombre | Solo iconos (64 px); abierto en 3 columnas | Se ocultan las columnas descriptivas largas; las acciones secundarias quedan como icono con nombre accesible |
| < 768 px | 56 px: liebre, «Nexus», lupa y avatar | Oculto; la liebre lo baja como hoja con buscador y todas las áreas en una columna (renglones de 44 px) | Renglones apilados: clave, nombre y estado; luego el detalle; luego las acciones |

La captura pesada (editor de horarios, asignación) se diseña para escritorio, pero ninguna pantalla se rompe en celular. Se revisa sin desbordes a 390, 768, 1024, 1440, 1920 y 2560 px.

### Anatomía de una pantalla de catálogo
La forma de [Campus](app/Modules/Core/Livewire/pages/campuses/campuses.blade.php), que copian todos los catálogos:
1. **Encabezado** (`x-header`):
   - título y una línea que explica la pantalla;
   - a la derecha, búsqueda, filtro de estado y la **acción principal**, una sola y en `btn-primary`.
2. **Tabla** (`x-table` en una superficie blanca con filete):
   - la clave primero, en Mono;
   - el estado con su insignia;
   - las acciones a la derecha en `btn-ghost`, con su motivo en tooltip cuando están deshabilitadas;
   - paginación al pie, con el rango («Campus C1 a TNV · 1–5 de 5»).
3. **Formulario en modal** (`x-modal` + `x-form`) para crear y editar. Página completa solo si el formulario no cabe o tiene pasos.
4. **Avisos** con el `Toast` de MaryUI: título corto y el registro, escapado con `e()`.

### Cómo se hace una pantalla nueva
1. Copia la forma de Campus: el componente MFC, la Query, la Action y la Policy (skill `nexus-module`).
2. Arma la pantalla solo con componentes de MaryUI y clases de tokens:
   - `bg-base-100` y `bg-base-200`, `text-base-content`, `text-neutral`;
   - `btn-primary` y `btn-ghost`;
   - `badge-*` (ver "Insignias");
   - `font-mono` para claves.

   Nunca un color en hexadecimal ni CSS propio.
3. Declara su entrada de menú en `navigation()` del provider, **con su área** (ADR-0015).
4. Revisa la pantalla a 390, 768 y 1440 px, en claro y oscuro, antes de abrir el PR.

### Patrones de pantalla previstos
| Patrón | Dónde se usa | Estado |
|---|---|---|
| Listado con filtros, tabla y acción principal | Todos los catálogos | **Decidido:** la anatomía de arriba |
| Formulario en modal | Catálogos | **Decidido:** modal para crear y editar; página completa si no cabe o tiene pasos |
| Asistente de pasos | Importación | **Decidido (tarea 1.2):** `x-steps` de MaryUI como indicador, con los pasos hechos en `primary`; el contenido de cada paso en una `x-card` debajo. Lo que corre en la cola se muestra en la misma tarjeta con `x-progress` (indeterminado si no se sabe el total) y su texto, y la URL guarda en qué va (`?lote=`) para retomarlo |
| Inicio por rol | Inicio | Palabra gigante y mosaicos vivos (por diseñar) |
| Checklist con contadores | Validación de catálogos | Cómo mostrar diferencias contra el periodo anterior |
| Cuadrícula día x hora | Editor de horarios, disponibilidad docente, vista por salón | Interacción por clic o arrastrar; colores por carrera o por estado |
| Ranking con desglose | Asignación docente | Cómo presentar el desglose sin exponer evaluaciones a quien no debe |
| Tablero de estado | Choques, avance de validación, carga por docente | Jerarquía visual de duro vs blando |
| Consulta pública | Horarios publicados | Impresión y PDF |

## Elevation & Depth

Plano. La profundidad la dan las superficies (blanco sobre platino) y los filetes, no las sombras.

### Shadow Vocabulary
- **Flotante** `0 16px 40px rgba(9,45,72,.24), 0 2px 6px rgba(9,45,72,.12)`: solo lo que flota sobre el contenido (la tarjeta de la credencial, menús desplegables, modales).
- **Velo** `rgba(9,45,72,.35)` detrás del menú abierto y de los modales.

### Named Rules
- **Sin tarjetas con sombra para agrupar.** Una tabla o un formulario va en una superficie blanca con filete; nunca tarjetas dentro de tarjetas.

## Shapes

Radios contenidos: insignias 4 px, campos y botones 6 px, tablas y superficies 8 px, la tarjeta de la credencial 10 px. El avatar y el botón de la credencial son los únicos elementos redondos.

## Components

### Buttons
- **Principal** (`btn-primary`): azul espacio, texto blanco, 40 px de alto. Una por pantalla.
- **Secundaria** (`btn-ghost`): texto azul sin fondo; las acciones de fila.
- **Deshabilitada:** se muestra con su motivo en un tooltip («Solo el administrador da de alta campus.»), no desaparece.

### Insignias de estado
Rectangulares, de 26 px, con icono y texto, iguales en toda la aplicación:

| Estado | Clases | Icono |
|---|---|---|
| Activo, Validado | `badge badge-success badge-soft` | `o-check-circle` |
| Publicado | `badge badge-success` | `o-check-circle` |
| Inactivo | `badge badge-neutral badge-soft`; además la fila entera pasa a `text-neutral` y peso normal | `o-minus-circle` |
| Borrador | `badge badge-dash` | `o-pencil` |
| En revisión, en cola | `badge badge-info badge-soft` | `o-eye` / `o-clock` |
| Pendiente, Con advertencia | `badge badge-warning badge-soft` | `o-clock` / `o-exclamation-triangle` |
| Con error, Choque | `badge badge-error badge-soft` | `o-x-circle` |
| Periodo actual | `badge badge-primary` | — |

Los contadores (por ejemplo, «12 filas») van en `badge-neutral badge-soft` con cifra Mono.

### Inputs / Fields
Los de MaryUI: 40 px en barras de herramientas y 44 px en la credencial y en celular. Borde de filete, fondo blanco y foco visible. Las etiquetas van arriba, en español, con el error asociado debajo.

### Navigation
- **Entrada de menú:** icono y nombre. La activa lleva fondo gris polvo, peso 700 y el punto rojo. Las cifras de registros van a la derecha en Mono.
- **Áreas:** Académico, Servicios escolares, Recursos humanos, Recursos financieros, Datos. Cada una con su icono; el orden lo define el enum `NavigationArea` (ADR-0015).

### El menú que se transforma (firma)
Un solo menú, no dos:
- **Al abrir** (la liebre, Ctrl K o el buscador de la banda):
  1. el panel se despliega como cortina;
  2. el buscador entra de inmediato, con el cursor;
  3. los iconos de área viajan a los encabezados de sus columnas y las secciones del área actual a la suya;
  4. cuando cada icono aterriza, aparecen su nombre y sus secciones.
- **Al cerrar** (Esc, la liebre o un clic fuera): lo de las otras áreas se va primero, la cortina se recoge y todo regresa a su lugar.

Detalle en "Movimiento", abajo, y en la pantalla de referencia.

### Movimiento
| Momento | Curva | Duración |
|---|---|---|
| Abrir (menú, credencial) | `cubic-bezier(.3, 1.06, .5, 1)`, rebote apenas perceptible | 0.55 s; elementos nuevos 0.32 s en cascada |
| Cerrar y regresar | `cubic-bezier(.22, 1, .36, 1)`, sin rebote | 0.42 s; lo que se va, 0.11 s antes |
| Salto de la liebre al abrir | la misma de abrir | 0.5 s, 4 px |

- Solo se anima `transform`, `opacity` y `clip-path`.
- Con `prefers-reduced-motion`, todo cambia sin animar.
- La palabra gigante y los mosaicos vivos son para los inicios por rol, nunca para pantallas de captura.

### La liebre
- **Archivo:** la silueta oficial del Tec, `resources/images/liebre.png` (original) y `liebre-512.png` (recortada, 512 px).
- **Uso:** como máscara CSS, para que tome el color del lugar: blanca sobre el cuadro rojo.
- **Animación:** se mueve completa. Las orejas se animarían por separado solo con un SVG que las tenga como piezas aparte.
- Es la única marca: no hay otro logo en la banda.

## Do's and Don'ts

### Do:
- **Do** usa solo componentes de MaryUI y clases de tokens; la identidad entra por `resources/css/app.css`.
- **Do** pon la clave primero y en Mono, y el estado con su insignia e icono.
- **Do** deja visible y deshabilitado lo que el rol no permite, con el motivo.
- **Do** revisa cada pantalla a 390, 768 y 1440 px y en tema oscuro antes del PR.
- **Do** escribe en español y de tú («Elige el archivo que exportaste del SII»).

### Don't:
- **Don't** uses el rojo Tec fuera de la liebre, la franja y el punto de «estás aquí».
- **Don't** agrupes con tarjetas con sombra ni anides tarjetas.
- **Don't** escribas colores en hexadecimal ni CSS por pantalla.
- **Don't** dependas solo del color para un estado.
- **Don't** pongas texto funcional por debajo de 11 px.
- **Don't** agregues un segundo menú o un logo en la banda.
- **Don't** animes las pantallas de captura con la palabra gigante o los mosaicos.
