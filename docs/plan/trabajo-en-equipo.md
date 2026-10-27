# Trabajo en equipo: tres personas en paralelo

Cómo se organiza el equipo desde la fase 1 (3 de noviembre de 2026) para llegar a la fecha de R1 sin pisarse. Las fases 1 a 4 ya traen reparto por tarea; este documento fija las reglas comunes.

## Roles

| Rol | Quién | Notas |
|---|---|---|
| **A** | Quien hizo la fase 0 | Conoce `Core`, `ScopedContext`, la convención de módulos y la CI. Es la referencia cuando algo de la base no queda claro. |
| **B** | Por asignar | Perfil con más pantallas y UI: retícula, validación, editor de horarios, consulta publicada. |
| **C** | Por asignar | Perfil de reglas y flujos: docentes, ranking, asignación, cierre, seguridad. |

Los nombres reales se asignan en la **reunión de arranque del 2 de noviembre**. Las letras se usan en los archivos de fase y en los issues hasta entonces, y se conservan como alias después.

## Ownership por módulo y fase

| Fase | A | B | C |
|---|---|---|---|
| 1 Catálogos e importación | `Imports` (motor y asistente), `Students` | `Academic` (modelos, CRUD, retícula, plantillas, `DemoSeeder`) | `Faculty`, `Facilities`, provisión de usuarios |
| 2 Validación y periodo | `Core` periodos y `OpenTerm`, `AcademicLoad` máquina de estados | `CatalogValidation`, `Faculty` perfil y disponibilidad | `AcademicLoad` propuestas y bandeja, `Core` notificaciones |
| 3 Captura de carga | `Scheduling` núcleo (modelos, validador, creación de grupos, tablero de choques) | `Scheduling` editor de horarios, cupos, salones y préstamos | `Staffing` completo (ranking, asignación, vista docente, solicitudes, carga por docente) |
| 4 Cierre y piloto | Publicación y snapshots, exportación al SII, despliegue y respaldos | Consulta publicada y PDFs/Excel | Cierre, visto bueno, endurecimiento |

Quien es dueño de un módulo en una fase decide su estructura interna, responde las dudas sobre él y revisa los cambios que otros le hagan. El ownership rota entre fases a propósito, para que al terminar R1 cada quien conozca más de un módulo.

## Ritmo semanal

| Día | Qué | Duración |
|---|---|---|
| Lunes | Planeación: cada quien toma issues del milestone de la fase activa en el orden sugerido del archivo de fase; se detectan dependencias entre personas para la semana | 30 minutos |
| Martes a jueves | Avance asíncrono: cada issue en curso recibe un comentario breve al final del día (qué quedó, qué bloquea) | Sin reunión |
| Viernes | Integración en `develop`: todo lo terminado está mezclado; demostración de 20 minutos con el `DemoSeeder` o datos reales; revisión de alcance contra la fecha de R1 y rebalanceo si hace falta | 45 minutos |

Las fases de R1 se traslapan: quien termina su parte de la fase N arranca la N+1 el mismo día, sin esperar a los demás.

## Contratos primero

Cada fase empieza con un **PR pequeño de contratos** que publica lo que otros necesitan para avanzar sin esperar la implementación completa:

- Fase 1: contrato de plantilla de importación y una plantilla de ejemplo (A, día 1); eventos `BatchApplied` y `TermOpened`; `EligibleStudentsQuery` como interfaz con una implementación provisional.
- Fase 2: Actions de transición de `academic_loads` con sus firmas y guardas vacías (A); evento de propuesta enviada.
- Fase 3: interfaz del validador de restricciones y una regla de ejemplo (A, antes del día 4), para que el editor (B) y la asignación (C) lo consuman desde el inicio; `RoomSuggestionQuery` como interfaz.
- Siempre: `ScopedContext` de `Core` es el único punto de alcance; si alguien necesita un método nuevo ahí, lo pide por issue a A y se agrega en un PR aparte.

Un contrato publicado no se cambia sin avisar en el issue a quienes lo consumen.

## Reglas de PR

1. Un PR por tarea del plan. El título sigue el formato de commits convencionales (`feat(staffing): compute teacher ranking`) y el cuerpo referencia la tarea (`Fase 3 · 3.8`) y `Closes #N`.
2. Menos de **400 líneas netas** cuando sea posible; si una tarea no cabe, se parte en dos PRs encadenados.
3. CI verde (`lint`, `tests`, `browser`) antes de pedir revisión.
4. **Revisor cruzado obligatorio**: A revisa a B, B revisa a C, C revisa a A. Si el revisor asignado no puede, lo toma el tercero; nunca se mezcla sin revisión.
5. Revisar en **menos de 24 horas**. Una revisión pendiente tiene prioridad sobre el trabajo propio.
6. El **autor mezcla** su PR después de la aprobación, con "squash and merge", y borra la rama.
7. La descripción del PR usa la plantilla de `.github/` y enlaza los tests que demuestran cada criterio de aceptación.

## Cómo evitar conflictos

