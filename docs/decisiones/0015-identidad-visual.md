# ADR-0015 — Identidad visual de Nexus

**Estado:** Aceptada · **Fecha:** 2026-10-29

## Contexto
`DESIGN.md` era un borrador con una paleta provisional (guinda y gris) y el layout tal como sale de MaryUI. Antes de que B y C construyan sus catálogos (3 de noviembre), el equipo definió la identidad que será norma en toda la aplicación (issue #108).

- Nexus empieza por la carga académica, pero apunta a sustituir todo el SII: servicios escolares, recursos humanos y recursos financieros ([PRODUCT.md](../../PRODUCT.md)). El diseño tiene que servir a quien usa 3 opciones (un docente, un alumno en el celular) y a quien usa decenas de módulos en varias áreas.
- Se usa en PC de oficina con monitores de todo tipo, en laptop, proyectado en juntas y en el celular.
- Se exploraron direcciones con impeccable: credencial, retícula, alta densidad, mosaico, cartelera y el estándar de la categoría. La elección quedó en la ficha de superficie (`.impeccable/surfaces/`) y la pantalla aprobada en `docs/diseno/pantalla-de-referencia.html`.

## Decisión
| Tema | Decisión |
|---|---|
| Estructura | **La credencial con áreas.** Arriba, una banda con la identidad de la sesión; a la izquierda, un menú por áreas (Académico, Servicios escolares, Recursos humanos, Recursos financieros, Datos). Las pantallas de trabajo son tablas densas y formularios de MaryUI. |
| Banda | Cuadro rojo con la liebre (botón del menú), «Nexus», la ruta (área › pantalla), el buscador «Buscar o ir a… Ctrl K» y, a la derecha, **la credencial**: un botón con nombre, rol, campus y periodo que se abre como tarjeta para cambiar campus y periodo, el tema, la contraseña y cerrar sesión. Reemplaza los selectores sueltos y el menú de usuario. Se eligió entre tres bandas (credencial, buscador al centro, una frase). |
| Menú | **Un solo menú que se transforma.** Cerrado (288 px): franja de iconos de área y las secciones del área actual. Al tocar la liebre o Ctrl K se expande en su lugar: los iconos se vuelven encabezados, las secciones viajan a su columna, aparecen las demás áreas y el cursor queda en el buscador. Esc, la liebre o un clic fuera lo cierran. Solo aparecen las áreas con al menos una entrada visible para el usuario. En celular, la liebre baja el menú como una hoja con el buscador. |
| Marca | La liebre, mascota del Tec (`Liebre Solida.png`, silueta), usada como máscara para que tome el color del lugar: blanca sobre el cuadro rojo. Sin otro logo en la banda. |
| Paleta | Azul espacio profundo `#092d48` (banda, texto, acción principal), rojo `#c2201e` (liebre, franja de 3 px bajo la banda, punto de «estás aquí»), platino `#f3f4f7` (fondo), gris polvo `#e6dedb` (superficies de navegación y selección), gris tenue `#666567` (texto secundario). Aproximaciones de los colores institucionales hasta tener los códigos oficiales. |
| Reglas de color | El rojo de marca nunca va en texto, insignias ni botones: así no se confunde con el rojo de error, y los errores llevan siempre icono y texto. Sobre gris polvo, el texto secundario usa `#59585a`, porque el gris tenue da 4.4:1. |
| Estados | Insignias rectangulares con icono, iguales en toda la aplicación: Activo, Validado (verde suave), Publicado (verde sólido), Inactivo (gris; la fila entera se aclara), Borrador (borde punteado), En revisión (azul), Pendiente y Con advertencia (ámbar), Con error y Choque (rojo de error con ✕), Periodo actual (color de marca). El color nunca va solo. |
| Tipografía | Atkinson Hyperlegible Next para todo y Atkinson Hyperlegible Mono para claves, cifras y horarios. Están diseñadas para leerse bien a distancia y en pantallas pobres, lo que sirve para el proyector. Reemplaza Instrument Sans. |
| Movimiento | Una sola gramática: 0.4 a 0.5 s con un rebote ligero, solo con `transform` y `opacity`. La firma es el menú que se transforma. La palabra gigante y los mosaicos vivos quedan para los inicios por rol, no para las pantallas de captura. Si el sistema pide reducir movimiento, todo cambia sin animar. |
| Tema oscuro | Se conserva, con la misma paleta llevada a fondos azul noche; tonos y contrastes medidos en `DESIGN.md`. |
| Responsivo | Escritorio (≥ 1200 px) con iconos y secciones; tableta (768 a 1199 px) solo con iconos y el menú abierto en 3 columnas; celular (< 768 px) con banda compacta, el menú como hoja y las tablas en renglones apilados. El contenido llega hasta 1680 px de ancho. |
| Error | El rojo de error es `#b42318`, distinto del rojo Tec, y la advertencia es ámbar oscuro (`#8a5a00`) para que su texto pase AA. |

## Consecuencias
- **Core:** `NavigationItem` agrega el área a la que pertenece y un enum `Area` (etiqueta e icono) define el orden. Cada módulo declara el área de sus entradas. `NavigationTest` cambia porque el menú se agrupa.
- **Layout:** `resources/views/layouts/app.blade.php` deja `x-nav` y el cajón de MaryUI por la banda y el menú propios. El selector de contexto (`core::components.context-switcher`) pasa a la tarjeta de la credencial, con la misma Action (`SetActiveContext`). El movimiento del menú usa Alpine, que ya viene con Livewire; no agrega dependencias.
- **Tokens:** `resources/css/app.css` cambia los temas `nexus` y `nexus-dark`, y agrega como tokens la curva de movimiento y la máscara de la liebre. Las fuentes se cargan como Instrument Sans hoy, con el plugin de fuentes de Vite.
- **DESIGN.md** es la norma desde este ADR: paleta con contrastes medidos, tipografía, estructura, anatomía de una pantalla, insignias, movimiento, responsividad y la convención para hacer pantallas. Al construir el shell se revisa contra lo construido.
- **Construcción** en PRs encadenados de #108:
  1. Tokens, tipografía, liebre e insignias.
  2. Áreas en la navegación (Core) con sus pruebas.
  3. Banda, credencial y menú que se transforma, con prueba de navegador del menú (abrir, buscar, Esc).
  4. Pantalla de Campus con el nuevo estilo, revisión final de impeccable y `DESIGN.md`.
- **Fuera de este trabajo:** los inicios por rol con la palabra gigante y los mosaicos vivos, el diseño del editor de horarios (fase 3), el formato de los PDF y los códigos oficiales de color.

## Alternativas descartadas
- **Estándar de panel de administración** (barra lateral oscura, tarjetas con sombra): es la plantilla genérica que el equipo quería evitar.
- **Mosaico completo** (títulos gigantes en todas las pantallas): no aguanta decenas de secciones y le quita espacio a cada pantalla de trabajo.
- **Cartelera y retícula:** sirven con pocos registros, no con 550 docentes o miles de alumnos.
- **Dos menús** (barra lateral más un mapa encimado): se leía como dos navegaciones distintas. Se cambió por un solo menú que se transforma.
- **Ciruela o petróleo como marca:** la ciruela cae en el violeta típico de las interfaces hechas con IA. El petróleo funcionaba, pero el equipo prefirió los colores institucionales.
