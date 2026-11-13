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
  compacto:
    fontFamily: "Atkinson Hyperlegible Next, ui-sans-serif, system-ui, sans-serif"
    fontSize: "12.5px"
    fontWeight: 400
    lineHeight: 1.35
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

**La credencial con áreas.** La sesión porta su credencial: arriba, una banda azul con la liebre del Tec, la ruta y la credencial de quien trabaja (nombre, rol y periodo). A la izquierda, un menú por áreas que, al tocar la liebre, se despliega en su lugar y muestra todo el sistema. Las pantallas de trabajo son tablas densas y formularios: moderno, serio e institucional, sin adornos que estorben la tarea.

Nexus apunta a sustituir todo el SII (académico, servicios escolares, recursos humanos, recursos financieros), así que el diseño sirve igual a quien usa tres opciones que a quien usa decenas de módulos, en PC de oficina, laptop, proyector y celular ([PRODUCT.md](PRODUCT.md)).

**Principios**
1. **Primero la tarea, después el adorno.** Tablas densas, filtros visibles, acciones claras.
2. **El estado siempre a la vista.** Choques, pendientes y bloqueos se ven donde ocurren, con la misma insignia en toda la aplicación.
3. **Nada oculto por permiso.** Lo que el rol o el estado no permite se muestra deshabilitado con su motivo en un tooltip; solo lo de otro alcance (otro campus u otro departamento) no se muestra.
4. **Una sola forma de hacer cada cosa.** Un tipo de tabla, de formulario, de modal y de insignia, tomados de MaryUI con los tokens de este archivo. Nunca los diálogos del navegador (`confirm()`, `alert()`, `wire:confirm`): para una pregunta o un aviso corto, un `x-modal`; para lo que lleva mucha información, un `x-drawer`, que es el preferido (ADR-0023, decisión del usuario del 10 de noviembre de 2026). Cómo se confirma, en «Confirmar».
5. **Sirve en cualquier pantalla,** del celular al proyector.

**Base técnica.** MaryUI sobre daisyUI y Tailwind 4. Los componentes se usan tal cual y la identidad entra por tokens; no hay CSS por pantalla (CLAUDE.md, "Convenciones"). Iconos: Heroicons de MaryUI, contorno de 1.5. Su tamaño va con `w-*` y `h-*`, nunca con `size-*`: MaryUI les pone `w-5 h-5` si la clase no trae un `w-` o un `h-`, y eso le gana a `size-*` (lo revisa `tests/Arch/BladeIconsTest.php`). El movimiento del menú usa Alpine, que ya viene con Livewire.

**Pendientes:** códigos oficiales de color (los de aquí son aproximaciones de los institucionales), inicios por rol con la palabra gigante y los mosaicos vivos y guía de textos (tono, mayúsculas, nombres de botones).

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

**Horas:** sin cero a la izquierda en todo texto para personas: «7:00», «7:00–9:00», «el lunes de 7:00 a 9:00», en pantallas, mensajes del validador y de las Actions, bitácora y avisos (`Core\Support\WeekTime::clock()`, o `Minutes::label()` en Scheduling). Los datos (`related`, valores de formularios, llaves) siguen en `HH:MM`. Donde las horas forman columna van en Mono con cifras tabulares, para que «7:00» y «10:00» no bailen.