- Las migraciones viven **dentro del módulo** con marca de tiempo; dos personas no editan la misma migración. Una migración ya mezclada en `develop` no se modifica: se crea otra.
- **No se edita un módulo ajeno** sin avisar antes en el issue y etiquetar a su dueño; lo normal es pedirle el cambio.
- Los cambios a `Core` (layout, `ScopedContext`, roles, configuración de módulos) se **anuncian antes** de hacerlos, porque afectan a todos.
- Nada de ramas largas: se integra a `develop` **al menos cada dos días**, aunque sea detrás de una condición que no active la pantalla todavía.
- Antes de abrir un PR se actualiza la rama con `develop` y se corre la suite completa en local.

## Worktrees de git por tarea

Cada tarea se trabaja en su propio **worktree** de git, uno por rama, para no mezclar cambios de dos issues en un mismo directorio y poder cambiar de tarea (por ejemplo, para revisar un PR) sin guardar trabajo a medias. La rama se nombra `feature/<número-de-issue>-<slug-corto>`, por ejemplo `feature/42-ranking-docente`. El worktree se borra al mezclar el PR. El skill de worktrees que ya usa el equipo cubre la creación y limpieza.

## Uso de la IA

- **Una sesión de Claude Code por issue.** La sesión empieza leyendo `CLAUDE.md` y el archivo de la fase; el issue ya trae objetivo, dónde va el código y por qué, pruebas y criterios.
- **Brainstorming solo si hay ambigüedad real.** Si la tarea está clara en el plan, se pasa directo a TDD. Si al empezar aparece una duda de alcance, se resuelve en el issue antes de escribir código.
- **TDD siempre**, con el ciclo descrito en `docs/arquitectura/testing.md`: la IA escribe el test desde la regla o el criterio de aceptación, el humano revisa que pruebe lo correcto, luego el código.
- **`/code-review` antes de abrir el PR**, y se atienden sus hallazgos reales; los descartados se justifican en el PR.
- El PR que cierra una tarea o una fase **actualiza el archivo de la fase** (marca la tarea con el PR) y `PLAN.md` (estado y fecha). Esa actualización es parte de la tarea, no un paso aparte.
- La IA no decide alcance ni fechas; eso se decide en la reunión del viernes y queda en `docs/decisiones/`.

## Definición de hecho

Una tarea está hecha cuando todo esto es cierto:

1. Tests en los tres niveles donde aplique (unit para reglas, feature para pantallas y jobs, browser para el flujo crítico de la fase), escritos antes del código.
2. CI verde.
3. Documentación actualizada: archivo de la fase, `PLAN.md`, y `docs/dominio/` o `docs/arquitectura/` si cambió una regla o convención.
4. Toda Action de escritura registra bitácora.
5. Pruebas negativas de rol y alcance presentes.
6. Revisado por el revisor cruzado y aprobado.
7. Mezclado en `develop` y rama borrada.
8. Tarea marcada en el plan con el número de PR.

## Cuando alguien se atrasa

Se detecta el viernes, no después. El orden de respuesta es: primero **rebalancear** (otra persona toma parte de las tareas pendientes de ese módulo), después **recortar alcance de la tarea** (por ejemplo, el editor de horarios por clic en lugar de arrastrar y soltar), y por último mover una tarea no crítica a la fase siguiente. **Nunca se recortan pruebas** ni se salta la revisión para recuperar tiempo. Todo recorte queda anotado en `docs/decisiones/` y en `PLAN.md`.

## Tablero de GitHub

- Un **tablero** del proyecto con columnas **Backlog**, **Listo**, **En curso**, **En revisión**, **Hecho**. "Listo" significa que la tarea tiene todo lo necesario para empezarla (contratos publicados, datos disponibles).
- Un **milestone por fase** con su fecha de cierre; los issues de una fase pertenecen a su milestone.
- Etiquetas: `type:*` (task, epic, feature, docs, chore; los bugs usan `bug`), `module:*` (uno por módulo más `infra`), `release:*` (r1, r2, r3, r4), `priority:*` (p0 bloquea la entrega en curso, p1 importante, p2 cuando haya tiempo). Las incidencias del piloto llevan además `piloto`.
- Cada issue de tarea nace del archivo de fase con el mismo id y el mismo contenido (objetivo, módulo, dónde y por qué, pruebas, criterios); si difieren, manda el archivo de fase y se corrige el issue.

## Releases

- `develop` → `main` por PR cuando la integración de la fase está **probada en el servidor de pruebas** (no solo en CI): despliegue de `develop`, demostración del viernes sin incidencias p0, y revisión del alcance de la fase.
- Cada mezcla a `main` lleva un **tag semántico**: `v1.0.0` es R1 (carga de Campus II publicada), `v1.x` las correcciones del piloto, `v2.0.0` será R2 y `v3.0.0` R3.
- `main` solo recibe PRs desde `develop` (o un hotfix desde `main` que se devuelve a `develop` el mismo día).

## Comunicación con los usuarios del piloto

Desde la fase 2, cuando entran jefes y coordinadores reales, hay **una persona de contacto por semana** (rota A, B, C). Esa persona recibe dudas e incidencias por el canal acordado con Subdirección, las convierte en issues con etiqueta `piloto` y la prioridad correcta el mismo día, y responde al usuario qué se hará y cuándo. Las incidencias `p0` del piloto se atienden antes que cualquier tarea planeada; las demás entran al lunes siguiente. Nadie más del equipo interrumpe su tarea por una incidencia salvo que la persona de contacto lo pida.
