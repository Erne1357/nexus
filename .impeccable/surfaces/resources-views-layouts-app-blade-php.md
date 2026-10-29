---
version: 1
slug: "resources-views-layouts-app-blade-php"
primary_target: "resources/views/layouts/app.blade.php"
related_targets: ["app/Modules/Core/Livewire/pages/campuses/campuses.blade.php","app/Modules/Core/Livewire/components/navigation/navigation.blade.php"]
---

# Shell de Nexus y catálogo de referencia (Campus)

Modo: Operate. Superficie: el layout autenticado (banda, menú) y la pantalla de Campus, que copian todos los catálogos.
Audiencia: jefes, coordinadores, Subdirección y administración en PC de oficina, laptop y proyector; docentes y, desde R3, alumnos en celular.
Tarea: moverse entre muchas áreas y secciones de un ERP académico sin perderse, leer tablas densas y saber siempre en qué campus y periodo se trabaja.
Restricciones: MaryUI sobre daisyUI y Tailwind 4, identidad solo con tokens; tema claro y oscuro; AA en todo texto; responsivo de 360 px al proyector; nada oculto por permiso.
Momento memorable: la liebre abre el menú y el menú mismo se reacomoda: los iconos de área se vuelven encabezados y las secciones viajan a su columna.
Pendiente: inicio por rol con la palabra gigante y los mosaicos vivos (otra superficie), códigos oficiales de color, formato de PDFs.

## Direction contract

THESIS: La sesión porta su credencial y el menú es un solo mapa que se despliega en su lugar. Rechaza la plantilla de panel con barra lateral oscura, tarjetas con sombra y un segundo menú que tapa al primero.

OWN-WORLD: Azul espacio profundo #092d48 en la banda, el texto y la acción principal; rojo #c2201e solo en la liebre, la franja de 3 px bajo la banda y el punto de "estás aquí"; platino #f3f4f7 de fondo, gris polvo #e6dedb en superficies de navegación y selección, gris tenue #666567 para texto secundario (#59585a sobre gris polvo). Atkinson Hyperlegible Next para todo y su Mono para claves y cifras. Insignias rectangulares con icono; tablas planas con filete.

STORY: Quien entra ve en la banda dónde está (área › pantalla) y su credencial (nombre, rol, campus, periodo). Trabaja en tablas densas. Cuando busca algo fuera de su área, toca la liebre o Ctrl K: el menú se expande en su lugar con el buscador listo y todas sus áreas.

FIRST VIEWPORT: Banda de 64 px: cuadro rojo de 72 px con la liebre blanca (botón del menú), «Nexus», ruta del área y la pantalla, buscador «Buscar o ir a… Ctrl K» y, a la derecha, la credencial en un botón que se abre como tarjeta (campus, periodo, tema, contraseña, cerrar sesión). Franja roja de 3 px. Menú cerrado de 288 px: franja de iconos de área en gris polvo y las secciones del área actual. Contenido: título, descripción, búsqueda, filtro y acción principal; tabla de Campus con claves en Mono e insignias de estado.

FORM: La credencial institucional (candidato 6 de la lista propia), con el movimiento de Mosaico (reto metro-typographic-tiles) llevado al menú y a los inicios; seed:11f10160193257c0. Firma: el menú que se transforma (FLIP con transform y opacity, 0.5 s con rebote ligero; sin movimiento si el sistema lo pide).

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