### Hierarchy
| Uso | Tamaño y peso |
|---|---|
| Título de pantalla (`x-header`) | 30 px, 700, interlineado 1.15 (26 px en celular) |
| Texto de tabla y de la aplicación | 14 px, 400 |
| Formularios | 16 px |
| Compacto: el dato secundario bajo un nombre (carrera · semestre · horario), las cifras de una ficha densa, el filtro segmentado compacto (`text-[12.5px]`, también en Mono) | 12.5 px, 400 |
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
- **La credencial** es un botón con avatar (las iniciales), nombre y, debajo, «rol · periodo»: el perfil activo, con su nombre para quien lo ocupa. No lleva ni elige campus (decidido por el usuario el 12 de noviembre de 2026, #415); cada pantalla que lo necesita lo elige en su URL con `x-core::campus-filter` (supuesto por confirmar; ver «Filtro segmentado»). Se abre como tarjeta, con el foco dentro (Esc, un clic fuera o salir de ella con Tab la cierran), con:
  - encabezado azul y su franja roja;
  - «Actuando como» (con más de un puesto) y el selector de **periodo** (los de `core::components.context-switcher`, con `SwitchRole` y `SetActiveContext`);
  - **tema** claro u oscuro;
  - **Cambiar contraseña** y **Cerrar sesión**.
- **Menú cerrado** de 288 px: franja de iconos de área (64 px, gris polvo) y las secciones del área actual (224 px, blanco). Solo aparecen las áreas con al menos una entrada visible para el usuario.
- **Contenido** a la derecha del menú, sobre platino, con un margen de 32 px. Llega hasta 1680 px de ancho; en monitores más anchos queda alineado al menú y el resto es fondo.
- **Dónde vive:** `resources/views/layouts/app.blade.php` con `core::components.band` (banda y credencial) y `core::components.navigation` (menú); el detalle está en `docs/arquitectura/arquitectura.md`, "Layout (shell)".

### Responsivo
| Ancho | Banda | Menú | Tabla |
|---|---|---|---|
| ≥ 1200 px (`desktop:`) | Completa; el buscador crece de 260 a 520 px | Iconos + secciones; abierto en 5 columnas (hasta 1480 px) | Todas las columnas |
| 768–1199 px (`max-desktop:`) | Sin el área en la ruta; credencial con avatar y nombre | Solo iconos (64 px); abierto en 3 columnas, y las áreas de la cuarta en adelante debajo de la más larga | Se ocultan las columnas descriptivas largas; las acciones secundarias quedan como icono con nombre accesible |
| < 768 px (`max-md:`) | 56 px: liebre, «Nexus», lupa y avatar | Oculto; la liebre lo baja como hoja con buscador y todas las áreas en una columna (renglones de 44 px) | Renglones apilados: clave, nombre y estado; luego el detalle; luego las acciones |

La captura pesada (editor de horarios, asignación) se diseña para escritorio, pero ninguna pantalla se rompe en celular. Se revisa sin desbordes a 390, 768, 1024, 1440, 1920 y 2560 px. El corte de 1200 px es el breakpoint `desktop` de `resources/css/app.css`; el de 768 px es `md` de Tailwind.

### Capas
De abajo hacia arriba. Cada capa gana a las de abajo sin importar cuánto se desplace la pantalla; ninguna pantalla inventa un `z-index` fuera de su rango.

| Capa | `z-index` | Qué |
|---|---|---|
| Contenido | 0–20 | Lo fijo del contenido (`sticky`): encabezado del detalle de la bandeja (`z-20`), encabezado de columnas y barra de totales de la tabla, columna fija de la matriz, barras de selección. Máximo `z-20`. |
| Menú y velo | 22–24 | El velo (`z-[22]`), el panel y la franja de iconos (`z-[23]`) y las entradas (`z-[24]`). Arrancan bajo la banda (`top: var(--shell-top)`). |
| Banda y sus tarjetas | 30 | La banda (`sticky top-0 z-30`) es un contexto de apilado propio: la campana y la credencial flotan dentro de él, así que nada del contenido ni el menú las tapa. |
| Drawers y modales | 50 | `x-drawer` y modales de MaryUI, con su velo: cubren también la banda mientras están abiertos. |

Un contenido nuevo que se quede fijo usa `z-10` (`z-20` si debe pasar sobre otro fijo); nunca sube de 20. `row-menu` sale a la capa superior del navegador con `popover` y no entra en esta tabla.

### Anatomía de una pantalla de catálogo
La forma de [Campus](app/Modules/Core/Livewire/pages/campuses/campuses.blade.php), que copian todos los catálogos:
1. **Encabezado** (`x-header`): el título y una línea que explica la pantalla. Nada más: la barra de herramientas no va en su slot `actions`, porque según el ancho subiría junto al título o caería debajo.
2. **Barra de herramientas** (`<x-core::catalog-toolbar>`), en su propio renglón debajo del encabezado y su filete, igual en las cinco pantallas:
   - a la izquierda, la búsqueda (slot `search`, `md:w-64`) y después los filtros, todos de `md:w-52` y en el mismo orden: primero los de catálogo (departamento, carrera, tipo) y al final el de estado. En los catálogos con cuatro filtros o con «Más acciones» (Salones y Docentes), `<x-core::catalog-toolbar narrow>`: la búsqueda de `md:w-56` y los filtros de `md:w-40`, para que la búsqueda, los filtros y la acción quepan en un renglón desde 1440 px; la opción «todos» de cada filtro dice solo el nombre del filtro («Departamento», «Edificio», «Tipo», «Tipo de plaza», «Dueño», «Estado»), con el `aria-label` de siempre, para que ningún texto se corte (decidido por el usuario el 30 de octubre de 2026). Por lo mismo, la búsqueda dice solo qué se busca («Clave o nombre», «Número o nombre»): a `md:w-56`, «Buscar por clave o nombre» se corta. Debajo de 1440 px la barra puede tomar dos renglones;
   - a la derecha, siempre en el mismo lugar (`md:ms-auto`), la **acción principal** (slot `action`), una sola y en `btn-primary`;
   - las acciones de toda la pantalla que no son la principal (como «Dar de alta usuarios» en Docentes) van en **«Más acciones»**, un botón de icono (`o-ellipsis-vertical`, con `aria-label="Más acciones"`) a la derecha de la principal, que abre el mismo menú de "Acciones de fila" (`<x-core::row-menu icon="o-ellipsis-vertical" label="Más acciones">`); lo que el rol no permite se queda en el menú, deshabilitado y con su motivo escrito (decidido por el usuario el 30 de octubre de 2026);
   - debajo de 768 px cada control ocupa todo el ancho, uno por renglón.
3. **Tabla** (`x-table` en una superficie blanca con filete):
   - la clave primero, en Mono y sin partirse (`whitespace-nowrap`);
   - el nombre con un ancho mínimo (`min-w-52` desde 1200 px) para que no pase de dos renglones;
   - el estado con su insignia;
   - las acciones a la derecha (ver "Acciones de fila");
   - paginación al pie, con el rango («Campus C1 a TNV · 1–5 de 5»);
   - **cabe en su caja** a 1440 y a 1024 px: lo que no cabe son columnas descriptivas largas, que se ocultan (`max-[1200px]:hidden`, y `max-[1535px]:hidden` en Materias y Planes, que tienen más). Si aun así no cabe, la caja se desplaza de lado, nunca la página.
4. **Formulario en modal** (`x-modal` + `x-form`) para crear y editar. Página completa solo si el formulario no cabe o tiene pasos.
5. **Avisos** con el `Toast` de MaryUI: título corto y el registro, escapado con `e()`.

#### Acciones de fila
El mismo patrón en las cinco pantallas, en la tabla y en los renglones apilados del celular:
- **Visible, solo lo frecuente:** «Editar» (y «Retícula» en Planes), en `btn-ghost btn-sm`. Lo demás se pliega en un botón **«Acciones»** (`btn-ghost btn-sm`, con `o-chevron-down`) que abre `<x-core::row-menu>`; entre 768 y 1199 px, en la tabla, los botones quedan solo con su icono (`o-ellipsis-horizontal` en «Acciones») y su nombre accesible (`aria-label="Acciones de <CLAVE>"`).
- **Dentro del menú**, un `<x-core::row-menu-item>` por acción: Desactivar o Activar, Ofrecer en el campus, Borrar (en `text-error`). Conservan su `spinner` y su `data-testid`. La confirmación de Desactivar o Borrar va en un modal, nunca en el diálogo del navegador (ADR-0023). Los que todavía usan `wire:confirm` se reemplazan en #374.
- **Lo deshabilitado se queda en el menú, deshabilitado, con su motivo escrito en un segundo renglón** del elemento («Borrar — Solo se borra una carrera sin planes de estudio.»). Nada se oculta por permiso y un tooltip no lo ve quien usa teclado o pantalla táctil.
- **El menú no se corta:** es un `popover` del navegador, en la capa superior, así que la caja con `overflow` de la tabla ni el pie de la página lo cortan. Se abre debajo del botón y, si no cabe, arriba. Esc o un clic fuera lo cierran y el foco vuelve al botón; las flechas, Inicio y Fin recorren los elementos.

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
| Personas y puestos | Académico › Personas y puestos (solo admin) | **Decidido por el usuario el 3 de noviembre de 2026:** variante C, la ficha de la persona; ver "Personas y puestos", abajo |
| Organigrama | Académico › Organigrama (solo admin) | **Decidido por el usuario el 3 de noviembre de 2026:** variante B, diagrama de cajas con el panel de la unidad a un lado; ver "Organigrama", abajo |
| Ficha del docente | Docentes › Ficha | **Decidido por el usuario el 30 de octubre de 2026 (tarea 1.13):** variante A, pestañas; ver "Ficha del docente", abajo |
| Retícula del alumno | Ficha del alumno; propuesta del coordinador (fase 2) | **Decidido (tarea 1.6):** ver "Retícula del alumno", abajo; la de la propuesta, **decidida por el usuario el 4 de noviembre de 2026** (maqueta C), en "Retícula de la propuesta de carrera" |
| Editor de retícula del plan | Retícula de cada plan de estudios (Académico › Planes de estudio) | **Decidido (tarea 1.8):** variante A, columnas por semestre y panel lateral; ver "Retícula del plan (editor)", abajo |
| Inicio por rol | Inicio | Palabra gigante y mosaicos vivos (por diseñar) |
| Checklist con contadores | Validación de catálogos | **Decidido por el usuario el 2 de noviembre de 2026:** variante C, tablero de tarjetas: una tarjeta de avance («N de 5 validados») y una por catálogo, con altas y bajas contra la foto anterior o «Primer periodo en Nexus»; toda la tarjeta abre la lista del catálogo (`?catalogo=`), que sigue la anatomía de catálogo y edita en un drawer. **Ajustado el 2 de noviembre de 2026 con datos masivos:** la página lleva una fila de pestañas (`tabs tabs-border`, como la Ficha del docente): «Tablero» y una por catálogo con su estado, que cambian de catálogo sin recargar; la lista abre con un solo renglón (nombre, estado, cifras, Validar o Reabrir) y su barra de herramientas queda a la altura de los demás catálogos; las altas y bajas son solo cifras («+7 altas · −6 bajas», neutras si son 0), con el filtro «Cambio» (Altas) en la lista y las bajas en un drawer («Ver bajas»); en [`review.blade.php`](app/Modules/CatalogValidation/Livewire/pages/review/review.blade.php). **Revisión por registro, decidida por el usuario el 12 de noviembre de 2026:** opción A «Tabla con columna Revisión» (`.impeccable/mocks/decision/validacion-individual/option-a.html`), con dos piezas de la C: el motivo escrito de una acción deshabilitada en la fila y «Revisaste los N → Validar catálogo»; ver «Revisión por registro», abajo |
| Cuadrícula día x hora | Disponibilidad docente; editor de horarios, vista por docente y por salón (fase 3) | **Decidido por el usuario el 30 de octubre de 2026 (tarea 1.12) para la disponibilidad:** variante A, herramienta y pintar; ver "Cuadrícula día x hora", abajo. **Editor de horarios, decidido por el usuario el 7 de noviembre de 2026 (tarea 3.5):** maqueta A «Semana con bandeja»; cada sesión lleva el color de su materia (los tokens `chain-N`, como franja) y el choque duro va con borde `error` e insignia con su regla; ver "Editor de horarios", abajo |
| Ranking con desglose | Asignación docente | **Decidido por el usuario el 7 de noviembre de 2026 (tarea 3.9):** maqueta C «Por materia» de entrada, con un selector para pasar a la A «Dos paneles»; ver "Asignación docente", abajo. Quién ve las evaluaciones lo decide el permiso de ver el ranking |
| Tablero de estado | Choques, avance de validación, avance del cierre, carga por docente | **Avance del cierre, decidido por el usuario el 10 de noviembre de 2026:** maqueta E, con la forma del avance de validación: cuatro cifras de todo el Tec, la búsqueda y el estado, un renglón por departamento con una insignia por carga y los faltantes sumados por columna, y un drawer por carga con sus acciones; ver «Avance del cierre», abajo. **Avance de validación, decidido por el usuario el 2 de noviembre de 2026:** variante A, matriz: tres indicadores (avance global, ventana de la etapa, observaciones abiertas) y una tabla de departamentos por catálogo con insignias de estado, totales por columna, «Recordar» por fila y un drawer de detalle; renglones apilados debajo de 1024 px. **Salones va en una sola columna con una insignia por campus** (código y icono de estado, nombre del campus en `aria-label`), decidido por el usuario el 2 de noviembre de 2026 con datos masivos: la matriz tiene siempre cinco columnas, se desplaza de lado dentro de su caja con el departamento fijo y trae una leyenda de símbolos debajo; el total de Salones cuenta por campus. En [`progress.blade.php`](app/Modules/CatalogValidation/Livewire/pages/progress/progress.blade.php). Choques y carga por docente siguen pendientes: jerarquía visual de duro vs blando |
| Consulta pública | Horarios publicados | **Decidido por el usuario el 10 de noviembre de 2026 (tarea 4.4):** variante A «Fichas por materia» (`.impeccable/mocks/publicados/a.html`); ver «Consulta publicada», abajo. Impresión y PDF siguen pendientes (4.5) |

### Revisión por registro
La validación de catálogos (decidida por el usuario el 12 de noviembre de 2026, opción A; CV7): cada registro activo se marca como revisado y el catálogo se valida solo con todos revisados. Lo copia cualquier lista donde alguien revisa registro por registro antes de aprobar el conjunto.
- **Avance:** «N de M revisados» (solo los activos cuentan) con una barra `progress progress-success` de 8 px junto a la cifra, nunca sola. En la tarjeta del tablero, la cifra en grande («17 de 29» y «revisados»), la barra a todo el ancho y «Quedan N por revisar» o «Todo revisado»; en el renglón de la lista, en una línea. La pestaña del catálogo lleva «N/M» en Mono mientras no está validado. Solo hay pestaña y tarjeta de los catálogos con revisión en el periodo, y el avance del tablero («N de M validados» y «Faltan N para sacar la carga de borrador») cuenta solo los catálogos que el admin abrió en el periodo (CV9, HC-14): de los demás no dice nada.
- **Validar catálogo:** `btn-primary`, el mismo en la tarjeta (a todo el ancho) y en el renglón de la lista. Mientras falte alguno va deshabilitado y su motivo va **escrito** al lado, con `o-clock` («Faltan 12 registros por revisar.»), enlazado con `aria-describedby`; quien solo lee lo ve con `o-lock-closed` («Solo el jefe del departamento valida.»).
- **Filtro segmentado** «Por revisar · Revisados · Con observación · Todos» con sus cifras de todo el catálogo (`?revision=`), en el lugar del filtro de estado. «Por revisar» son los activos sin marca y es el de entrada mientras quede alguno; «Falta» (docentes) abre en «Todos». Los inactivos se ven en «Todos», en `text-neutral` y con la insignia «Inactivo»; uno sin marca no se revisa: sin casilla ni «Por revisar», y su columna «Revisión» dice «Inactivo: no se revisa». Marcar los de la última página de «Por revisar» lleva a la que ahora es la última, nunca a una página vacía.
- **Columna «Revisión»:** a quien marca, «Marcar revisado» (`btn-outline btn-sm`) o la insignia «Revisado» con quién y cuándo debajo, en 12 px («Laura · 10 nov., 12:40»); a quien no marca, la insignia «Revisado» o «Por revisar» (`draft`). Quitar la marca va en «Acciones» con «Lo marcó … el …» en su segundo renglón. Corregir un registro lo marca: el pie del drawer lo dice («Al guardar, queda revisado.») y su encabezado lleva la insignia.
- **Elegir y marcar varios:** una casilla por registro sin revisar (y la de la página en el encabezado). Elegir es del navegador. Con algo elegido, la barra «N elegidos · Elegir los N por revisar · Quitar la selección · Marcar N como revisados» va pegada al pie de la superficie (`sticky bottom-0`, `bg-base-200`, `shadow-float`; el botón a todo el ancho en celular). «Elegir los N por revisar» toma los pendientes que dejan la búsqueda y los filtros. Validar o reabrir cambia quién marca, y la lista vuelve a empezar sin selección. La fila elegida lleva `bg-primary/5`.
- **Teclado** (desde 768 px, a quien marca; los atajos en `kbd` debajo de la barra): J y K mueven el foco de fila en fila (la fila enfocada lleva un contorno `outline-primary` de 2 px por dentro), Espacio elige, R marca los elegidos o la fila, Enter abre «Acciones» o «Editar». No corren al escribir en un campo, con Ctrl, Alt o Meta, ni con un diálogo, drawer o menú abierto. Las flechas siguen desplazando la página.
- **Lo deshabilitado de una fila** (la baja de quien ocupa un puesto) lleva su motivo escrito en la fila, debajo de sus acciones, en `text-xs text-neutral` (de la opción C); el motivo de toda la pantalla (solo lectura, revisión validada) va una vez en el renglón de la revisión con `o-lock-closed`.
- **Captura rápida en un recuadro** (el correo institucional): «Agregar» junto a «Sin correo institucional» (y «Agregar/Cambiar correo institucional» en «Acciones») abre un `popover` anclado al nombre, de 24rem como máximo: el campo con el dominio fijo a la derecha (`join`), para qué sirve, el error en `text-error` sin cerrarse, «Cancelar» y «Guardar». Esc o un clic fuera lo cierran y el foco vuelve a lo que lo abrió.
- **«Revisaste los N»:** «Por revisar» vacío dice «Revisaste los 40 docentes.» con `o-check-circle` en `success`, «Ya puedes validar el catálogo.» y «Validar catálogo»; si ya está validado o quien mira no valida, lo dice en lugar del botón.
- **Celular** (menos de 768 px): renglones apilados con la casilla a la izquierda, la clave y su insignia («Revisado» o «Por revisar») arriba, el nombre, el detalle y las acciones de 44 px; el filtro segmentado en cuatro columnas con la etiqueta sobre la cifra.

### Consulta publicada
Fuera de la sesión, `/horarios` (`academic-load::pages.published.*`, layout `layouts::public`), decidido el 10 de noviembre de 2026: variante A «Fichas por materia». Solo lee `PublishedScheduleQuery`.

- **Banda pública:** la del shell sin buscador ni menú. La liebre (cuadro rojo de 72 px; 56 px bajo 768 px) lleva a `/horarios`; «Nexus» y la ruta «Horarios» (sin ruta en celular, y bajo 480 px «Nexus» queda solo para lectores de pantalla, para que los selectores quepan completos); a la derecha los selectores Campus y Periodo (bajo 380 px bajan a un segundo renglón de la banda, a lo ancho) (los periodos publicados y, como filtro, «Todos los campus» y los campus activos con grupos publicados de la modalidad; cambiar uno va a `/horarios?periodo=&modalidad=&campus=`; la modalidad va en la URL y aún no tiene selector, M1) y «Iniciar sesión» (o el nombre y «Ir a Nexus»). Franja roja de 3 px debajo. Pie: «Horarios oficiales del Instituto Tecnológico de Ciudad Juárez». El tema es el del sistema.
- **Inicio:** «Horarios», periodo, modalidad, el campus del filtro si hay uno, y «Actualizado el…»; «Tu carrera y semestre» (una fila por carrera: clave en Mono, retícula con select si hay varias, botones de semestre de 44 px en celular); desde 1200 px a la derecha «Por docente» y «Por salón» con buscador sin acentos. Estado vacío: «Todavía no hay horarios publicados para Campus II en Enero–Junio 2027.» Solo con sesión y permiso, la franja «Sin publicar» y, con `academic_load.load.export`, el recuadro «Exportes del personal»: «Excel de la modalidad» (con una columna con el campus de cada grupo) y un select de departamento con «PDF del departamento» (botones `btn-outline btn-sm`, a todo el ancho y de 44 px en celular); descargan por `published.export`, con la misma página de espera que los PDF públicos.
- **Semestre:** «← Horarios», carrera, «Semestre N · N materias · N grupos» y la retícula en Mono, selectores de carrera, retícula y semestre (el estado vive en la URL) y la leyenda de iconos. Una sección por materia en orden de clave, con su franja `--chain-N` (cíclica), clave en Mono, nombre, créditos e insignia «N grupos»; debajo, fichas en cuadrícula `auto-fill` de 330 px mínimo (todo el ancho en celular) con tinte de la materia al 5 % y franja de 4 px a la izquierda (como el editor). La ficha: «CLAVE · grupo», el docente (o «Por asignar») y la mini-semana con una celda por día del perfil (horas y salón en Mono; «Virtual»; `o-beaker` práctica, `o-computer-desktop` virtual, teoría sin icono; día sin sesión vacío con borde punteado).
- **Docente y salón:** desde 768 px la semana de Cuadrícula día x hora (carriles para lo que se empalma, renglón de 3.5rem, bloques con el tinte de su materia); desde 1200 px la lista de grupos a la derecha; en celular, botones de día (`?dia=`) y la lista de horas del día.
- **Nunca** cupos, capacidad, versiones, estados, justificaciones ni RFC, ni avisos de lo que falta o de semestre incompleto: «Por asignar» va en texto neutro, sin alarma.

### Ficha del docente
Forma de la ficha del alumno, decidida el 30 de octubre de 2026 (variante A, pestañas), en [`teacher.blade.php`](app/Modules/Faculty/Livewire/pages/teacher/teacher.blade.php):
- **Encabezado:** enlace «Docentes», nombre, número en Mono y estado; debajo, adscripción principal, grado e ingreso. A la derecha «Editar datos» (`btn-outline`) y «Acciones» (`x-core::row-menu`: «Desactivar/Activar», solo admin y Subdirección, y «Restablecer contraseña», solo admin, que se llama «Dar acceso» si el usuario no tiene contraseña (AC-03), y, mientras no la tenga, «Reenviar invitación» (AC-07), del jefe de su departamento y del admin; para los demás quedan en el menú, deshabilitadas con su motivo en un segundo renglón).
- **Cifras:** franja de superficie blanca con filete: horas base del periodo activo, plazas que cuentan y adscripciones; en celular se apilan.
- **Pestañas** `tabs tabs-border`: Datos, Plazas, Disponibilidad, Perfil e Historial, la activa en `?tab=`, siempre en un renglón (`flex-nowrap`) que en celular se desplaza de lado.
- **Datos:** dos columnas desde 1200 px (datos | adscripciones). La tarjeta de datos no repite «Editar» (ya está «Editar datos» en el encabezado) y es un contenedor (`@container`): cada dato va con su etiqueta encima mientras la tarjeta mide menos de 32rem, y en dos columnas (etiqueta de 10rem | valor) desde ahí, así las insignias del usuario caben a 1200–1440 px y en celular. Debajo de «Correo» va «Correo institucional» (AC-06), que se captura en «Editar datos». El renglón «Usuario» lleva su número, «Activo/Inactivo» y el estado del acceso (AC-04): «Sin acceso» neutral, «Invitación enviada» info, «Debe cambiar su contraseña» warning y «Con acceso» success. Una adscripción es solo un departamento (código en Mono y nombre; sin campus), con la insignia «Principal» o «Hacer principal», y «Quitar de <depto>» por renglón, deshabilitado con su motivo si el rol no cubre ese departamento o es el propio docente. **Plazas:** una lista de renglones en cuadrícula (tipo, horas, desde, hasta, estado, acciones) que se apila debajo de 768 px, con las horas base del periodo al pie. Fechas `dd/mm/aaaa`.
- Agregar o editar una plaza y quitar una adscripción (el aviso dice qué principal queda) o una plaza se hacen en un `x-modal`; lo que no se puede quitar queda deshabilitado con su motivo.
- **Jefaturas** (solo admin): renglones en cuadrícula (cargo, departamento · campus o «Todo el Tec», desde, hasta, estado, «Terminar»); «Nombrar jefatura» y «Terminar jefatura» en `x-modal`; son los nombramientos de jefe y de jefe de proyecto docente del usuario del docente.
- **Disponibilidad:** el resumen del periodo activo en un renglón, la cuadrícula de solo lectura con los rangos por día y «Editar disponibilidad», que abre el editor; ver "Cuadrícula día x hora".

### Personas y puestos
Decidido el 3 de noviembre de 2026 (variante C), en [`person.blade.php`](app/Modules/Core/Livewire/pages/person/person.blade.php); solo la ve el admin.
- **Lista** (`people`): anatomía de catálogo, sin acción principal. Barra con «Número o nombre» y el filtro «Puesto»; columnas Usuario (Mono), Persona, Puestos vigentes (un chip por resumen) y Estado («Con puesto», «Sin puesto vigente», «Usuario inactivo»); «Ver puestos» por renglón; renglones apilados debajo de 768 px.
- **Ficha** (`person`): enlace «Personas y puestos», iniciales, nombre, usuario en Mono, insignia «Con N puestos vigentes» y la del estado del acceso (AC-04); «Nombrar» a la derecha (deshabilitado con «El usuario está inactivo.») y, sobre él, «Dar acceso» (`btn-outline`, con `x-confirm`) si el usuario activo no tiene contraseña (AC-03); buscador de otra persona y «Último nombramiento». Cuatro cifras (vigentes, anteriores, desde, próximo vencimiento).
- **Línea de vigencias:** eje de años, marca «Hoy» con su línea vertical `bg-primary` de 2 px a través de los renglones (en celular solo cruza las barras, no los nombres) y una barra por nombramiento: `bg-primary` vigente, `bg-primary/30` terminado, borde punteado programado; se desplaza de lado en cajas angostas.
- **Nombramientos:** una tarjeta por nombramiento (puesto, insignia, chips, fechas, días que faltan, quién lo terminó). «Terminar…» abre el recuadro con la fecha de fin en la tarjeta; el docente y un nombramiento de Administración cuyo fin dejaría algún día sin admin vigente lo llevan deshabilitado con su motivo (AZ-S11, AZ-S12); el motivo de Administración aplica cuando ningún último día desde hoy evita días sin admin. Si hoy no se puede pero un día posterior sí, el recuadro propone ese día y lo dice en un renglón. Quien termina su propio puesto de Administración y lo pierde en ese momento vuelve a Inicio con el aviso «Terminaste tu puesto de Administración.».
- **Lateral:** «¿Quién ocupa un puesto?» y «Bitácora de sus puestos». Dos columnas (`minmax(0,1fr) | 22rem`) desde 1200 px; debajo, una, con el lateral al final.
- **Cajón «Nombrar»** (`x-drawer` a la derecha, ADR-0023; U3 opción A «Drawer con casillas», decidida el 12 de noviembre de 2026): todos los puestos salvo el de docente y solo los campos que el puesto pide, con las fechas y «Nombrar» fijos al pie y los errores de la Action junto a su campo. La modalidad es texto si hay una sola activa y un control segmentado si hay varias (MS16); el campus es opcional y segmentado, «Todos los campus» por omisión (AZ-S17). Para el coordinador, las carreras van agrupadas por departamento (clave en Mono) con casillas, la de cada departamento marca o desmarca sus carreras, un buscador por clave o nombre solo oculta renglones y dos atajos marcan «Todas las de <modalidad> en <campus>» y «Todas las de <modalidad>». Cada carrera dice quién la coordina («Sin coordinador en C2» en `text-warning`) y, si la persona ya la coordina en ese alcance, «Ya la coordina en C2» o «… en todos los campus» con la casilla marcada y deshabilitada. Al cambiar de campus se conservan las carreras marcadas que también se ofrecen en él y la lista se atenúa mientras responde el servidor. Crea un nombramiento por carrera, todos o ninguno (M5, MS19), y el botón dice «Nombrar en N carreras». El jefe de proyecto docente usa el mismo cajón con departamento. El Organigrama conserva su formulario en modal.
- **Pestaña «Nombramientos»** (`tabs tabs-border`, junto a «Personas»): barra con «Nombre o usuario» y los filtros Puesto, Modalidad, Campus («Todos los campus» entre ellos) y Vigencia (Vigentes / Todos); tabla plana con Persona (enlace a la ficha, usuario en Mono), Puesto, Carrera o departamento (la clave de la carrera en Mono con su nombre debajo; el departamento, en texto normal), Modalidad («Todas» si no la lleva), Campus («Todos los campus» si no lo fija) y Vigencia (fechas en Mono y «Sin fecha de fin», «Terminado» o «Aún no empieza»). No trae los puestos de docente ni de alumno. Debajo de 768 px, renglones apilados.

### Organigrama
Decidido el 3 de noviembre de 2026 (variante B), en [`org-chart.blade.php`](app/Modules/Core/Livewire/pages/org-chart/org-chart.blade.php) y el panel [`org-unit-panel.blade.php`](app/Modules/Core/Livewire/components/org-unit-panel/org-unit-panel.blade.php); solo lo ve el admin.
- **Barra** (`<x-core::catalog-toolbar>`): «Nombre o clave», que filtra con Alpine sin ir al servidor y sin importar acentos (las cajas que no coinciden quedan en `opacity-35`); «Mostrar inactivas (N)»; el aviso «N departamentos académicos sin jefe» con `o-exclamation-triangle`, y «Nueva unidad».
- **Diagrama:** listas anidadas pintadas en el servidor, todo con utilidades de tokens y conectores `border-primary/40` (de 2 px, en claro y en oscuro). La Dirección va centrada; el primer nivel, en un renglón de cajas de 240 px que se encogen hasta 176 px (si aun así no caben, la caja del diagrama se desplaza de lado), con el tronco y la barra hechos con `before:` y `after:`; de ahí para abajo, rieles (`border-s-2`) con un codo por hija, y la última corta el riel con `bg-base-100`. Debajo de 768 px el primer nivel también cuelga de un riel: el diagrama es una lista. Al pie, la leyenda «Se edita en Académico · n personas en la unidad y en las que cuelgan de ella».
- **Caja:** el tipo en versalitas, con `o-lock-closed` si es departamento académico (borde punteado sobre `base-200`: se edita en Académico); el nombre; el titular o «Sin jefe vigente» («Sin titular vigente» en Subdirección Académica), en `text-warning` con icono, y en la raíz «Sin titular en Nexus»; la clave en Mono, sin partirse (si no cabe se recorta y la completa va en `title`; las personas bajan de renglón), y `o-user` con las personas de la unidad y sus hijas; «Inactiva» si lo está. La raíz lleva filete superior `primary` de 4 px y el primer nivel uno de 3 px en `primary/60`. La elegida, `ring-2 ring-primary`.
- **Panel:** a la derecha desde 1400 px (22rem, pegado arriba con su propio desplazamiento) y debajo antes; tocar una caja lo llena y, debajo de 1400 px, lleva la vista a él, sin animar si se pide movimiento reducido. Cabecera con el nombre, «clave · tipo», la insignia y la ruta «Dirección › …» (la Dirección no la lleva); «Titular» con «Ver en Personas y puestos»; «Puestos vigentes aquí» (vigentes y programados) con «Nombrar» (el formulario de «Personas y puestos», con la unidad fija y la persona por buscar) y «Terminar…» por renglón (el recuadro de la ficha de la persona); «Personas»; «Unidades hijas», chips que la eligen, y «Acciones»: «Renombrar», «Mover», «Agregar hija» y «Desactivar» (con confirmación) o «Activar». Lo que no se puede queda deshabilitado con su motivo escrito debajo («Mover: …»); un departamento académico solo dice que se edita en Académico, sin «Agregar hija». Mover a la misma madre o guardar sin cambios avisa «Sin cambios» y no vuelve a pintar el diagrama.
- **Formulario en modal:** «Nueva unidad» (nombre, clave, tipo sin Dirección ni Departamento académico y «Depende de», ya elegida: la de «Agregar hija» o, desde «Nueva unidad», la del panel si admite hijas o la Dirección), «Renombrar unidad» (nombre, clave y tipo; el tipo de la Dirección y el de Subdirección Académica no cambian, y el campo lo dice) y «Mover unidad» (las madres posibles en orden de árbol, con «└ » por nivel). Los errores de las Actions salen junto a su campo.

### Choques y avance
El tablero de la carga de un campus (`scheduling::pages.conflicts`, `/scheduling/conflicts`), decidido por el usuario el 7 de noviembre de 2026: la maqueta C («Por carrera y semestre») como vista principal y la A («Por departamento») como segunda; maquetas en `.impeccable/mocks/tablero-choques/` (galería https://claude.ai/artifact/SdVpuEPaB6Vc26Pqoz24mg).
- **Barra:** un `join` con las dos vistas (la elegida en `btn-primary`, `?vista=departamentos`), «Recalculado hoy a las HH:MM» y «Recalcular» (`btn-outline`), que encola el recálculo.
- **Por carrera y semestre:** una pestaña por carrera con su clave en Mono y cuántos grupos le faltan (`badge-error` si alguno tiene choque duro, `badge-warning` si no, y `o-check-circle` si está lista); un renglón con el nombre de la carrera y «N de M grupos listos», los que tienen choque y los que tienen pendientes; y la cuadrícula: un renglón por retícula con grupos (las viejas primero, con la marca «Prioridad» en borde punteado; ya no entran alumnos a ellas) y una columna por semestre. Cada celda (64 px) dice cuántos grupos tiene y, con icono y cifra en Mono, sus choques (`o-x-circle`, `error`) y sus pendientes (`o-clock`, `warning`); su fondo es `success/10`, `error/10` o `warning/10`, y una celda sin grupos va rayada. La columna de retículas queda fija al desplazarse de lado dentro de su caja. Un grupo compartido cuenta también en la celda de cada carrera con cupo (la retícula y el semestre donde esa carrera lleva la materia), y su detalle muestra la clave de la carrera dueña. Tocar una celda abre debajo su detalle; tocarla otra vez lo cierra.
- **Por departamento:** cinco cifras (grupos con choque duro, sin horario, sin docente, sin salón y listos para cerrar) y la matriz: un renglón por departamento con una insignia por tipo de pendiente (un guion tenue si es cero) y «Faltan N» o «Listo para cerrar»; renglones apilados debajo de 768 px. Tocar un departamento abre su `x-drawer` a la derecha.
- **Detalle** (celda o drawer): los choques duros, cada uno con su regla en una insignia, la clave de la materia y el grupo en Mono, su carrera y semestre, el mensaje del validador y «Corregir en el editor»; luego los grupos sin horario («Acomodar»), sin docente («Asignar») y sin salón («Asignar salón»). Los enlaces van al editor de horarios (`scheduling.editor?grupo=`) y a la asignación docente (`staffing.assign?grupo=`); mientras una pantalla no existe, el enlace queda deshabilitado con su motivo. Un grupo cuyo docente está dado de baja cuenta como «Sin docente» y lleva la insignia «Docente dado de baja» (`badge-error badge-soft`, `o-user-minus`; P10, confirmado el 10 de noviembre de 2026).

### Línea de tiempo de etapas

El calendario de un periodo (`core::pages.term`, `/terms/{term}`) y el bloque «Calendario» de Inicio usan la misma línea de tiempo (`Support/StageTimeline`; maqueta B, aprobada por el usuario). Los tonos de la insignia, la barra y la leyenda son los mismos (`StageStatus::tone()`, `icon()` y `barClasses()`):

| Estado | Insignia | Icono | Barra |
|---|---|---|---|
| En curso | `info` | `o-clock` | `bg-info` |
| Próxima | `neutral` | `o-calendar` | gris con borde |
| Terminada | `success` | `o-check-circle` | `bg-success/35` con borde `success/60` (atenuada: un periodo pasado no es lo más fuerte de la pantalla) |
| Sin fecha | `draft` | `o-minus-circle` | recuadro punteado «Sin fecha» |

- **Encabezado:** un carril propio para la marca «Hoy» (22 px, solo desde 672 px de caja; la marca se alinea a la derecha si cae después del 80 % del rango), los meses y, debajo, el lunes de cada semana. El rótulo de cada mes se acomoda al ancho de su celda con una consulta de contenedor: «noviembre 2026», «Nov 2026», «Nov» y, si ni eso cabe, nada visible; el nombre completo queda en `title` y en `sr-only`. La línea «Hoy» arranca bajo el encabezado y la leyenda es opaca. En Inicio, bajo 512 px, una leyenda «Hoy, 2 nov» explica la línea.
- **Renglón:** número, nombre, insignia y días que faltan en la misma línea (columna de nombres de 360 px desde 896 px de caja), y su barra más una barra delgada `bg-primary` con el código por cada campus con ventana propia. En la ficha, cada renglón es un botón que abre el panel de la etapa (`x-drawer` a la derecha) y se llama por su contenido (nombre, estado, fechas, días que faltan): nunca lleva `aria-label`. Se acomoda por el ancho de su caja (`@container`): desde 672 px, columna de nombres a la izquierda; debajo, el renglón apila nombre, fechas y una mini barra. La leyenda solo lista los estados presentes.
- **Sin calendario:** un periodo sin ninguna fecha no pinta 8 recuadros «Sin fecha»: dice «Este periodo no tiene calendario.» y lista las 8 etapas, cada una con «Poner fechas». Ahí queda el lugar (`data-slot="copy-calendar"`) de «Copiar de otro periodo».
- **Resumen de campus (ficha):** entre el encabezado y «Calendario», un renglón con «Abierto en» y los códigos de los campus abiertos en Mono, y un «Abrir en <campus>» por cada campus activo sin abrir (un solo menú «Abrir en…» si faltan más de dos). La lista «Campus» (quién abrió, cuándo, «Completar apertura»; un campus abierto e inactivo en gris con «Campus inactivo») va al final.
- **Inicio:** solo lectura, trae las etapas en curso y las dos que siguen, y enlaza la ficha del periodo a quien puede verla.

### Cuadrícula día x hora
La disponibilidad de un docente en un periodo, decidida el 30 de octubre de 2026 (variante A, «herramienta y pintar»; maquetas en `.impeccable/mocks/decision/docente/`). Las piezas son de Faculty: `x-faculty::availability.grid`, `.ranges`, `.legend` y `.swatch` (`app/Modules/Faculty/Views/components/availability/`).

- **Forma:** una columna por día del perfil del campus y un renglón por bloque, de 36 px (`h-9`), en una caja blanca con filete. La primera columna (52 px) lleva la hora en Mono; el encabezado de cada día, en `bg-secondary/40`, su nombre como etiqueta y sus horas disponibles («13 h disp.»). Si la caja mide menos de 640 px (consulta de contenedor), las celdas solo llevan el icono, los días su nombre corto y la columna de horas mide 44 px: a 390 px cabe sin desplazarse. Cada día es un `role="group"` con `display: contents`.
- **Tipos** (`AvailabilityKind::fillClasses()` e `icon()`), con el tipo en `data-kind` y sus variantes de utilidades:

| Tipo | Relleno | Icono |
|---|---|---|
| Disponible | `base-100` | — |
| No disponible | rayas a 135° de `neutral` al 9 y al 20 % sobre `base-100` | `o-no-symbol` en `secondary-muted` |
| Preferido | `success/15` | `o-star` en `success` |

- **Rangos en la cuadrícula:** la primera celda de un rango dice su tipo y la segunda sus horas en Mono («9:00–13:00»); las que siguen a otra del mismo tipo no llevan filete arriba (`data-continues`), así el rango se ve como un bloque. Debajo, la **leyenda** con muestras de 24 px.
- **Rangos por día:** el día con sus horas disponibles y cada rango con su icono, su tipo y sus horas en Mono, o «Disponible todo el día». En el editor van a la derecha desde 1400 px (360 px, pegados arriba al desplazarse) y debajo antes; bajo 768 px el día va arriba de sus rangos.
- **Editor** (`faculty::pages.teacher-availability`, `/faculty/teachers/{teacher}/availability`), página hija de la ficha (decidido por el usuario el 30 de octubre de 2026):
  - encabezado: el enlace al docente (a su pestaña Disponibilidad), «Disponibilidad» con la insignia del periodo, el docente, el horario de la cuadrícula y el bloque; a la derecha, «Copiar de <periodo>» (`btn-outline`), deshabilitado con su motivo si no hay semestre anterior o no tiene disponibilidad en el horario de la cuadrícula;
  - cifras: disponible (de cuántas), preferido, no disponible y las horas base del periodo con sus plazas; cuatro columnas desde 1200 px y dos antes;
  - herramientas: un `join` de radios «No disponible», «Preferido» y «Borrar», cada uno con su muestra y su tecla en `kbd` (N, P, B); la elegida en `btn-primary`. Bajo 768 px ocupan el ancho y no muestran la tecla;
  - pintar: clic o arrastre con la herramienta elegida; pintar sobre otro tipo lo reemplaza (decidido por el usuario el 30 de octubre de 2026). El botón de un día pinta su columna y el de una hora, su renglón. Un arrastre rápido pinta también las celdas que el puntero saltó;
  - teclado: la cuadrícula es una sola parada de tabulación; las flechas, Inicio y Fin mueven el foco por las celdas y, más allá de la primera fila o columna, por los botones de los días y de las horas, y Enter o Espacio pintan lo que tiene el foco. N, P y B eligen la herramienta, salvo al escribir o con el menú o un diálogo abierto;
  - pantalla táctil: las celdas llevan `touch-none`, así tocar pinta una celda y arrastrar el dedo pinta las que recorre; la página se desplaza desde la columna de horas, los encabezados o fuera de la cuadrícula;
  - lo pintado queda pendiente: cada celda cambiada lleva un punto `info` arriba a la derecha y «sin guardar» en su nombre accesible. La **barra de guardar**, pegada al pie (`sticky`, `shadow-float`), dice «N bloques sin guardar» (la única región `aria-live` de la pantalla), con «Descartar» (`btn-ghost`) y «Guardar disponibilidad» (`btn-primary`, la acción principal); solo se ve con cambios;
  - salir con bloques sin guardar pide confirmar: al cerrar o recargar, el aviso del navegador; al ir a otra pantalla del menú o de un enlace, «Tienes N bloques sin guardar. ¿Salir sin guardar?». Atrás y Adelante no se pueden detener;
  - lo maneja el componente Alpine `availabilityEditor` (`resources/js/app.js`) dentro de `wire:ignore`; `save()` y `copyPrevious()` no vuelven a pintar la página y avisan con un toast; si el servidor no responde, un toast de error lo dice y lo pendiente se queda. Mientras guarda no se puede pintar. Cuando el navegador restaura la página de su caché (Atrás o Adelante), el editor vuelve a pedir lo guardado, porque la página trae lo de cuando se abrió, y pone encima lo pintado sin guardar, que sigue pendiente.
- **En la ficha** (pestaña Disponibilidad): el resumen en un renglón, la cuadrícula de solo lectura (oculta a los lectores de pantalla, porque los rangos por día dicen lo mismo) con los rangos a su lado (360 px, como en el editor) si la caja mide 1200 px o más (antes no caben sin cortar las horas de las celdas o de los rangos) y debajo si no, la leyenda y «Editar disponibilidad» (`btn-outline btn-sm`). Sin periodo activo, pide elegirlo.

### Retícula del alumno
La ficha del alumno muestra su retícula como la del SII, que es la que conocen alumnos y coordinadores (aprobado el 29 de octubre de 2026, maquetas de la tarea 1.6). Desde el 30 de octubre de 2026 es una **cuadrícula perfecta**: los mosaicos tienen un tamaño fijo, así un plan con más o menos materias se ve parecido y las dos retículas se parecen lo más posible (pedido por el usuario).

- **Las piezas son de Core,** las mismas del editor del plan, para que las dos retículas no se separen: `App\Modules\Core\Support\SemesterGrid` calcula el renglón de cada materia y de cada banda, y `core::semester-grid.*` las pinta (`frame`, `column`, `semester`, `band`, `separator`, `tile` y `selection-bar`). Lo que dice cada mosaico es de su módulo.
- **Una sola cuadrícula (desde 768 px):** una columna por semestre, `repeat(N, minmax(6.875rem, 9rem))`, así un plan de nueve semestres cabe sin desplazarse desde 1366 px (menú de 288 px, márgenes de 32 px y aun con la barra de desplazamiento de Windows). La caja blanca con filete se ajusta a la cuadrícula; si no cabe, se desplaza de lado la caja, nunca la página.
  - **Renglón 1:** el encabezado de cada semestre, todos del mismo alto: «SEMESTRE 3» (etiqueta) y, en Mono, cuántas acreditó de las del semestre («4 de 6»). Nada más.
  - **Tronco común:** cada materia en su columna, una debajo de otra, por clave.
  - **Especialidades:** después del tronco común, una **banda** por especialidad a todo el ancho, con filete punteado arriba: «ESPECIALIDAD», su nombre completo y cuántas materias tiene. Sus materias van debajo, cada una en su columna, y el mosaico no carga el nombre. Van todas las del plan, por clave; una vez elegida la especialidad, solo la suya (reglas-de-negocio.md, "Retícula del alumno"). Para lectores de pantalla, la especialidad se dice dentro de cada semestre, antes de sus materias.
  - **Columnas:** el filete vertical entre semestres corre por toda la altura (las bandas lo tapan). La columna del semestre del alumno lleva `bg-base-200` de arriba abajo, y su encabezado dice «su semestre» en `sr-only`, porque el color nunca va solo. Una celda sin materia queda vacía.
- **Celular (menos de 768 px):** los semestres se apilan, cada uno en su caja con su encabezado y sus materias en dos columnas; primero el tronco común y luego cada especialidad bajo un separador corto con su nombre. Los mosaicos miden lo mismo que en escritorio. Cada mosaico existe una sola vez en la página: el semestre es una `<section>` con `md:contents`, y cada pieza lleva su lugar en `--col` y `--row`, que solo lee `md:`.
- **El mosaico** mide 104 px de alto (`h-26`) y el ancho de su columna; a 1440 px queda casi cuadrado. Nada lo estira. Radio `rounded-field`, `px-2.5 py-2`, la franja de su cadena, el anillo de elegido y el atenuado. De arriba abajo:
  1. la clave en Mono (12.5 px, 600) y, a la derecha, el icono de su estado (16 px), con el estado en `sr-only`;
  2. el nombre, en dos renglones a lo más, partido con guiones (`lang="es"`), completo en `title` y en el texto para lectores de pantalla;
  3. si todavía no la puede cursar, qué le falta, en un renglón recortado y completo en `title`: «Pide ACF-0902, ACF-0903», «Fuera de su avance» o «Pide 70 % de los créditos»;
  4. al pie, en Mono, sus créditos («5 cr») y, a la derecha, la oportunidad si pasa de la primera («2.ª») y la calificación.
- **Estados**, con los nombres del SII (`SubjectStanding`; reglas-de-negocio.md, "Retícula del alumno"). Su relleno, borde e icono salen de `SubjectStanding::tileClasses()` y `icon()`, y el estado va en texto para lectores de pantalla:

| Estado | Tono | Borde | Icono |
|---|---|---|---|
| Acreditada | `success` | filete del tono | `o-check-circle` |
| Cursando | `info` | filete del tono | `o-clock` |
| Sin calificar | `info` | punteado | `o-question-mark-circle` |
| Reprobada · prioridad | `warning` | filete del tono | `o-arrow-path` |
| A curso especial | `warning` | doble | `o-exclamation-triangle` |
| Curso especial reprobado | `error` | filete del tono | `o-x-circle` |
| Puede seleccionarla | `primary` sobre blanco | doble | `o-arrow-right-circle` |
| No permitida aún | `neutral` sobre `base-200` | punteado | `o-lock-closed` |

- **Arriba de la retícula** solo va la leyenda de los estados, en un renglón compacto: un recuadro de 24 px con el icono de 16 px centrado y dentro. **Sin textos de ayuda:** ni hasta qué semestre puede seleccionar ni «toca una materia»; el mosaico lleva `cursor-pointer`. La pestaña **Kárdex** muestra el historial por periodo, del más antiguo al más reciente (FIFO: el primer periodo que cursó va arriba), con el resultado en su insignia.
- **Cadenas de seriación.** Cada grupo de materias unidas por su seriación tiene su color, que se ve siempre como una **franja a la izquierda** de cada materia: una sombra interior con el token `chain-N` en `--chain`, que no toca el borde del estado. **Debajo de la retícula**, una leyenda nombra cada cadena por su primera materia y dice cuántas tiene (`ChainColor::swatchClasses()`). Tocar una materia resalta su cadena completa (lo que pide y lo que abre) y atenúa lo demás; tocar una cadena de la leyenda resalta todas sus materias; tocar lo mismo otra vez, «Quitar resaltado» o Esc lo quita. Solo al tocar, no al pasar el cursor, y sin líneas entre materias (decidido por el usuario el 29 de octubre de 2026). Lo hace el componente Alpine `studentGrid` (`resources/js/app.js`). En celular (menos de 768 px) la leyenda de cadenas va plegada: «Cadenas de seriación (N)» se abre con un toque (decidido por el usuario el 29 de octubre de 2026).
- **La barra de selección** dice lo resaltado («ACF-0902 Cálculo Integral: pide … · abre …» o «Cadena de X: N materias.») con «Quitar resaltado» a la derecha. Va pegada al pie de la ventana (`sticky`) mientras la retícula está a la vista, con la sombra flotante, y solo se ve con algo resaltado. Su texto es `aria-live="polite"` y está siempre en la página, vacío cuando no hay nada, para que el lector de pantalla diga cada cambio.
- **Materias sin semestre** (D18): las de su plan que esperan semestre en «Por validar» van debajo de la retícula, en una caja blanca con filete como el kárdex: el título «Por validar» (o «Sin semestre en el SII» si el plan es una retícula histórica) en la franja `bg-base-200/60`, y un renglón por materia, en el orden del SII, con la clave en Mono, el nombre, los créditos en Mono y la insignia «Acreditada» (`success`) o «Sin acreditar» en `text-neutral`. Un plan sin ningún semestre no pinta la cuadrícula ni la leyenda de estados: solo esa lista.
- **Tokens de las cadenas** (`--color-chain-1` a `--color-chain-12`, utilidades `chain-N`). Son elementos gráficos, así que piden 3:1; la razón es la mínima contra los rellenos de la retícula (blanco, `base-200` y los tintes al 10 % de cada tono). Con más de doce cadenas los colores se repiten, y la leyenda las distingue:

| Token | Claro · mínima | Oscuro · mínima |
|---|---|---|
| `chain-1` | `#6d4fc2` · 5.02 | `#b19cf0` · 5.58 |
| `chain-2` | `#0f7c8c` · 4.16 | `#5cc8d6` · 6.71 |
| `chain-3` | `#b0457f` · 4.45 | `#e88cc0` · 5.62 |
| `chain-4` | `#c2661a` · 3.42 | `#f0a565` · 6.45 |
| `chain-5` | `#3b56b8` · 5.55 | `#8ea6f5` · 5.61 |
| `chain-6` | `#6b7a1f` · 4.02 | `#bfd06a` · 7.82 |
| `chain-7` | `#1f86c7` · 3.37 | `#6cc0f2` · 6.57 |
| `chain-8` | `#8a5a3c` · 4.94 | `#d4a585` · 5.98 |
| `chain-9` | `#4d6b7a` · 4.81 | `#a3b9c6` · 6.48 |
| `chain-10` | `#9c4fd1` · 4.0 | `#d29cf5` · 6.17 |
| `chain-11` | `#2f8f5b` · 3.42 | `#7fd6a4` · 7.57 |
| `chain-12` | `#c24f9b` · 3.65 | `#f29ad0` · 6.48 |

### Retícula del plan (editor)
El admin edita la retícula de un plan en `academic::pages.plan` (`/academic/plans/{plan}`). De tres maquetas, el usuario eligió la variante A, columnas por semestre con un panel lateral: «para cosas densas, haya drawers» (aprobado el 29 de octubre de 2026). Las maquetas están en `.impeccable/mocks/decision/reticula/`.

- **Encabezado:**
  - el enlace «Planes de estudio»;
  - el nombre del plan, su clave en Mono y la insignia de estado;
  - carrera, versión, semestres, créditos y materias;
  - debajo, sus especialidades como chips con su número de materias, y «Gestionar especialidades».
- **Leyenda,** arriba de la retícula, en un renglón compacto como la de la ficha (recuadros de 24 px):
  - obligatoria (filete) y optativa (borde punteado);
  - inactiva en el catálogo: `o-minus-circle` sobre `bg-base-200`, como en el mosaico;
  - `3-2-5`: horas de teoría, de práctica y créditos;
  - «Pide» para seriación y «Correq.» para correquisito.

  **Sin textos de ayuda:** el mosaico lleva `cursor-pointer`.
- **La misma cuadrícula que la retícula del alumno,** con las mismas piezas de Core (`SemesterGrid` y `core::semester-grid.*`; ver "Retícula del alumno"): una columna por semestre, del primero al último del plan aunque estén vacíos; el tronco común arriba; una banda por especialidad con sus materias debajo; mosaicos de tamaño fijo; y en celular, los semestres apilados. La cadena de seriación es la misma franja, con el token `chain-N` en `--chain`; Academic calcula sus cadenas en `PlanGridEditorQuery`, porque no depende de Students.
  - **El encabezado de cada semestre** dice «SEMESTRE N» y, debajo, los créditos de su tronco común («27 cr»; los de especialidad dependen de cuál elija el alumno, y cuántas materias tiene ya se ve). A su derecha, en los semestres que se ofrecen, **«Agregar materia»**: un botón de icono `o-plus` (`btn-ghost btn-xs`, `aria-label` «Agregar materia al semestre N», tooltip «Agregar materia» a la izquierda, para que el del último semestre no se salga de la caja).
  - **Las bandas** van solo para las especialidades con materias en la retícula; las demás están en los chips del encabezado.
- **Cada materia** es el mosaico de Core, de arriba abajo:
  1. la clave en Mono y, si está inactiva en el catálogo, `o-minus-circle` a la derecha («Inactiva» en `title` y en `sr-only`); el mosaico inactivo conserva `bg-base-200` y texto `text-neutral`;
  2. el nombre, en dos renglones a lo más, partido con guiones;
  3. lo que pide, en un renglón: «Pide X · Correq. Y», recortado y completo en `title`;
  4. al pie, horas y créditos (`3-2-5`) y, a la derecha, «Optativa» si lo es, además del borde punteado.
- **Tocar una materia** le pone el anillo, resalta su cadena completa y atenúa lo demás, y la barra de selección (la misma de la ficha) dice qué pide, qué correquisito tiene y qué abre. También la abre en el panel lateral, cuyo velo tapa la barra:
  - El panel es un `x-drawer` de MaryUI a la derecha, de 448 px, y a pantalla completa en celular. Se superpone con su velo, porque MaryUI no empuja el contenido sin CSS propio.
  - Es un diálogo: `role="dialog"`, `aria-modal="true"` y `aria-labelledby` con el título del panel. MaryUI pone la trampa de foco, Esc e `inert`.
  - Esc, el velo o la X lo cierran y quitan la selección.
  - Sus secciones son «Lugar en la retícula», «Pide» y «La piden»; «La piden» es de solo lectura.
  - «Lugar en la retícula» es un formulario: semestre, especialidad («Ninguna (tronco común)»; una inactiva aparece deshabilitada, salvo la que ya tiene la materia), «Obligatoria» y «Guardar lugar» (`btn-primary btn-sm`). Los errores caen en su campo.
  - Al pie, fijo, «Cada cambio queda en la bitácora.» y «Quitar de la retícula…» (`btn-ghost text-error`). Abre una confirmación que lista la seriación que se va, como materia y como requisito con su tipo, qué materias se quedan sin seriación y que cambia el avance de los alumnos del plan; Academic no da la cifra de alumnos, que es de Students.
  - «Pide» lista cada requisito con su clave en Mono, su nombre y su semestre, su tipo en un select («Seriación» o «Correquisito») y quitar (una X, sin confirmación, como en la maqueta). Bajo 640 px el select y la X bajan a un segundo renglón.
  - Debajo va «Agregar requisito»: busca otra materia de esta retícula por clave o nombre, sin importar acentos y sin la propia ni las que ya pide; lista hasta 8 con su clave, su nombre y «Sem. N», en el orden de la retícula, y la elegida queda escrita en la búsqueda. Luego el tipo y «Agregar» (`btn-outline`). La lista va en línea bajo la búsqueda, como en «Agregar materia», y no flotante como en la maqueta.
  - Una seriación que cierra un ciclo, al agregarla o al cambiar el tipo, se rechaza con una alerta `alert-error alert-soft` al final de «Pide»: «**Cierra un ciclo:** X ya pide, en cadena, a Y.» y la cadena como claves en Mono unidas por flechas, del requisito a la materia. El select del tipo regresa al que tiene.
- **«Agregar materia»**, en el encabezado de cada semestre (hasta el último que se ofrece). Un plan sin semestres ni materias lo ofrece en su estado vacío, en el semestre 1. Abre un modal:
  - busca en el catálogo por clave o nombre, desde dos letras y con todas las palabras;
  - lista las primeras 8 coincidencias con la clave en Mono, el nombre, el departamento y `HT-HP-C`, y al pie cuántas son;
  - las que ya están en la retícula, las inactivas y las borradas se ven atenuadas, con el motivo en lugar del departamento, y no se eligen;
  - la elegida lleva un anillo `primary`, y el botón dice «Agregar CLAVE»;
  - debajo van el semestre, la especialidad y «Obligatoria».
- **Los modales de la retícula** («Agregar materia» y la confirmación de quitar) van en la página y no dentro del panel, porque el panel se desliza con `transform` y los encerraría. Esc los cierra sin cerrar el panel.
- **La leyenda de cadenas** va **debajo de la retícula** y resalta la cadena que se toca; la barra de selección dice cuántas materias tiene. Tocarla otra vez, «Quitar resaltado» o Esc quita el resaltado. En celular (menos de 768 px) la leyenda de cadenas va plegada: «Cadenas de seriación (N)» se abre con un toque (decidido por el usuario el 29 de octubre de 2026).
- **Especialidades**, en el mismo panel. Cada una muestra su clave, su estado, su nombre y cuántas materias tiene. Sus acciones:
  - «Renombrar», en su lugar;
  - «Desactivar» o «Activar»;
  - «Quitar», deshabilitado si tiene materias. El motivo va escrito en el renglón y el botón lo enlaza con `aria-describedby`, porque un tooltip no lo ve quien usa teclado o pantalla táctil.

  Al final va «Nueva especialidad», con clave y nombre.
- **El resaltado lo pinta el servidor** con `data-focus`, `data-me` y `data-on-chain`, que leen las mismas utilidades de la retícula del alumno. La retícula cambia con cada edición, y así no queda estado de Alpine desfasado.

### Retícula de la propuesta de carrera
La vista «Retícula» de la propuesta de carrera (`academic-load::pages.proposal`), decidida por el usuario el 4 de noviembre de 2026 (maqueta C «Las dos»; maquetas en `.impeccable/mocks/proposal-grid/`). Usa las piezas de Core de "Retícula del alumno"; en [`grid.blade.php`](app/Modules/AcademicLoad/Views/proposal/grid.blade.php).

- **Renglón de la vista:** «Retícula · Tabla», el filtro «Todas · Con grupos · Sin grupos» (`?grupos=con|sin`, la elegida en `btn-primary`) y los totales: «MATERIAS CON GRUPOS 53 / 59» (las de todas, en `text-neutral`), «GRUPOS» y «ALUMNOS POSIBLES». En la tabla van en la barra pegada al pie.
- **Departamentos:** «DEPARTAMENTOS» y una píldora por departamento con su clave, sus grupos y el estado de su parte. La elegida va sólida en `primary` con una × a la vista; en celular la franja se desplaza hasta ella.
- **Estados** (`ProposalTileState`), con su leyenda arriba de la retícula; lo que decidió el departamento manda sobre los grupos:

| Estado | Relleno y borde | Icono |
|---|---|---|
| Con grupos | `base-100`, filete | — |
| Sin grupos (0 grupos) | `warning/10`, punteado `warning` | `o-minus-circle` |
| Aceptada por el departamento | `success/10`, filete `success/40` | `o-lock-closed` (`o-check-circle` si toda la propuesta está bloqueada) |
| Regresada por el departamento | `error/10`, filete `error/40` | `o-arrow-uturn-left` |

- **Cadenas:** la franja de la retícula del alumno (las mismas cadenas, `SeriationChains` de Core, y los mismos colores, `ChainColor`) y, debajo, su leyenda, plegada en celular.
- **Marcar:** el departamento elegido, el filtro o los dos (lo que cumple ambos) se marcan como una cadena resaltada: lo demás se atenúa al 20 % y en grises, el encabezado de cada semestre lo cuenta («18 de SC», «2 sin grupos») y la barra de selección lo dice («Sistemas y Computación (SC): 42 materias · 133 grupos.», «Sin grupos: 6 materias.», «Sistemas y Computación (SC), sin grupos: 2 materias.»). En la tabla, el filtro oculta las filas que no lo cumplen; el departamento las atenúa, o las oculta con «Mostrar solo».
- **Cadena y marca:** tocar una cadena de la leyenda la resalta en lugar de la marca, que sigue elegida; «Quitar resaltado» o Esc quitan primero la cadena (vuelve la marca) y luego la marca. Elegir un departamento o un filtro suelta la cadena, y «Ver» de una parte regresada suelta también el filtro, que podría esconder lo que se le pide. Lo pinta el servidor, como en el editor del plan.

### Editor de horarios
Académico › Horarios (`scheduling::pages.schedule-editor`, `/scheduling/editor`), decidido por el usuario el 7 de noviembre de 2026: maqueta A «Semana con bandeja» (`.impeccable/mocks/horarios/a.html`; el cajón del grupo, `cajones.html`). Las piezas están en [`schedule-editor.blade.php`](app/Modules/Scheduling/Livewire/pages/schedule-editor/schedule-editor.blade.php) y `app/Modules/Scheduling/Views/editor/`; lo que pinta lo lee y arma `Scheduling\Queries\ScheduleEditorQuery` con la geometría de `Core\Support\WeekGrid` (la comparte la consulta publicada). Los cajones son componentes aparte (`scheduling::components.session-drawer` y `.group-drawer`): abrirlos no vuelve a pintar la semana, y cambiar de día en celular, «Ver» de un choque y plegar la bandeja son del navegador.

- **Encabezado:** «Horarios» con la insignia del periodo y un renglón con el campus, la retícula (clave en Mono), los grupos, «Horas colocadas N de M» (Mono solo en las cifras) y las insignias «N choques» (`error`, o `success` con 0) y «N avisos» (`warning`, solo si hay), que llevan al panel de choques.
- **Selectores**, en un renglón que se parte: Carrera (select), Retícula (segmentado: la vigente con «Vigente» y cada vieja con «Prioridad» y su número de grupos sin acomodar; bajo 768 px se apila; si la carrera tiene una sola, su clave y su insignia como texto, con sus grupos sin acomodar si es vieja, porque un segmentado de una opción no elige nada), Semestre («Semestre 3 · 4 sin acomodar»), el docente o el salón en esas vistas y, a la derecha, «Ver por» (Semestre · Docente · Salón). El semestre, el docente o el salón y «Ver por» se parten juntos: «Ver por» nunca queda solo en un renglón. Debajo, un aviso `alert-warning` por retícula vieja con grupos sin acomodar: «Retícula <clave>: N grupos sin acomodar · se acomodan primero» e «Ir». Todo queda en la URL (`?carrera=`, `?reticula=`, `?semestre=`, `?grupo=`, `?vista=`, `?docente=`, `?salon=`, `?dia=`); `?grupo=` abre la carrera, retícula y semestre desde donde el usuario ve el grupo (su carrera dueña o la de su cupo) con el grupo elegido. Con un grupo elegido, «Ver por» Docente muestra a su docente y Salón el salón de su primera sesión con salón (si no tiene, el primero de la lista); la URL conserva `?grupo=` a propósito, así al volver a Semestre el grupo sigue elegido. Solo se toma de un grupo del semestre que el usuario ve.
- **Columna izquierda** (desde 1200 px, de 292 px, pegada arriba y con su propio desplazamiento; antes, la bandeja va arriba de la semana y los choques abajo, plegables):
  - **«Grupos del semestre»** con su cuenta y los que faltan por acomodar: los grupos por materia, en el orden de la retícula (la materia con su clave en Mono). El encabezado lleva su cuenta y «N sin acomodar» (`warning`, con texto). Cada grupo es una pieza (`aria-pressed`) con la franja de su materia, «CLAVE · grupo» en Mono, «N de M h» (Mono solo en las cifras) con `o-check-circle` (`success`) o `o-exclamation-triangle` (`warning`), y el docente o «Sin docente»; el botón de su esquina abre el cajón del grupo con el icono de lo que se hace ahí: `o-pencil-square` («Editar cupos y sesiones») para quien reparte los cupos y `o-eye` («Ver cupos y sesiones») para los demás; nunca `o-information-circle`, que no dice que el cajón se edita. La bandeja es una sola parada de tabulación: desde 768 px arriba y abajo cambian de grupo y la derecha va al botón de su cajón (la izquierda regresa); en celular, donde cada materia es una tira de lado, izquierda y derecha cambian de grupo y abajo va al botón (arriba regresa). Inicio y Fin van al primero y al último; Enter o Espacio los tocan. Con Alt, Ctrl o Cmd las flechas son del navegador (Alt+← es «atrás»). En celular la bandeja empieza cerrada, se cierra al elegir un grupo y las piezas de una materia se desplazan de lado.
  - **«Choques del semestre»:** los duros primero (`error/10` con borde), luego los avisos (`warning/10`); cada uno con su regla en Mono, el día y las horas de su sesión, el mensaje y «Ver», que lleva a la sesión (en celular, a su día) y la marca un momento con un anillo `primary`; si el choque no es de una sesión, elige el grupo del semestre al que pertenece (nunca uno de otro semestre o carrera). El empalme de dos grupos del semestre va una vez. HC-12 (horas que faltan) se lee como aviso, «faltan horas», aunque el validador lo dé duro.
- **La semana** (desde 768 px; ver "Cuadrícula día x hora"): una columna por día del perfil del campus, tan ancha como sus carriles (cada carril de al menos 7rem, para que «CLAVE · grupo» y sus horas se lean; un día sin sesiones lleva un carril), y un renglón de 52 px por bloque de `day_start` a `day_end`. Lo que no cabe se ve desplazando la caja de lado a cualquier ancho, nunca la página; la caja mide a lo más el alto de la ventana bajo la banda, y al desplazarla la columna de horas y el encabezado de los días (su nombre y «N h ocupadas») se quedan pegados. En la sesión, los espacios alrededor del punto de «CLAVE · grupo» van a la mitad (`word-spacing`). No se pliegan carriles: todas las sesiones del día se ven.
  - **La sesión** es un bloque que abarca sus renglones, con la franja de su materia (un token `chain-N` por materia del semestre, en el orden de la retícula), el tinte de ese color al 9 %, «CLAVE · grupo» y sus horas en Mono con el icono de su tipo (`o-book-open` teoría, `o-beaker` práctica, `o-computer-desktop` virtual), y su salón en Mono o «Sin salón» con `o-exclamation-triangle` en `warning`. Con choque duro lleva borde `error` de 2 px y una insignia por regla («HC-05» con `o-x-circle`): el color nunca va solo. Las sesiones que coinciden en un día van en carriles lado a lado. Tocarla abre su cajón.
  - **Elegir un grupo** (en la bandeja o en la barra) pone sobre la barra «CLAVE · grupo», su materia, sus horas (Mono solo en las cifras) y la leyenda («Cabe», «Choca: se guarda con choque», como el aviso al colocar, y «No se puede»), «Editar cupos y sesiones» o «Ver cupos y sesiones» (el cajón del grupo) y «Quitar selección» (o Esc), y pinta cada celda libre del grupo: **cabe** (`success/10` con borde punteado y `o-plus`), **choca** con una regla que se guarda marcada (rayas `error` con `o-exclamation-triangle` y la regla en Mono) o **no se puede** (HC-01 o HC-02, que la base no guarda: las rayas neutras de «No disponible» con `o-no-symbol`, un botón deshabilitado que no coloca y deja pasar los clics a la sesión de abajo, salvo su marca, que muestra el motivo bajo el puntero). El motivo de las que chocan o no se pueden va en `aria-describedby` y, con el foco o bajo el puntero, en un aviso junto a la celda (`bg-neutral`, fuera de la caja para que no se recorte). Tocar una celda que cabe o que choca coloca un bloque (se une con los contiguos). Las sesiones del grupo elegido llevan el anillo `primary`; las demás se atenúan bajo las celdas: sin el tinte de su materia, con la franja al 40 % y el texto en `neutral` (nunca con `opacity`, que baja el texto a 2:1). Quien no acomoda el grupo ve lo mismo sin botones, sin que las celdas tapen los clics a las sesiones, y el motivo, una sola vez, en la barra («Solo lectura: …» o el de la carga).
  - **Teclado:** el primer enlace de la página, «Ir a la semana», solo se ve con el foco (fijo bajo la banda) y lleva a la parada de la semana (en celular, al día elegido) sin recorrer la bandeja ni los choques. La semana es una sola parada de tabulación (la primera celda que coloca o sesión que se abre; si no hay, la primera que no se puede, para que Tab siempre entre); las flechas recorren celdas (también las que no se pueden, para leer su motivo) y sesiones por su lugar (con un grupo elegido que se acomoda, las sesiones de los demás quedan bajo las celdas y fuera de las flechas), Inicio y Fin van a la primera y la última del día, Enter coloca o abre y Esc quita la selección; al colocar, el foco queda en la sesión que resultó (`scheduleEditor`, `resources/js/app.js`).
- **Vista por docente:** la semana de un docente de los grupos del semestre en todos los campus (extendida a las horas y los días de sus sesiones de otro campus que caen fuera del horario de este, que cuentan en «N h ocupadas»), con su disponibilidad del periodo (no disponible en las rayas neutras, preferido en `success/15`, con su nombre y sus horas); sus sesiones de otro semestre u otro campus van en `base-200` con borde punteado y el campus, y no se abren. **Por salón:** la semana de un salón de los que usa el semestre, con sus bloqueos (rayas neutras, `o-lock-closed` y el motivo) y el docente de cada sesión.
- **Celular (menos de 768 px):** los días en botones («L 6 h», el elegido en `btn-primary`, `?dia=`) y la lista de horas de ese día: cada hora con las sesiones que empiezan en ella y, con un grupo elegido, toda hora donde el grupo no tiene sesión, aunque la cubra la de otro grupo, con «Cabe · colocar una hora», «Choca: …» o «No se puede: …»; sin grupo, «Libre» o la disponibilidad o el bloqueo. Nada desborda la página a 390 px.
- **Cajón de la sesión** (`x-drawer` a la derecha, `w-full max-w-md`: 448 px y nunca más ancho que la ventana, para que no empiece fuera de ella con barras de desplazamiento clásicas): «Sesión de CLAVE · grupo», la materia y el docente, «Ver el grupo»; arriba, el **Validador** con los choques de la sesión y los avisos del grupo, o «Sin choques en esta sesión.» (se lee al abrir, al guardar y al asignar o quitar el salón; con cambios sin guardar dice «Es del horario guardado; se vuelve a leer al guardar.»); día, inicio y fin (en los bloques del perfil) y tipo (segmentado Teoría · Práctica · Virtual); y la sección del salón (`scheduling::components.session-room`, tarea 3.7). **Cada cosa se guarda de una sola forma:** el horario con «Guardar horario» al pie (deshabilitado sin cambios, con «Cambia el día, la hora o el tipo para guardar.» en su tooltip) y el salón con «Asignar salón» o «Asignar y pedir préstamo» en su sección. El día, las horas y el tipo se quedan en el navegador hasta «Guardar horario» (Alpine los compara con lo guardado, sin ir al servidor): mientras tienen cambios, la sección del salón se esconde tras su motivo, «Guarda el horario para ver los salones libres a esa hora.», el salón actual y «Descartar cambios», porque los sugeridos serían de la hora guardada. Guardar sin cambios no escribe. Quien no acomoda el grupo no ve ese aviso. La sección del salón: el salón actual con su estado (el préstamo pendiente o por pedir en `warning`, como en los sugeridos: es un estado, no un choque; los choques en `error`), los tres mejores sugeridos y «Ver N más» (abiertos si la búsqueda resalta uno de ellos), el buscador; cada sugerido dice su clave y su tipo, si cabe el grupo («Cabe: 30 de 32»), por qué se sugiere (por defecto o historial de la materia, mismo edificio) y su estado; si todos están libres lo dice una vez («Todos libres a esa hora») en lugar de una insignia «Libre» por renglón. Para quien no lo asigna, lo mismo de solo lectura con «Lo asigna el jefe de <depto>.». Las horas de los mensajes del validador vienen como en la semana («de 7:00 a 8:00»; ver «Horas» en Typography). Al pie, también «Quitar sesión» (`btn-ghost text-error`, con confirmación). Si al moverla se le quitó el salón, lo dice («Quitamos el salón X: estaba ocupado a esa hora.»). Quien no acomoda el grupo ve los campos deshabilitados y el motivo arriba. Pasar de un cajón al otro («Ver el grupo», una sesión del grupo) no regresa el foco; al cerrar el último, regresa a lo que lo abrió.
- **Cajón del grupo** (`w-full max-w-md`, como el de la sesión): la materia, «CLAVE · grupo», quién imparte, la capacidad, el docente en una caja de solo lectura («Lo asigna Asignación docente»), sus sesiones (cada una abre su cajón) y «Cupos por carrera» (`scheduling::components.group-quotas`), con «Guardar cupos» en el pie fijo (`sticky`, como el de la sesión; a su izquierda, el motivo si no se puede guardar). Junto a «Agregar carrera» se dice qué pasará: una carrera del departamento que imparte entra a los cupos y cuenta al «Guardar cupos»; una de otro departamento se propone en ese momento a su jefe («Proponer a <depto>») y sus lugares quedan apartados mientras decide. Lo apartado cuenta (HC-07; confirmado el 8 de noviembre de 2026): «N de M lugares repartidos» ya lo suma, la barra lo pinta aparte en `warning` con la insignia «N apartados por propuestas», cada propuesta dice «Propuesto · aparta N lugares» y el error HC-07 («Quita N lugares.») lo incluye. Lo apartado son los lugares guardados de cada propuesta, como los cuenta «Guardar cupos»: un cambio de lugares en su renglón cuenta hasta «Cambiar lugares». Quitar un cupo «Propuesto» lo retira en ese momento, así que pide confirmar («¿Retirar la propuesta de IIND? Se retira en este momento y el jefe de <depto> ya no la verá.»); quitar uno del borrador no escribe hasta guardar.
- **Avisos:** toasts de MaryUI («Sesión colocada»; «Colocada con choque» si el grupo rompe una regla dura que antes no rompía, aunque no nombre la sesión; «No se colocó» con el mensaje del validador: la sesión que no se guarda no queda). Cambiar el día, las horas o el tipo en el cajón de la sesión es del navegador y viaja al guardar; el validador del cajón se lee al abrir y al guardar.

### Acuerdos
La pantalla `scheduling::pages.agreements` (`/scheduling/agreements`, `?acuerdo=<uuid>`), decidida por el usuario el 7 de noviembre de 2026 (maqueta B «Bandeja de dos paneles»; maquetas en `.impeccable/mocks/horarios/acuerdos-b.html`). Copia la forma de la bandeja del departamento.

- **Lista (izquierda, 300 a 380 px):** búsqueda («Salón o grupo») y filtro de tipo; tres grupos plegables («Por decidir», «Enviados», «Decididos») con su conteo, el de «Por decidir» en `warning`. Los pendientes van del más antiguo al más reciente, sin importar el tipo, para que ningún préstamo quede enterrado detrás de una serie de grupos compartidos; los decididos, del más reciente al más antiguo. Cada acuerdo: salón, día y horas en Mono (o la clave del grupo) y, debajo, el grupo (o los lugares de la carrera) y el otro departamento por su nombre: «De Ciencias Básicas» si lo decide quien mira, «Para …» si lo pidió y «… → …» si solo lo ve; la clave del departamento queda en `title` (no hay nombre corto en el modelo, y «II» se confundía con Campus II). Los decididos llevan su insignia. Vacío: «No tienes acuerdos por decidir.» Al entrar con `?acuerdo=` y al pasar al siguiente, el renglón abierto se lleva a la vista dentro de la lista.
- **Aceptar varios** (confirmado el 8 de noviembre de 2026): quien decide tiene una casilla en cada «Por decidir» (de todo el alto del renglón, para el tacto) y, con dos o más, «Elegir los N que puedes aceptar» / «Quitar la selección». Se aceptan hasta 50 a la vez: con más de 50 por decidir dice «Elegir los primeros 50 de N que puedes aceptar» y «Se aceptan hasta 50 a la vez.», y si se eligen más a mano, «Aceptar» se deshabilita con el motivo debajo. Un préstamo cuyo bloque empalma no se puede elegir: su casilla va deshabilitada y un tercer renglón en `error` dice por qué («No se puede aceptar: ACF-0901 1B lo ocupa de 10:00 a 12:00 (HC-02).»). Elegir es del navegador (no va al servidor): con algo elegido, la barra «N elegidos · Quitar · Aceptar N» va al pie de la lista (pegada abajo en celular, al alcance del pulgar) y confirma con la cuenta. Se aceptan del pedido más antiguo al más reciente, no en el orden en que se eligieron, para que el resultado no dependa de cómo se eligieron (las propuestas ya apartan sus lugares, así que dos no compiten por los mismos; HC-07); un error de la base (o cualquier otro) en uno se reporta, se dice sin detalle y los demás siguen; uno que ya no se ve o ya no se decide queda como «Un acuerdo · Ya no está disponible.», sin nombrarlo. Los que fallan y siguen por decidir quedan elegidos. El resultado queda arriba de los paneles («Aceptados N · No se aceptaron M» y cada uno que falló con su mensaje) hasta cerrarlo, decidir otro, filtrar o buscar, o empezar otra selección. Rechazar es uno por uno, porque pide motivo.
- **Detalle (derecha):** título («Préstamo de LC-201», «Cupo de ISC en SCC-1013 3A»), tipo e insignia («Por decidir», «Enviado», «Aceptado», «Rechazado», «Cancelado»); debajo, una franja a todo el ancho, sin caja, con el estado o lo que pasa al decidir, dicho una sola vez (`info/10`; `error/10` si no se puede aceptar; `success/10`, `error/10` o `base-200` ya decidido). Luego los datos en Mono y la semana: en un préstamo, la del salón (ocupado en rayas con la clave del grupo, el bloque pedido con borde, icono y texto: «Pedido», «Enviado», «Empalma», «Aceptado»…); en un grupo compartido, la materia, el grupo con su semestre y plan, los lugares, el dueño del grupo, el semestre en que la otra carrera lleva la materia en sus planes activos («No está en sus planes» si en ninguno) y la semana del grupo («El grupo todavía no tiene sesiones.» si no tiene) (sus sesiones con el anillo `primary`, su salón o «Sin salón» con `o-exclamation-triangle`). Al final, de qué departamento a cuál (nombre, y la clave en Mono tenue), quién lo pidió y cuándo. Desde 1880 px la semana va a la derecha de los datos.
- **Mini semana:** filetes sobre la superficie del panel, sin caja propia. Si mide menos de 36rem (consulta de contenedor) los bloques solo llevan su icono y debajo va la lista con el texto: lo del día pedido en un préstamo («El lunes en NII1»), todas las sesiones en un grupo.
- **Acciones fijas al pie:** «Aceptar» y «Rechazar…» y «Cancelar solicitud» para quien pidió. «Rechazar…» pone el motivo (obligatorio) arriba de todo, con lo que pasa al rechazar (en un préstamo, la sesión se queda sin salón), lo lleva a la vista y le da el foco; Esc vuelve. Al aceptar o rechazar se abre el siguiente por decidir (el que seguía; del último, el primero) con el foco en su título y el aviso del toast; si ya no queda ninguno, el panel se queda en el decidido. Lo que no se puede queda deshabilitado con su motivo escrito en la franja (`aria-describedby`).
- **Bajo 1024 px** un solo panel: la lista y, al elegir, el detalle con «Volver» y el pie pegado abajo (el panel recorta con `overflow-clip`, que no rompe `sticky`).
### Asignación docente
La pantalla del jefe para asignar el docente de cada grupo con el ranking a la vista (`staffing::pages.assign`, `/staffing/assign`, Académico › Asignación docente; tarea 3.9). El usuario eligió la maqueta C con un selector para pasar a la A el 7 de noviembre de 2026 (maquetas en `.impeccable/mocks/staffing/jefe/asignacion-c.html` y `asignacion-a.html`). Las piezas están en [`app/Modules/Staffing/Views/assign/`](app/Modules/Staffing/Views/assign/).

- **Encabezado:** el título, departamento · campus · periodo y, a la derecha, «Carga del departamento» con la insignia de su estado y «38 de 61 con docente» con su barra. A quien ve varios departamentos (Subdirección, admin), el selector «Departamento» (`?depto=`) debajo del título.
- **Estado de la carga:** en Propuesta, un aviso `alert-info` («Los docentes se asignan cuando pasa a Asignación») con «Pasar a asignación docente» (`btn-primary`, la principal de la pantalla) para quien puede; en celular el texto va arriba y el botón debajo, a todo el ancho. Si ya se sabe que Scheduling lo vetaría (`StaffingBlockersQuery`: grupos con choques duros, o sin horario en un campus con bloques), el botón sale deshabilitado y debajo un `alert-warning` dice en palabras qué falta («59 grupos tienen choques duros en su horario.», «25 grupos no tienen horario.») con «Ir a Choques y avance» y «Abrir el editor de horarios»; si aun así se veta al pasarla, los motivos en un `alert-error`. En pantalla ningún motivo cita ids de regla. En Borrador o después de Ajustes, una línea de solo lectura con `o-lock-closed`; en Asignación o Ajustes, quien solo consulta ve «Solo consulta: asignan el jefe del departamento y el jefe de proyecto docente.». A quien no puede asignar, «Asignar» le sale deshabilitado con ese motivo en su `title` (no se oculta), y no hay «Cambiar».
- **Vista** (`?vista=materia|paneles`): «Por materia · Dos paneles», siempre al principio del renglón de herramientas; la última elegida se recuerda en la sesión para cuando se entra sin `?vista=`. Los filtros son los mismos en las dos: búsqueda «Grupo, materia o docente» (`?q=`, con la × solo si hay texto), Materia, Carrera, Semestre y «Todos · Sin docente · Con docente» con sus cifras (el filtro segmentado de referencia). «Por materia» abre en «Sin docente» mientras la carga está en Asignación o Ajustes y falta algún docente (`?estado=` y `?grupo=` mandan). Debajo de los filtros, la leyenda del orden del ranking: «Orden: horas base › historial y perfil › evaluación».
- **Por materia (C):** una superficie por materia; su encabezado (pegado arriba desde 768 px, `bg-secondary/40`) dice «SEM. N», el nombre, la clave en Mono y «4 de 7 con docente» con un punto por grupo (hasta 12). Cada grupo es un renglón: a la izquierda (10.5rem) la clave en Mono de 20 px con su carrera dueña, el horario con un bloque por renglón, sin partirse («L/Mi 11:00–13:00» y debajo «V 11:00–12:00»; `ScheduleSummary`) y «5 h por semana»; a la derecha:
  - sin docente, los tres primeros lugares como tarjetas (tres columnas desde 44rem de caja): lugar y nombre, plaza (`PositionTag`: TC, 3/4, MT, «Asig. N h», «Hon.»), «otro depto.», perfil, veces, evaluación o «sin evaluación», avisos, marcas; al pie «Base 27 h · Puntaje 4.44» con el mismo peso (el ranking va primero por horas base), «Quedan 12 → 7» y «Asignar». Solo el primero que puede tomarlo (sin marcas) lleva el borde `primary/50` y su botón en `btn-primary btn-soft`; los demás, y cualquiera con marca, `btn-ghost`. Si los tres traen marca, una línea lo dice: «Ninguno sin marcas: estos son los primeros del bloque final.»;
  - con docente, un renglón compacto sin caja de color (la clave en Mono de 15 px y el horario a la izquierda): «Con docente» (la única señal verde), su nombre y plaza, «Lugar p de n» (o «Fuera del ranking») y «Asignó X · dd/mm/aaaa hh:mm»; a la derecha, «Cambiar», que abre su ranking;
  - sin ranking todavía, «Calculando el ranking…» con un spinner, y la página consulta cada 5 s mientras algún grupo a la vista espera; si el departamento ya se recalculó y nadie entró, «Ningún docente entró al ranking de este grupo.».
  - «Ver el ranking completo (N)» lo abre debajo, a todo el ancho del renglón: desde 768 px mide a lo más 70vh, con su propio desplazamiento y el encabezado de columnas pegado arriba; luego «Historial de asignaciones» plegado y, al pie, otra vez «Ocultar ranking» y «Siguiente sin docente».
  - **«Siguiente sin docente»** (a la derecha de las acciones de cada grupo; en el renglón compacto de un grupo con docente, solo la flecha `o-arrow-down` con su nombre en `aria-label` y `title`; con el filtro «Sin docente» solo al pie del ranking abierto, porque el siguiente es el renglón de abajo): lleva la página al siguiente grupo sin docente de la lista (después del último, el primero), le pone el foco y lo marca un momento con un anillo `primary`; si está a más de dos pantallas, salta sin animación (también al entrar con `?grupo=`).
- **Dos paneles (A):** a la izquierda (20rem; 22rem desde 1536 px, `2xl`) los filtros y la lista de grupos, pegada arriba con su propio desplazamiento desde 1024 px: el filtro segmentado compacto (la etiqueta sobre la cifra), la búsqueda y los selects caben en la columna; cada grupo con su clave, materia, «carrera · Sem. N · horario» y el docente con su lugar o «Sin docente»; el elegido en `bg-secondary` desde 1024 px, y al entrar o al cambiar de grupo la lista se desplaza hasta él. A la derecha, el grupo elegido: clave · materia, sus datos y su docente, y las pestañas «Ranking» e «Historial» (`tabs tabs-border`) con «Siguiente sin docente» a su derecha (elige el que sigue en la lista; en celular dice «Siguiente»). Debajo de 1024 px la lista ocupa el ancho y el grupo se abre como hoja a pantalla completa que entra desde la derecha, con «Grupos» para volver (y Esc).
- **El ranking** es el mismo en las dos vistas: arriba la leyenda «Orden: horas base › historial y perfil › evaluación»; luego #, docente (plaza, «otro depto.», «sin evaluación» en `text-neutral`, sus avisos y sus marcas), base, veces, perfil (`o-star` Preferente en `success`, Apto, Sin perfil), eval., puntaje, quedan y «Asignar» (o «Asignado» en `info` para el vigente, con su renglón en `info/10`). Se ven primero quienes pueden tomar el grupo; el bloque final se pliega en un renglón `bg-base-200` con `o-no-symbol`: «119 docentes no pueden tomarlo (97 sin horas, 40 choque, 30 no disponible)» y «Ver», que lo abre debajo (y «Ocultar»). Abiertos, los marcados llevan su texto en `text-secondary-muted` y cada marca es su insignia: Choque `error`, Sin horas `warning`, No disponible `draft`. El aviso de traslado justo entre campus (SC-09) es discreto, junto a la plaza: «traslado justo» con `o-map-pin` en `text-neutral`, y no baja al docente. Desde 46rem de caja es una tabla de nueve columnas (la del docente, de 8.5 a 24rem, y «Asignar» al extremo derecho); debajo, lugar, nombre y botón arriba, y las cifras con su etiqueta en una franja `bg-base-200` de tres columnas iguales.
- **Asignar (HC-16):** el primer lugar se asigna con un clic y un toast con «Lugar 1 de N». Otro lugar abre el motivo en su lugar (bajo las tarjetas o bajo el renglón del ranking), sobre `warning/10`: «Ocupa el lugar 4 de 22 del ranking.», «Motivo para asignar a <docente>» y «Cancelar» / «Asignar con motivo». Lo que rechaza AssignTeacher (un choque, la carga en otro estado) se ve junto al docente en un renglón `error/10` y en un toast de error.
- **Historial:** cuándo y quién, docente, lugar («3 de 12»), motivo («Primer lugar: no requiere motivo.» si no hubo) y estado: Vigente (`success`), Reemplazada (`neutral`, `o-arrow-path`) o Quitada (`draft`, `o-minus-circle`), con quién y cuándo dejó de estar vigente.
- `?grupo=<id>` abre ese grupo: en la vista por materia abre su ranking y lleva la página a él; en los dos paneles lo elige (lo usa el tablero de choques).

### Solicitudes de cambio
Decidido por el usuario el 7 de noviembre de 2026 (tarea 3.11; maquetas en `.impeccable/mocks/staffing/docente/`): la pantalla es la maqueta A «Lista y detalle» y el pedido del docente vive en «Mi carga» (maqueta C). En [`change-requests.blade.php`](app/Modules/Staffing/Livewire/pages/change-requests/change-requests.blade.php) y [`my-load/requests.blade.php`](app/Modules/Staffing/Views/my-load/requests.blade.php). Ajustado con datos masivos el mismo día.

- **Encabezado:** el título y la línea del departamento; a la derecha, como en la asignación, el selector «Departamento» (`select-sm`, solo a quien ve varios) y, al coordinador, «Nueva solicitud» (`btn-outline`, `o-plus`; deshabilitado con su motivo si ningún grupo de su carrera tiene la carga en Asignación o Ajustes). Se ve aunque todavía no tenga solicitudes.
- **Aviso de la carga:** superficie blanca con filete, en tres partes desde 1200 px: «CARGA DEL DEPARTAMENTO» con el departamento y, debajo, campus y periodo; las etapas Propuesta · Asignación · Ajustes · Cierre (puntos `bg-primary` las pasadas, `bg-info` con anillo la actual y `aria-current="step"`, filete las que siguen; se ocultan en celular), y la insignia del estado con qué pasa con las solicitudes («3 aprobadas por aplicar en el departamento · 1 de tu carrera. Al aplicarlas, la carga regresa a Asignación.»: la segunda cifra solo si la lista de quien la ve tiene menos). Debajo, si Scheduling vetó el regreso, una alerta `alert-warning alert-soft` con los motivos.
- **Filtros:** los estados como filtro segmentado, el mismo de la asignación (`staffing::shared.segmented-filter`: un `join` de 40 px, 44 en celular, con la cifra en Mono, `aria-pressed` y el elegido en `btn-primary`; si no cabe, se desplaza de lado dentro de su caja), y a la derecha «Tipo» y la búsqueda «Docente, grupo o materia» (`?q=`, sin importar acentos). En celular, mientras se ve un detalle, se ocultan.
- **Lista y detalle:** como la bandeja del departamento, dos paneles desde lg (`minmax(18rem,22rem) | 1fr`, 26rem desde 1440 px); debajo, uno a la vez, con «Solicitudes» (`btn-ghost`, como «Grupos» en la asignación) para regresar. La lista va en tres grupos con encabezado `bg-secondary/40` y su cifra: Pendientes, Por aplicar y Resueltas. Cada renglón: quien la pide y la fecha, la materia · grupo · carrera, la insignia del estado (Pendiente `o-clock`, Aprobada `o-check`, Rechazada `o-x-circle`, Aplicada `o-check-circle`: `ChangeRequestStatus::icon()`) y la del tipo (`badge-neutral badge-soft` con `o-calendar-days` u `o-map-pin`), y lo que propone en dos renglones a lo más; la elegida en `bg-secondary`, como la lista de la asignación. La que se abre sola (la primera) solo se marca desde lg.
- **Detalle:** fijo arriba desde lg con su propio desplazamiento, con el contenido a 60rem como máximo. Título con la materia y el grupo, insignias, «Pedida el …» y, en un renglón que se parte, Docente, La pidió (si fue la coordinación), Materia, Carrera dueña y Campus; «Cambio de horario/salón» con dos cajas, «Al pedirla» (filete) → «Propuesta» (`info/10`, filete `info/40`), la flecha baja en cajas angostas; «Motivo»; «Historial» como riel (`border-s-2`) con un punto por paso y el comentario del jefe en `bg-base-200`, más el paso que falta en gris. Lo que le toca a quien lo ve va en un pie pegado abajo del panel (`sticky bottom-0`, filete arriba), así se decide sin desplazar: «Comentario» con «Rechazar» (`btn-outline btn-error`) y «Aprobar» (`btn-primary`), o «Abrir en el editor» (`btn-outline`, al editor de horarios con el grupo) y «Marcar como aplicada» (`btn-primary`); a los demás, una línea con `o-information-circle` que dice quién sigue.
- **Nueva solicitud** (coordinador, confirmado el 7 de noviembre de 2026): un `x-modal` con «Grupo» y los campos de la solicitud (abajo). Al enviarla, la pantalla abre la solicitud en su departamento.
- **En «Mi carga»:** el bloque de una sesión cuyo grupo tiene una solicitud abierta lleva su estado como icono y texto («Solicitud pendiente», «Solicitud aprobada»), que se parte en columnas angostas. Debajo de los días, «Mis solicitudes» con su cifra y un solo «Solicitar cambio» (`btn-outline`; deshabilitado con su motivo en tooltip y `aria-describedby` si ningún grupo se puede pedir); si la carga de algún grupo no está en Asignación ni en Ajustes, una línea con `o-lock-closed` lo dice. Luego la tabla de sus solicitudes (grupo, cambio, estado, respuesta del jefe, enviada), que se apila debajo de 44rem de caja.
- **Modal de una solicitud** (el mismo en los dos lugares): «Grupo» como lista de radios (`staffing::change-requests.group-picker`): cada grupo con su clave en Mono, su materia y un renglón de datos (horario y campus en Mi carga; semestre, departamento y horario para el coordinador), el elegido en `bg-secondary`, y el que no se puede pedir deshabilitado con su motivo escrito («Solicitud abierta», «Carga en Cierre»); con más de 8 grupos, una búsqueda arriba que filtra en el navegador. Luego «Qué quieres cambiar» como `join` de dos radios (Horario con `o-calendar-days`, Salón con `o-map-pin`; el elegido en `btn-primary`), «Qué propones» con un ejemplo según el tipo en el placeholder, «Motivo», y «Cancelar» y «Enviar solicitud». Sin textos de ayuda; lo que rechaza la Action sale como alerta arriba.

### Oferta del departamento y Mi carga
Las vistas del docente (tarea 3.10, Mi espacio › Oferta y Mi carga), ajustadas con datos masivos el 7 de noviembre de 2026. En [`offer.blade.php`](app/Modules/Staffing/Livewire/pages/offer/offer.blade.php) y [`my-load.blade.php`](app/Modules/Staffing/Livewire/pages/my-load/my-load.blade.php).

- **Oferta, encabezado y barra:** el título y un renglón con el departamento (si es uno solo; con varios, pestañas), el periodo y «N grupos asignados a ti»; la barra con «Materia o clave» (`?q=`, `md:w-64`), «Semestre» (`md:w-52`) y, a la derecha, el filtro segmentado «Todos · Sin docente · Entre tus 3 primeros» con sus cifras (`?estado=`); la cifra de cada opción es exactamente lo que muestra. Con filtros, «Mostrando N de M» y «Quitar filtros».
- **Oferta, fichas:** sin franja de color: la suya en `info/5` con filete `info/40`, la de un grupo sin docente con borde punteado. El lugar es una cifra (`badge-neutral badge-soft`, «14 de 139» con las cifras en Mono, sin icono), en `badge-success` con `o-star` si cuenta en «Entre tus 3 primeros», o «Asignado a ti» (`info`, `o-check-circle`). Las marcas (`RankingMark`: Choque `o-x-circle` en `error`, Sin horas `o-clock` en `warning`, No disponible `o-no-symbol` en `neutral`, los mismos de la asignación) van como icono y texto de 12 px, sin insignia, para que no pesen más que el grupo.
- **Oferta, panel:** `x-drawer` a la derecha (`w-full max-w-md`) con su botón de cerrar (`o-x-mark`, `btn-ghost btn-circle`, 44 px en celular), como el de Carga por docente. «Tu lugar» con sus marcas en insignia y, con la de choque, una caja `error/10` «Choca con tu horario» con cada grupo suyo que se empalma («A4 · Ingeniería Económica · C1 Lu 08:00–10:00»). «Tu desglose» como tabla con encabezados «Criterio · Tuyo · Puntos», en 14 px; «Horas base vigentes» dice que es el primer criterio del orden y no suma puntos.
- **Mi carga:** la ecuación de horas en una franja (dos por renglón en celular, sin los signos); los días en columnas cuando su caja mide 56rem o más y uno debajo de otro antes, así ninguna insignia se sale de su día. Cada bloque con la materia en 14 px, sus horas, grupo y salón en Mono (o «Sin salón» con `o-exclamation-triangle` en `warning`, en texto normal) y el campus. Entre dos bloques, «Hueco de N h» o, si cambia el campus, «Traslado C2 → C1 · 3 h» con sus minutos libres: neutro, o en `warning` con `o-exclamation-triangle` si son menos de `staffing.min_campus_travel_minutes` (SC-09; nunca bloquea).
- **Lo publicado (P8):** con la carga publicada, las dos pantallas muestran su última versión y lo dicen en una línea discreta, «Publicado el 8 dic. 2026 · v2», en `text-neutral` y sin icono ni insignia: en Mi carga, en 14 px bajo la línea del periodo; en la oferta, como un dato más del renglón del encabezado. Una por carga publicada de las que se ven; si se ven varias, cada una lleva delante su departamento o su campus («C2: publicado el …»), y en un renglón con `flex-wrap` se acomodan solas. Sin carga publicada a la vista no hay línea. En el partial [`shared/published.blade.php`](app/Modules/Staffing/Views/shared/published.blade.php).

### Cierre de la carga
La pantalla del jefe para cerrar la carga de su departamento (`staffing::pages.closing`, `/staffing/closing`, Académico › Cierre de la carga; tarea 4.1). El usuario eligió la maqueta B2 el 10 de noviembre de 2026 (`.impeccable/mocks/cierre/cierre-b2.html`). Las piezas están en [`app/Modules/Staffing/Views/closing/`](app/Modules/Staffing/Views/closing/), las justificaciones y su drawer son componentes aparte (`staffing::components.closing-justifications` y `closing-justify-drawer`) para que abrir un grupo o justificar no vuelva a pintar la página, y las acciones están en `ClosesLoads`, que comparte con «Avance del cierre».

- **Encabezado:** el título y «SC Sistemas y Computación · Ene-Jun 2027» (la clave en Mono). Entra quien cierra la carga de algún departamento: el jefe va al suyo y Subdirección y el admin eligen con el selector «Departamento» (`?departamento=<id>`, como llegan los avisos del cierre; solo ofrece los que tienen grupos). Un departamento que el usuario no puede cerrar es 403.
- **Pestañas por campus** («C1 Campus I · C2 Campus II», `?campus=<código>`): una por campus donde el departamento tiene grupos, cada una con la insignia del estado de su carga («Publicada · v1» en `success-solid`), con el icono que ese estado lleva en la línea del estado y en «Avance del cierre» (`LoadClosing::statusIcon()`: Propuesta `o-document-text`, Asignación `o-user-plus`, Ajustes `o-arrow-path-rounded-square`, Cerrada `o-lock-closed`; no el `o-eye` del tono); en celular el nombre del campus se oculta y la insignia baja. Sin `?campus=`, la del único campus del usuario si tiene grupos ahí, o la primera (#415: la credencial ya no elige campus). Con un solo campus no hay pestañas: el campus va en la línea del título y la insignia a la derecha. Una carga sin grupos no tiene pestaña; si ninguna tiene grupos, la pantalla dice que la carga se queda en borrador.
- **Estado de la carga** (superficie blanca con filete): la línea de estados (Borrador, Propuesta, Asignación, Ajustes con `o-arrows-right-left` porque es una vuelta, Cerrada, Aprobada solo si el campus exige el visto bueno, y Publicada · vN), cada uno con la fecha (`dd/mm`) en que la carga llegó a él por última vez: los pasados con punto `primary`, el actual en `info` con anillo y `aria-current="step"`, uno visitado después del actual en gris, Ajustes sin visitar con filete `primary` y los que siguen con filete gris; si no cabe, se desplaza de lado dentro de su caja. Debajo, la línea del estado con su icono y tono («Faltan 255 grupos y 3 trámites para cerrar» en `error` si algún faltante es choque duro, si no en `warning`: los grupos son distintos, uno sin docente ni salón cuenta una vez, y los trámites son las solicitudes de cambio y los acuerdos; decidido el 10 de noviembre de 2026; «Lista para cerrar»; «Sin grupos» si ya no le queda ninguno activo; «Cerrada el 07/12/2026 · Por …»; «Reabierta el …»; «Publicada · v2 el …») y una línea con lo que falta («14 choques duros · 4 sin docente · 2 solicitudes»), y a la derecha las acciones: «Reabrir carga» (`btn-outline`) y la principal (`btn-primary`): «Cerrar carga», «Volver a asignación» o «Dar visto bueno» (con su «Regresar con comentarios»). Lo que el rol o el estado no permite queda deshabilitado con su motivo en `title` y escrito debajo de los botones («Solo Subdirección reabre la carga.»), salvo «Faltan … para cerrar», que ya dice la línea del estado. En celular los botones ocupan el ancho. Todo estado tiene su paso en la línea: Aprobada aparece también si la carga está o estuvo ahí aunque el campus ya no exija el visto bueno.
- **Publicación:** debajo del estado de la carga, en su propio bloque a todo el ancho, la línea de versiones con «Publicar» (`academic-load::components.publish-load`; ver «Publicación»), de lado porque aquí cabe. Publicar no está entre las acciones de arriba: cuando no se puede, el último punto de la línea dice por qué. La ve quien ve la carga; al publicar, la pantalla vuelve a leer la carga (`load-published`): la línea de estados, la insignia de la pestaña y el historial.
- **Para cerrar:** una celda por cosa que falta (los grupos con choque duro, sin horario, sin docente, con sesiones sin salón y con docente dado de baja; las solicitudes de cambio pendientes o aprobadas sin aplicar; los grupos compartidos propuestos sin respuesta y los acuerdos que esperan la respuesta del departamento), con la cifra en Mono de 26 px, su icono y su tono, el detalle («3 justificados», «8 sesiones presenciales · 1 justificado», el nombre del docente dado de baja, «Espera a Ciencias Económico-Administrativas») e «Ir a …», que deja la credencial en el periodo de la carga y abre en el campus de la carga (`?campus=`) la pantalla donde se arregla (Choques y avance, Horarios, Asignación docente, Solicitudes de cambio, Acuerdos). Lo que ya no falta queda atenuado con `o-check-circle`. Sin horario y sin salón solo aparecen si el perfil del campus usa bloques de tiempo y salones. La caja es un contenedor: cuatro columnas desde 44rem, dos desde 26rem y un renglón por cifra más angosta (la cifra y su icono en una columna de 5.25rem, para que tres dígitos no toquen el nombre). El nombre va en 14 px y «Ir a …» lleva la flecha en línea, tras la última palabra. Las cifras cuentan grupos, como el veto de HC-15; las sesiones sin salón van en el detalle. En el encabezado, la insignia «Faltan 255 grupos y 3 trámites», «Lista para cerrar» o «Sin pendientes» y «Revisado a las H:MM» con «Revisar de nuevo»: lo que falta sale del validador al momento (`ClosingBlockersQuery`), que se corre al elegir la carga y después de cada acción, no en cada petición. Una carga publicada no lo calcula: dice que ya pasó el cierre. Si una carga cerrada vuelve a tener faltantes, una línea en `warning` avisa que publicarla se vetará.
- **Justificaciones:** pestañas `tabs tabs-border` «Por justificar» (primero, con su cifra en `warning`), «Justificados» y «Todos», la búsqueda «Grupo o materia» (sin importar acentos) y, si el campus usa salones, el filtro segmentado «Todos · Sin docente · Sin salón» con lo que cada opción muestra; todo filtra en el servidor y la lista se muestra de 50 en 50 («Mostrando 50 de 255» y «Mostrar 50 más»). Cada grupo: la clave en Mono con «ISIC · Sem. 1», la materia con su clave y el horario un bloque por renglón sin partirse, lo que le falta como insignia (`warning` mientras no se justifica, `neutral` ya justificado), la justificación con quién y cuándo o «Sin justificación» (en la tabla y en la lista de solo lectura; en los renglones apilados ya lo dice «Justificar»), y «Justificar» (`btn-outline`), «Editar» (`btn-ghost`) o, si ya no se edita, «Ver». La búsqueda es un campo de 40 px (44 px en celular), como los de las barras. La lista es un contenedor: tabla de cinco columnas desde 62rem (la de «Le falta» de 13rem, para «Sin salón · 2 sesiones»), la clave a la izquierda y lo demás apilado desde 34rem, todo apilado más angosta.
- **Justificar en lote** (confirmado el 10 de noviembre de 2026; como la selección de Acuerdos): quien justifica tiene una casilla por grupo, que ocupa todo el alto del renglón, y «Seleccionar los N de esta vista» (los de la pestaña y los filtros, también los que no se muestran todavía; «los primeros 200» si son más), que cambia a «Quitar la selección». La selección vive en el navegador hasta justificar. Con algo elegido, una barra pegada abajo dice «N elegidos» con «Quitar» y «Justificar N grupos» (`btn-primary`, a todo el ancho en celular), que abre el modal «Justificar N grupos» con el campo «Justificación». Se justifican hasta 200 a la vez; con más, el botón se deshabilita y lo dice. Cambiar de pestaña, de búsqueda o de filtro vacía la selección, y el lote solo toma los elegidos que siguen en la vista: uno que cambió mientras estaba elegido (se justificó en otro lado, ya tiene docente) no se toca. Al terminar, arriba de la lista, «Justificados N · No se justificaron M» con la clave y el motivo de cada uno que no aplicó.
- **Drawer de justificación** (`x-drawer` a la derecha, `max-w-md`; se abre en el navegador, sin pasar por la lista): «Justificar A2» o «Justificación de A2», la materia y el campus; carrera y semestre, horario; lo que le falta con «Asignar en Asignación docente» o «Asignar salón en Horarios»; el campo «Justificación» y quién y cuándo justificó. Al pie, fijo: «Quitar justificación» (`btn-ghost text-error`), «Cancelar» y «Guardar justificación». Si la carga ya no se edita o el usuario no cierra la carga, el campo se deshabilita y un `alert-info` dice por qué. Si con el drawer abierto el grupo deja de faltar (ya tiene docente y salón), dice «Este grupo ya no necesita justificación» en un `alert-success` con «Cerrar».
- **Historial de la carga:** columna lateral de 22rem desde 1280 px, pegada arriba con su propio desplazamiento; antes va entre «Para cerrar» y «Justificaciones», que es larga. Riel (`border-s-2`) con un punto por paso (`success` al cerrar, aprobar o publicar; `warning` al reabrir), el título («Cerrada», «Reabierta», «Volvió a Asignación», «Pasó a …»), «Asignación → Cerrada · nombre · dd/mm/aaaa h:mm» y el comentario o el motivo en `bg-base-200`.
- **Confirmaciones** (`x-modal`, las mismas en las dos pantallas): «Cerrar la carga de <departamento> en <campus>» con un `alert-warning` «Al cerrar, los N grupos activos quedan confirmados y la carga ya no se edita: horarios, docentes, salones y cupos quedan fijos. Solo Subdirección puede reabrirla.», «Pasan con justificación N grupos: X sin docente y Y sin salón.» y «Comentario (opcional)»; «Reabrir la carga…» con «Motivo» obligatorio (el botón se habilita al escribirlo; de una publicada dice que se sigue viendo la versión publicada); «Dar el visto bueno…» con comentario opcional, solo en un campus que lo exige.
- **Vetos:** lo que rechazan las guardas al cerrar, reabrir o volver a asignación se ve en un `alert-error` sobre el estado («No se cerró: la carga sigue en Asignación.» y cada motivo en palabras, sin ids de regla) y en un toast.

### Avance del cierre
El tablero de Subdirección (`staffing::pages.closing-board`, `/staffing/closing-board`, Académico › Avance del cierre; tarea 4.2). El usuario eligió la maqueta E el 10 de noviembre de 2026 (`.impeccable/mocks/cierre/campus-e.html`); tiene la forma del avance de validación. Lo ven quienes reabren cargas (Subdirección y el admin).

- **Encabezado:** el título y «Ene-Jun 2027 · 12 departamentos · 18 cargas en C1 y C2».
- **Cifras de todo el Tec** (cuatro celdas, dos por renglón en celular): cargas con faltantes, listas para cerrar, cerradas que faltan de publicar y publicadas, cada una «N de M» con la cifra en Mono de 26 px, como las de «Choques y avance». Cada celda es un botón que filtra la matriz por su cubeta, como «Estado», con `aria-pressed`; la elegida va en gris polvo con un filete `primary` de 3 px abajo y su nombre en negritas, y tocarla otra vez quita el filtro. El filtro va en la URL (`?estado=faltantes`, `listas`, `cerradas` o `publicadas`). Una carga en propuesta o en ajustes sin faltantes cuenta con las que tienen faltantes: antes de cerrar pasa por asignación.
- **Barra:** «Clave o departamento» y el filtro «Estado» (Con faltantes, Listas para cerrar, Cerradas, Publicadas: deja los departamentos con alguna carga así) y, a la derecha, «Choques recalculados hoy a las H:MM» (el más viejo de los campus, y cuáles no se han recalculado): los choques duros del tablero son los vigentes de `schedule_conflicts`, no el validador, que tardaría segundos por carga; el veto sigue decidiendo al cerrar.
- **Matriz** desde 1024 px: un renglón por departamento (clave en Mono, nombre y «N grupos · <jefe>»: quien tiene vigente pasar la carga a asignación, que solo tiene la jefatura, o «Sin jefe vigente» en `warning` con su icono, como en el Organigrama; en los renglones apilados, debajo del nombre), la columna «Campus» con una insignia por carga y las columnas Choques duros, Sin horario, Sin docente, Sin salón, Otros (docente dado de baja, solicitudes y acuerdos) y Justificados, cada cifra como insignia con su icono o un guion tenue; al pie, «Todo el Tec · N grupos», cuántas cargas y las sumas. La columna del departamento queda fija al desplazar la matriz de lado dentro de su caja (por debajo de unos 1370 px no cabe), como en «Avance de validación», y la de «Campus» mide al menos 16rem para que dos insignias de tres cifras quepan en un renglón. Debajo de 1024 px, renglones apilados: las insignias de campus bajo el nombre (junto a él desde 640 px) y las cifras que no son cero, cada una con su nombre («122 sin docente», «22 otros»), porque ahí no hay encabezados. Un departamento sin grupos en ningún campus va al final, atenuado, con «Sin grupos en ningún campus». A una carga publicada no se le cuentan faltantes. Una leyenda explica las insignias, también la de `info`.
- **Insignia de una carga:** el código del campus con el icono de su estado y la cifra que importa: «C2 · 21» con faltantes, en grupos distintos (`warning`, o `error` con `o-x-circle` si alguno es choque duro; sin cifra si solo faltan trámites, que el `aria-label` dice), «C1» con `o-check` lista para cerrar, con `o-lock-closed` cerrada, «C1 · v2» publicada (`success-solid`) y, en propuesta o ajustes sin faltantes, «C2» en `info`. El campus y el estado van en `aria-label` y `title`. Es un botón: abre el drawer de esa carga; tocar el renglón abre la primera.
- **Drawer de la carga** (`max-w-2xl`): el departamento y «N grupos en 2 campus · periodo»; el selector de campus (un `join` con el código, el nombre y la insignia del estado; vertical en celular); la línea de estados; la línea del estado en una franja de su tono; la publicación, a todo el ancho y con la línea de versiones vertical porque el drawer es angosto (la misma de «Cierre de la carga»: al publicar, el drawer y la matriz se vuelven a leer); las pestañas «Para cerrar» (con los grupos que faltan, o los trámites si solo faltan esos), «Justificaciones» («4/11», de solo lectura: se escriben en «Cierre de la carga») e «Historial», que cambian en el navegador; y al pie, fijo, «Abrir en Cierre de la carga» y las acciones de la carga, con el mismo modal de confirmación encima del drawer (Esc cierra solo el modal). Aquí lo que falta sí sale del validador, para esa carga. Tocar un renglón abre la primera carga que sigue sin cerrar (la primera, si todas pasaron el cierre); cerrar el drawer con Esc o con el velo olvida la carga y sus modales.

### Publicación
El componente de publicar la carga de un departamento (`academic-load::components.publish-load`, tarea 4.3). Lo eligió el usuario el 10 de noviembre de 2026: la maqueta B, «Línea de versiones» (maquetas en `.impeccable/mocks/publicar-carga/`, galería https://claude.ai/artifact/EnemqYXNDGXVG28i9YEsfR). Lo montan la pantalla de cierre del departamento y el tablero de Subdirección.
- **La línea:** un punto por publicación, con su versión en Mono dentro del círculo, la fecha y cuántos grupos tenía. La vigente va rellena en `success` y dice «vigente»; las anteriores van con borde `success`. La siguiente aparece punteada en `primary` y es el botón: «Publicar» la primera vez, después «Publicar versión N» con «Reemplaza a la vN en la consulta». Si no se puede publicar, ese último punto va gris, con candado, «No se puede publicar» y el motivo escrito debajo: permiso, ya publicada, sin cerrar o falta el visto bueno. La línea decide por el ancho de su caja, no por la pantalla (container query `@2xl`, 672 px): con espacio va de lado y se desplaza dentro de su caja; angosta (celular, drawer o columna) va vertical. Se monta como bloque propio a todo el ancho, nunca dentro de una fila de botones.
- **Detalle de una versión:** tocar un punto abre debajo quién publicó, cuándo («a las 10:31», en Mono), el comentario y unas pastillas neutras con los grupos, las sesiones y lo que cambió contra la anterior (grupos que entraron y salieron, cambios de docente y de salón). Tocarlo otra vez lo cierra.
- **Confirmación** (`x-modal`):
  - cuatro cifras (grupos, sesiones, docentes y salones), con la diferencia contra la versión vigente;
  - los grupos sin docente, que salen como «Por asignar»;
  - qué pasa con la consulta pública y con el historial docente;
  - un comentario opcional y «Publicar versión N».
  - Un veto (HC-15) aparece arriba, en un aviso de error con sus motivos, y la carga no se publica.

### PDF publicados
Los PDF de lo publicado (tarea 4.5; [ADR-0021](docs/decisiones/0021-dompdf-para-los-pdf-publicados.md)), con el formato que eligió el usuario el 10 de noviembre de 2026: tabla tipo SII para el semestre y el departamento, y semana para el docente y el salón. Van en carta horizontal y los arma DomPDF desde `app/Modules/AcademicLoad/Views/pdf/`. Usan DejaVu Sans y DejaVu Sans Mono, que vienen con DomPDF, en lugar de Atkinson, y los colores de este documento escritos en la plantilla, porque el PDF no carga `app.css`.

- **Marco:**
  - Arriba, «Nexus · Horarios» y «Instituto Tecnológico de Ciudad Juárez» a la izquierda, y el campus y el periodo a la derecha, sobre la franja roja de 2 pt.
  - Abajo, «Horarios oficiales del Instituto Tecnológico de Ciudad Juárez · Actualizado el …» y el número de página.
  - El título es el nombre de la carrera, del docente o del salón, y debajo va su dato (semestre y retícula, número de grupos o edificio).
- **Semestre (y departamento):**
  - Columnas Grupo, Docente y una por día del perfil del campus.
  - Un renglón por materia, con fondo `#f6f3f2` y una franja de 4 pt con su color `chain-N`: la clave en Mono negrita, el nombre en negrita y luego «5 créditos · 3 grupos».
  - Debajo, un renglón por grupo con la misma franja: «CLAVE · grupo» en Mono y el docente o «Por asignar» en tenue. En cada día, las horas en Mono negrita y debajo el salón, «Virtual» o «Por asignar». La práctica lleva la marca «PRÁCTICA» en azul información. Un día sin clase lleva una raya tenue.
  - Ningún renglón se parte entre páginas.
- **Semana (docente y salón):**
  - «N horas a la semana» y una tabla con la hora en Mono y una columna por día.
  - Cada sesión es una caja con la franja de 3 pt de su materia sobre `#f6f3f2`, que abarca sus bloques. Lleva «CLAVE · grupo», la materia y, en tenue, las horas con el salón (docente) o el docente (salón).
- **Lo que nunca sale:** cupos, capacidad, versiones, estados, justificaciones ni el RFC, igual que en la consulta pública.

## Elevation & Depth

Plano. La profundidad la dan las superficies (blanco sobre platino) y los filetes, no las sombras.

### Shadow Vocabulary
- **Flotante** `0 16px 40px rgba(9,45,72,.24), 0 2px 6px rgba(9,45,72,.12)` (`shadow-float`): solo lo que flota sobre el contenido (la tarjeta de la credencial, menús desplegables, modales).
- **Velo** `rgba(9,45,72,.35)` (`bg-band/35`) detrás del menú abierto y de los modales.

### Named Rules
- **Sin tarjetas con sombra para agrupar.** Una tabla o un formulario va en una superficie blanca con filete; nunca tarjetas dentro de tarjetas.

## Shapes

Radios contenidos: insignias 4 px, campos y botones 6 px, tablas y superficies 8 px, la tarjeta de la credencial 10 px. El avatar y el botón de la credencial son los únicos elementos redondos.

## Components

### Buttons
- **Principal** (`btn-primary`): azul espacio, texto blanco, 40 px de alto. Una por pantalla.
- **Secundaria** (`btn-ghost`): texto azul sin fondo; las acciones de fila.
- **Deshabilitada:** se muestra con su motivo, no desaparece. Un botón suelto lo lleva en un tooltip («Solo el administrador da de alta campus.»); un elemento del menú «Acciones» lo lleva escrito en un segundo renglón (ver "Acciones de fila").

### Confirmar
Nunca los diálogos del navegador (`confirm()`, `alert()`, `prompt()` ni `wire:confirm`; [ADR-0023](docs/decisiones/0023-sin-dialogos-nativos-del-navegador.md)). Lo que pide confirmar usa el diálogo de Core (`x-core::confirm-dialog`, uno por layout): el botón o el elemento de menú lleva `x-confirm="<consecuencia en una frase>"`, `data-confirm-title` («¿Desactivar el campus C2?»), `data-confirm-button` (el verbo: «Desactivar») y `data-confirm-tone` (`error` si borra o desactiva, `warning` si cambia algo que otros ven, `primary` lo demás). Es un `x-modal` de 448 px como máximo: el título en negrita, la consecuencia en texto secundario, «Cancelar» (`btn-ghost`) y el botón del tono. El foco abre en «Cancelar»; Esc o un clic fuera cancelan. En celular los botones van apilados a todo el ancho, de 44 px, con el de la acción arriba. Lo que necesita mucha información para decidir va en un `x-drawer`, no aquí. `x-confirm` va solo en botones y dentro de Alpine (un componente Livewire o un `x-data`): un enlace con `wire:navigate` navega antes del clic y no alcanza a confirmar (`row-menu-item` con `href` lo rechaza), y fuera de Alpine la directiva no corre y la acción se haría sin preguntar. El diálogo se anuncia como `alertdialog` con su título y su consecuencia.

### Filtro segmentado
El de referencia es el de Asignación docente ([`state-filter.blade.php`](app/Modules/Staffing/Views/assign/state-filter.blade.php)), y los demás filtros de una sola elección entre pocas opciones lo copian: un `join` de botones `btn` con `role="group"` y su `aria-label`, cada opción con `aria-pressed` y la elegida en `btn-primary`; la etiqueta y, si la hay, su cifra en Mono compacto. Nunca desborda su caja: en una caja angosta (debajo de 768 px, o la variante compacta en una columna lateral) son tres columnas iguales (`grid grid-cols-3`, `min-w-0`) de 44 px o más, con la etiqueta sobre la cifra; desde 768 px van en un renglón de 40 px.

El **campus de una pantalla** (`<x-core::campus-filter>`, con el concern `WithCampusFilter`, #415) es este mismo filtro con los nombres de los campus donde trabaja el usuario y, si la pantalla puede mostrarlos todos juntos, «Todos» al principio (`?campus=<código>`); con más de cuatro botones (contando «Todos») es un select, y con un solo campus no se pinta. Si no cabe, se desplaza de lado dentro de su caja, como el de Staffing.

### Insignias de estado
Rectangulares, de 26 px, con icono y texto, iguales en toda la aplicación. Se pintan siempre con el componente de Core `<x-core::status-badge>`, nunca con `x-badge` y clases sueltas: su `tone` (el enum `StatusTone` de Core o su valor) pone las clases y el icono de la tabla, `label` es obligatorio porque el color nunca va solo, el icono queda oculto a los lectores de pantalla y `icon` lo cambia cuando la tabla da dos.

```blade
<x-core::status-badge tone="success" label="Activo" />
<x-core::status-badge tone="warning" label="Con advertencia" icon="o-exclamation-triangle" />
```

Un módulo con estados propios los traduce a un tono con `tone()` en su enum, como `BatchStatus` de Imports: `<x-core::status-badge :tone="$batch->status->tone()" :label="$batch->status->label()" :icon="$batch->status->icon()" />`.

| Estado | `tone` | Clases | Icono |
|---|---|---|---|
| Activo, Validado | `success` | `badge badge-success badge-soft` | `o-check-circle` |
| Publicado | `success-solid` | `badge badge-success` | `o-check-circle` |
| Inactivo | `neutral` | `badge badge-neutral badge-soft`; además la fila entera pasa a `text-neutral` y peso normal | `o-minus-circle` |
| Borrador | `draft` | `badge badge-dash` | `o-pencil` |
| En revisión, en cola | `info` | `badge badge-info badge-soft` | `o-eye` / `o-clock` |
| Pendiente, Con advertencia | `warning` | `badge badge-warning badge-soft` | `o-clock` / `o-exclamation-triangle` |
| Con error, Choque | `error` | `badge badge-error badge-soft` | `o-x-circle` |
| Periodo actual | `primary` | `badge badge-primary` | — |

Los contadores (por ejemplo, «12 filas») van en `badge-neutral badge-soft` con cifra Mono.

### Inputs / Fields
Los de MaryUI: 40 px en barras de herramientas y 44 px en la credencial y en celular. Borde de filete, fondo blanco y foco visible. Las etiquetas van arriba, en español, con el error asociado debajo.

### Navigation
- **Entrada de menú:** su nombre, como en la pantalla de referencia; el icono de la entrada no se pinta (lo llevan las áreas). La activa lleva fondo gris polvo, peso 700, el punto rojo y `aria-current="page"`. Las cifras de registros irán a la derecha en Mono cuando haya de dónde salgan; hoy no se muestran.
- **Áreas:** Académico, Servicios escolares, Recursos humanos, Recursos financieros, Datos. Cada una con su icono; el orden lo define el enum `NavigationArea` (ADR-0015). El **área actual** es la de la pantalla; una pantalla hija sin entrada propia (la ficha de un alumno, `students.show`) se declara en `childRoutes` de la entrada de su listado, que la ubica con `aria-current="true"` (la página misma lleva `"page"`); si la pantalla no está en el menú, la primera.
- **Iconos de área:** son enlaces, para ir rápido de un área a otra sin abrir el menú. El de otra área lleva a la última página que visitaste de ella en tu sesión (con su consulta: `/students/<id>?tab=kardex`), o a su primera entrada si no has visitado ninguna; el del área actual, a su primera entrada (la principal). Cuenta como visita cada página completa cuya entrada (o pantalla hija) ves; al cambiar el campus o el periodo se olvidan, porque una ficha de otro campus ya no se abriría. Con el menú cerrado, al pasar el cursor se ve el nombre del área en un tooltip a la derecha. El menú completo lo abren solo la liebre, Ctrl K y el buscador de la banda.

### El menú que se transforma (firma)
Un solo menú, no dos:
- **Al abrir** (la liebre, Ctrl K o el buscador de la banda):
  1. el panel se despliega como cortina;
  2. el buscador entra de inmediato, con el cursor;
  3. los iconos de área viajan a los encabezados de sus columnas y las secciones del área actual a la suya;
  4. cuando cada icono aterriza, aparecen su nombre y sus secciones.
- **Al cerrar** (Esc, la liebre o un clic fuera), en un solo tiempo: la cortina se recoge, lo que se queda (los iconos y el área actual) regresa a su lugar junto con ella y lo de las otras áreas se desvanece a la vez, sin salir de la cortina. El foco regresa a la liebre.
- **El buscador** filtra las entradas por su nombre, sin importar mayúsculas ni acentos; las que quedan suben en su columna, las áreas sin ninguna se ocultan y, si no queda nada, dice «Sin resultados». Enter abre la primera. Por ahora solo busca pantallas del menú, así que dice «Busca una pantalla…».
- **Al ir a otra pantalla** desde el menú, la página nueva llega con el menú cerrado.

Detalle en "Movimiento", abajo, y en la pantalla de referencia.

### Movimiento
| Momento | Curva | Duración |
|---|---|---|
| Abrir (menú, credencial) | `cubic-bezier(.3, 1.06, .5, 1)`, rebote apenas perceptible | 0.55 s; elementos nuevos 0.32 s en cascada |
| Cerrar y regresar | `cubic-bezier(.22, 1, .36, 1)`, sin rebote | 0.42 s, todo junto y sin cascada; lo que se va se desvanece en 0.12 s a la vez |
| Salto de la liebre al abrir | la misma de abrir | 0.5 s, 4 px |

- Solo se anima `transform`, `opacity` y `clip-path`.
- Con `prefers-reduced-motion`, todo cambia sin animar.
- Lo que se mueve lleva el punto que el ojo sigue (el centro del icono, el principio del texto), no el borde de su caja, y lo que espera para moverse se queda en su lugar inicial mientras espera (`fill: 'backwards'`): nada brinca al empezar.
- Nunca dos movimientos del menú encimados: lo que se pide mientras uno corre (Esc justo después de abrir) se hace al terminar. Esc cierra solo la capa de arriba: la credencial si está abierta sobre el menú; si no, el menú.
- **Implementación:** el componente Alpine `nexusShell` (`resources/js/app.js`) hace el FLIP del menú con la Web Animations API (al cerrar, lo que se va se desvanece en una copia del menú abierto, sin nada interactivo, que se quita al terminar); la cortina, el velo y la tarjeta son transiciones de utilidades de Tailwind. El estado vive en `<body data-menu>` y las utilidades lo leen con `in-data-[menu=open]:`; la geometría por ancho son variables que declara `<body>`. La única regla del shell escrita a mano en `resources/css/app.css` bloquea el desplazamiento con el menú abierto (el candado de daisyUI vive en `:root`, donde no llega una utilidad).
- La palabra gigante y los mosaicos vivos son para los inicios por rol, nunca para pantallas de captura.

### La liebre
- **Archivo:** la silueta oficial del Tec, `resources/images/liebre.png` (original) y `liebre-512.png` (recortada, 512 px).
- **Uso:** como máscara CSS, para que tome el color del lugar: blanca sobre el cuadro rojo.
- **Animación:** se mueve completa. Las orejas se animarían por separado solo con un SVG que las tenga como piezas aparte.
- Es la única marca: no hay otro logo en la banda.

### Pantalla de acceso
El login G (tarea 1.17), elegido con maquetas de impeccable. Es la única pantalla con identidad propia fuera del shell; las demás siguen el resto de este documento.
- **Composición:** en escritorio (desde 1024 px), la foto con los avisos a la izquierda, fija, y a la derecha un panel blanco de `clamp(460px, 42vw, 720px)` con los logos del TecNM y del ITCJ pegados arriba, la marca Nexus centrada entre los logos y el formulario, y el formulario. En celular, primero el panel y debajo una tarjeta «Avisos» con la foto arriba y el título del aviso abajo.
- **Solo claro:** el layout de invitado con `bare` fija `data-theme="nexus"`; no hay versión oscura del login.
- **Colores:** los de este documento. El botón Entrar es rojo Tec (`btn-accent`): es la excepción a «el rojo es firma», solo en el login. Los logos son máscaras de una tinta (`resources/images/login/tecnm.webp` e `itcj.webp`): el del TecNM en azul espacio y el del ITCJ en rojo Tec.
- **Marca:** «Nexus» con la letra Agbalumo (licencia OFL) pasada a vector en `core::login.mark`, con relleno rojo Tec y contorno blanco y azul espacio, como el logo de Liebres. No se carga la fuente.
- **Avisos (LG-01):** tarjeta semitransparente (`rgb(236 241 246 / 0.5)` con desenfoque) arriba a la derecha de la foto, solo con el título; debajo, la cápsula de puntos, donde el del aviso actual se llena en 7 s. Un aviso sin foto va sobre azul espacio con la cola de la marca en rojo, y su texto en blanco. El carrusel se detiene con el mouse encima o con el foco dentro.
- **Atajos (LG-02):** botón blanco translúcido «Atajos» abajo a la izquierda de la foto (en celular, sobre la orilla de abajo de la foto). Abre la lista en el lugar del formulario; las opciones caen de arriba hacia abajo una tras otra con un rebote corto, y «Iniciar sesión» al final regresa.
- **Bienvenida:** la primera vez en el equipo la foto se abre desde la orilla, los logos suben y la marca se escribe de izquierda a derecha con un barrido inclinado (1.7 s en total); después, solo la marca, en 0.46 s. Cualquier tecla o toque la termina. Vive en `nexusLogin` (`resources/js/login.js`), que solo carga el login.
- **¿Olvidaste tu contraseña?:** enlace bajo la contraseña, junto a «Recordarme», que lleva a la pantalla para pedir el enlace por correo (AC-16). Esa pantalla y la de elegir la contraseña nueva van en el layout de invitado centrado, en una tarjeta, como el cambio de contraseña.

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
