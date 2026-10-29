# Product

<!-- impeccable:product-schema 1 -->

Contexto de producto para las decisiones de diseño de Nexus. Lo visual (paleta, tipografía, componentes) vive en [DESIGN.md](DESIGN.md); el alcance y las fechas, en [PROJECT.md](PROJECT.md) y [PLAN.md](PLAN.md).

## Platform

web

## Users

- **Jefes de departamento y jefes de proyecto docente:** arman y cierran la carga académica de su departamento en un campus. Validan catálogos, asignan docentes y salones y resuelven choques en sesiones largas al inicio de cada semestre.
- **Coordinadores de carrera:** proponen qué materias y cuántos grupos abre su carrera, con los alumnos elegibles calculados.
- **Subdirección académica:** sigue el avance por campus y, si el campus lo pide, da el visto bueno antes de publicar.
- **Docentes:** consultan su propia carga (grupos, horarios, salones) y piden cambios.
- **Administración del sistema:** importa los catálogos del SII y da de alta usuarios.
- **Alumnos (desde R3):** consultan horarios y se inscriben. Usarán sobre todo el celular. En R1 no tienen pantallas, pero el diseño ya debe servirles.

## Product Purpose

Nexus reemplaza por etapas al SII del Instituto Tecnológico de Ciudad Juárez como fuente de verdad académica. La primera meta es la carga académica de Campus II para Ene-Jun 2027: construirla, validarla y publicarla desde Nexus en lugar de Excel. Después vienen el motor de horarios (R2) y la inscripción (R3). Hay éxito cuando la carga se cierra y se publica en Nexus, sin hojas de cálculo en paralelo, y cada decisión queda registrada y verificable.

**Escala a la que apunta.** El SII es en la práctica el ERP del Tec: servicios escolares con su parte legal, recursos humanos y recursos financieros. Nexus empieza por la carga académica, pero la intención es que con el tiempo cubra todo eso. El diseño tiene que aguantar desde quien usa dos o tres opciones (un docente, un alumno) hasta quien usa decenas de módulos en varias áreas, y desde unas cuantas personas hasta toda la comunidad del Tec.

## Positioning

Nexus modela el proceso real del ITCJ, no un sistema escolar genérico:
- cupos por carrera dentro de cada grupo, en lugar de grupos paralelos;
- validación de catálogos por los jefes antes de capturar;
- un validador de choques al editar horarios;
- un ranking docente visible, con el motivo obligatorio cuando se asigna fuera de él;
- un perfil de flujo por campus;
- una bitácora inmutable de cada cambio.

Lo que hoy se acuerda de palabra o en Excel queda explícito y auditable.

## Operating Context

- **Escritorio de oficina:** las PCs de los departamentos tienen monitores de tamaños y resoluciones muy distintos. Ahí se hace la captura larga y densa: tablas, filtros y el editor de horarios.
- **Laptop, también desde casa:** pantallas más chicas.
- **Juntas con proyector:** la carga y los horarios se proyectan y se discuten en grupo, por ejemplo en la junta interdepartamental.
- **Celular:** solo consulta. Lo usan hoy los docentes y desde R3 los alumnos, que consultan sobre todo así.
- **Calendario:** la carga se arma entre noviembre y diciembre para el semestre de enero; el bloque horario presencial es de una hora, de 7:00 a 21:00, de lunes a viernes.
- **Datos:** llegan como exportaciones del SII (Excel o CSV) que se importan con un asistente.

## Capabilities and Constraints

- Interfaz en español, con tuteo ("Elige el archivo que exportaste del SII"), como la que ya existe.
- Altamente responsiva: desde el celular hasta monitores grandes y el proyector. La captura pesada se optimiza para escritorio, pero ninguna pantalla se rompe en móvil.
- Tema claro y tema oscuro.
- Roles con alcance por campus, departamento o carrera. Lo que el rol no permite se muestra deshabilitado con su motivo; lo de otro alcance no se muestra.
- Componentes de MaryUI sobre daisyUI y Tailwind 4; la identidad se aplica con tokens de tema, no con CSS por pantalla.
- Sin decidir todavía: formato de los PDFs publicados y diseño del editor de horarios (prototipo en la fase 3).

## Brand Commitments

- **Nombre:** Nexus.
- **Colores institucionales:** rojo, azul marino y blanco, de la identidad oficial (ITCJ o TecNM). Paleta elegida el 28 de octubre de 2026: azul espacio profundo `#092d48`, rojo `#c2201e`, platino `#f3f4f7`, gris polvo `#e6dedb` y gris tenue `#666567`. Siguen siendo aproximaciones hasta tener los códigos oficiales.
- **Marca:** la liebre, mascota del Tec, es la marca de Nexus; no hay otro logo en la banda. El archivo oficial de la liebre lo da el equipo.
- **Tono:** moderno pero serio, a la altura de un sistema institucional académico.
- **Mascota:** la liebre es también el botón que despliega el menú completo.

## Evidence on Hand

- No hay logo de Nexus ni archivos de logo institucional en el repositorio.
- No hay datos reales del SII en el repositorio. Las exportaciones viven en `storage/app/imports/` y tienen datos personales: nunca van a maquetas, capturas ni fixtures sin anonimizar. Los ejemplos usan nombres y claves ficticios.
- Hay contenido real del dominio que se puede citar: las 6 carreras de Campus II, unos 550 docentes, el bloque horario y los roles (en [PROJECT.md](PROJECT.md) y `docs/dominio/`).

## Product Principles

1. **Primero la tarea.** La gente que usa Nexus tiene poco tiempo y mucha información; la pantalla prioriza leer, comparar y decidir.
2. **Todo verificable.** Cada estado, choque, motivo y cambio se ve donde ocurre y queda en la bitácora.
3. **Nada oculto por permiso.** Si algo no se puede hacer, se dice por qué.
4. **Una sola forma de hacer cada cosa** en toda la aplicación.
5. **Sirve en cualquier pantalla,** del celular al proyector.

## Accessibility & Inclusion

- Contraste WCAG 2.x AA en todo texto, en claro y oscuro; que se mantenga legible al proyectarse.
- Foco visible y navegación por teclado, incluido el editor de horarios.
- El color nunca va solo: choques, bloqueos y estados llevan también su icono o su texto.
