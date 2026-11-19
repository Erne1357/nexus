# Fase 7 — Solver CP-SAT (Python + OR-Tools)

**Entrega:** R2 · **Ventana:** mar – abr 2027 · **Modo:** paralelo · **Estado:** 🟨 (abierta el 17 de noviembre de 2026) · **Milestone:** `Fase 7 — Solver CP-SAT`

## Objetivo

Sustituir la heurística por un motor de optimización real para los casos donde ella se queda corta: un servicio en Python con OR-Tools CP-SAT que recibe el mismo contrato de la fase 6, modela cada regla dura como restricción y cada regla blanda como término del objetivo, y devuelve soluciones sin violaciones duras con mejor puntaje blando. Desde Nexus no cambia nada para el usuario: es un motor más en la lista.

## Dependencias

- Fase 6: contrato versión 1 congelado, `SolverEngine`, `solver_runs`, pantalla de corridas, fixtures compartidos y línea base de la heurística.
- Decisión de infraestructura: recursos del servidor para un contenedor adicional con CPU dedicada durante las corridas.

## Alcance

### Incluye

- Servicio `services/solver/` en Python 3.12 con FastAPI y OR-Tools, con su Dockerfile, pruebas y documentación.
- Servicio `solver` en `compose.yaml` (red interna, sin puerto público, token compartido, healthcheck).
- `CpSatEngine` en PHP que implementa `SolverEngine` llamando al servicio.
- Modelado CP-SAT de HC-01 a HC-13 y SC-01 a SC-08, descomposición y límites de tiempo.
- Benchmark contra la heurística con los mismos fixtures.

### No incluye

- Cambios al contrato (si hacen falta, se versiona en una tarea aparte con compatibilidad).
- Asignación automática de docentes como decisión: la salida puede sugerir docente, el jefe decide.
- Exponer el servicio fuera de la red de Docker.

## Punto de partida (17 de noviembre de 2026)

Ajustes que trae la fase 6 y que cambian lo que dicen las tareas de abajo:

- **El contrato es multi-campus.** Una corrida es la carga de un departamento en una modalidad, en todos sus campus: la entrada trae `campuses[]` (una rejilla por campus), cada grupo su `campus_code`, y el modelo tiene una rejilla de bloques por campus. Los modelos pydantic del servicio espejan los DTOs de `app/Modules/Solver/Contract/`.
- **Cohortes en vez de «alumno tipo» calculado por el motor.** Quien arma la entrada pone `cohorts[]` en cada grupo (HC-03 por plan, semestre y campus, más los paquetes de primer semestre); el motor solo garantiza que los grupos con la misma clave no se empalmen. `current_sessions[]` permite marcar `changed` en la salida.
- **SC-09, traslado entre campus.** Hay nueve reglas blandas, no ocho (`travel_minutes` en la entrada; `busy` de los docentes lleva su `campus_code`): el objetivo de 7.6 incluye SC-09.
- **7.8, descomposición:** sigue el orden que sugiere `PlanRun`; el tronco común primero es una sugerencia, no se exige (`docs/arquitectura/solver.md`, «Descomposición del problema»). Lo que no es de la carga entra como `busy` o como grupos con `movable = false`.
- **7.10, fixtures:** son `tiny`, `small`, `medium`, `infeasible` y `department-multicampus` (carga de un departamento con dos campus); el de tamaño real de Campus II corre fuera del CI de PR.
- **7.11, motor predeterminado por modalidad:** la decisión se guarda en `solver.default_engine` por **modalidad** (ADR-0025), no por campus.
- **Reparto del servicio:** 7.1 a 7.3 (esqueleto, compose y API) traen un **motor de prueba** (`services/solver/app/engine/trivial.py`, equivalente a `FixedEngine` de PHP) para que la API funcione de punta a punta y `CpSatEngine` (7.9) se construya contra ella; el modelo CP-SAT (7.4 a 7.7) viene de otro riel y se registra como motor cuando exista. La dependencia nueva está en [ADR-0027](../decisiones/0027-servicio-del-motor-en-python.md).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 7.1 ✅ [#560](https://github.com/Erne1357/nexus/pull/560) | Esqueleto del servicio: proyecto en `services/solver/` con FastAPI, dependencias fijadas (OR-Tools, pydantic), Dockerfile propio con imagen de Python 3.12 fijada por digest, configuración por variables de entorno (token, límite de tiempo por defecto, hilos), endpoint de salud | Solver (servicio) | Carpeta aparte del código PHP porque es otro lenguaje con su propio ciclo de pruebas. Pydantic valida el contrato con la misma estructura que los DTOs de PHP. | pytest: el servicio arranca y responde salud; rechaza peticiones sin token | A |
| 7.2 ✅ [#560](https://github.com/Erne1357/nexus/pull/560) | Servicio `solver` en `compose.yaml` y `compose.override.yaml`: red `backend` sin puertos públicos, token compartido con `app` y `queue` por variable de entorno, límite de CPU y memoria, healthcheck, reinicio automático; en desarrollo el código montado como volumen | — | Infraestructura. El servicio solo lo llama el contenedor `queue`; nunca el navegador. | `docker compose up` levanta el servicio y `queue` lo alcanza por nombre | A |
| 7.3 ✅ [#560](https://github.com/Erne1357/nexus/pull/560) | Endpoints: `POST /solve` recibe el contrato de entrada, valida, encola la corrida en un proceso de trabajo del servicio y devuelve un identificador; `GET /runs/{id}` devuelve estado, progreso, mejor solución intermedia disponible y, al terminar, la salida completa según el contrato; `DELETE /runs/{id}` cancela | Solver (servicio) | `services/solver/app/api/`. Asíncrono porque una corrida puede tardar minutos y la conexión HTTP no debe quedar abierta. Las corridas viven en memoria del servicio con un límite; el registro duradero es `solver_runs` en PostgreSQL. | pytest: ciclo completo con una instancia pequeña; cancelación; identificador desconocido | A |
| 7.4 ✅ [#633](https://github.com/Erne1357/nexus/pull/633) | Modelo CP-SAT, variables y dominio: una variable booleana por combinación factible de grupo, día, bloque de inicio y salón (solo se crean combinaciones que no violen ventana, tipo de salón, capacidad ni bloqueos, para reducir el tamaño); sesiones fijas de la entrada como asignaciones forzadas; variables auxiliares de ocupación por docente, salón y semestre reticular por bloque | Solver (servicio) | `services/solver/app/model/variables.py`. Pre-filtrar el dominio es lo que hace tratable una instancia de 400 grupos. | pytest: el número de variables de una instancia pequeña es el esperado; sesiones fijas quedan forzadas | B |
| 7.5 ✅ [#634](https://github.com/Erne1357/nexus/pull/634) | Restricciones duras: cada regla HC-01 a HC-13 como restricción del modelo (exclusividad de docente y salón por bloque, no empalme del alumno tipo por semestre reticular y plan, ventana y alineación a bloques, capacidad, laboratorio, suma de cupos, horas del docente, disponibilidad, préstamos aceptados, bloqueos, horas semanales del grupo, grupos válidos) con una función por regla para poder activarlas o desactivarlas según la entrada | Solver (servicio) | `services/solver/app/model/constraints.py`. Mapeo documentado en `docs/arquitectura/solver.md`. HC-14 a HC-18 son de flujo, no del motor. | pytest por regla: instancia que la viola es infactible; sin la regla, factible | B |
| 7.6 ✅ [#647](https://github.com/Erne1357/nexus/pull/647) | Objetivo: cada regla SC-01 a SC-08 como término lineal con su peso de la entrada (huecos del alumno tipo, reparto en días, salón por defecto, bloques preferidos, compacidad del docente, cambios de edificio, semestres consecutivos, turnos de honorarios); desglose del puntaje por regla en la salida | Solver (servicio) | `services/solver/app/model/objective.py`. El desglose permite que la pantalla de la fase 6 muestre por qué una solución es mejor. | pytest: soluciones de prueba con puntaje calculado a mano | B |
| 7.7 ✅ [#639](https://github.com/Erne1357/nexus/pull/639) [#649](https://github.com/Erne1357/nexus/pull/649) | Resolución: límite de tiempo por corrida, número de hilos, registro de soluciones intermedias con su puntaje para reportar progreso, devolución de la mejor solución al vencer el tiempo con la marca de si es óptima o no; en instancias infactibles, relajación de la asignación: un grupo puede quedar sin programar y se reporta con las reglas que lo impiden, así que dice qué restricciones no se pudieron cumplir y para qué grupos. Las reglas duras nunca se relajan (un grupo con duras no se escribe, SV-06; acuerdo entre rieles, supuesto por confirmar) y `relaxed_rules` va vacía en v1 | Solver (servicio) | `services/solver/app/model/solve.py`. Sin relajación, una instancia infactible solo devolvería "sin solución"; con ella el coordinador sabe qué ajustar. | pytest: instancia infactible reporta los grupos y reglas en conflicto; límite de tiempo respetado | B |
| 7.8 ✅ [#672](https://github.com/Erne1357/nexus/pull/672) | Descomposición en el servicio y en PHP: primera corrida con las materias de tronco común de todas las carreras del campus; después una corrida por departamento con las sesiones ya fijadas como entrada; `PlanRun` de la fase 6 orquesta la secuencia y el usuario acepta por partes | Solver, Solver (servicio) | Reutiliza 6.9. Tronco común primero porque son los grupos con más carreras y departamentos involucrados; dejarlos al final hace infactible lo demás. | Feature en PHP: secuencia de corridas con sesiones fijadas; pytest: la segunda corrida respeta las fijadas | C |
| 7.9 ✅ [#616](https://github.com/Erne1357/nexus/pull/616) | `CpSatEngine` en PHP: implementación de `SolverEngine` que envía la entrada al servicio, consulta el progreso, mapea la salida al contrato, maneja tiempo de espera, servicio caído y cancelación; registrado en `solver.engines` | Solver | `Solver/Engines/CpSat/`. El resto de Nexus no sabe que hay un servicio Python. | Feature con el servicio simulado (respuestas grabadas): éxito, fallo, cancelación, tiempo de espera | C |
| 7.10 ✅ [#670](https://github.com/Erne1357/nexus/pull/670) | Pruebas pytest con los fixtures compartidos de la fase 6: instancias pequeñas verificables a mano (solución única conocida), mediana con laboratorios y tronco común, grande del tamaño de Campus II (400 grupos); cada una debe terminar sin violaciones duras dentro del límite de tiempo | Solver (servicio) | `services/solver/tests/`. Los mismos archivos JSON que usa PHP, leídos desde `tests/Fixtures/solver/`. | pytest parametrizado por fixture; el grande corre solo fuera de CI o en un job nocturno | B |
| 7.11 ✅ [#675](https://github.com/Erne1357/nexus/pull/675) | Benchmark contra la heurística: correr ambos motores sobre los fixtures y sobre la instancia real de Campus II `20273`; comparar violaciones, puntaje blando desglosado y tiempo; registrar resultados en `docs/arquitectura/solver.md` y decidir el motor por defecto por campus en `settings` | Solver | Reutiliza 6.12. La decisión de cuál motor es el predeterminado se toma con números, no por preferencia. | Reporte generado; decisión registrada en `docs/decisiones/` | C |
| 7.12 ✅ [#561](https://github.com/Erne1357/nexus/pull/561) | Operación: logs estructurados por corrida (identificador, tamaño, tiempo, resultado), métrica de corridas en curso, límite de corridas simultáneas, documentación de despliegue en `docs/operacion.md`, pruebas de CI para el servicio (lint y pytest corto) en el workflow existente | Solver (servicio) | Un segundo lenguaje necesita su propia disciplina de pruebas y despliegue. | Workflow de CI corre pytest del servicio en PR | A |

## Pruebas de la fase

- **pytest:** cada restricción y cada término del objetivo por separado; ciclo de API; fixtures compartidos; en instancias infactibles, los grupos sin lugar en `unscheduled` con sus reglas (`relaxed_rules` vacía en v1).
- **Feature (PHP):** `CpSatEngine` con servicio simulado; descomposición en secuencia; aplicar una solución de CP-SAT pasa el validador de la fase 3.
- **Integración:** corrida real desde la pantalla de la fase 6 contra el servicio en Docker con el seeder.
- **Rendimiento:** instancia de Campus II resuelta sin violaciones duras en menos de 10 minutos por departamento con el límite configurado; comparación documentada.

## Criterios de aceptación

1. El servicio corre en Docker sin puerto público, exige token y responde salud; `queue` lo alcanza y `app` no lo necesita.
2. Sobre todos los fixtures con solución, CP-SAT devuelve cero violaciones duras; sobre los infactibles, reporta grupos y reglas en conflicto.
3. En la instancia real de Campus II, CP-SAT obtiene mejor puntaje blando que la heurística con cero violaciones duras y dentro del tiempo acotado; el resultado y la decisión del motor predeterminado quedan registrados.
4. Una solución aceptada desde la pantalla de corridas se aplica con bitácora y pasa el validador de la fase 3.
5. CI corre lint y pytest del servicio en cada PR.

## Reparto

| Persona | Módulos | Orden sugerido |
|---|---|---|
| A | Servicio (esqueleto, API, compose, operación) | 7.1 → 7.2 → 7.3 → 7.12; entrega la API con un modelo trivial la primera semana |
| B | Servicio (modelo CP-SAT) | 7.4 → 7.5 → 7.6 → 7.7 → 7.10 contra los fixtures, sin depender de la API |
| C | Solver en PHP (cliente, descomposición, benchmark) | 7.9 con servicio simulado → 7.8 → 7.11 cuando A y B entreguen |

## Riesgos y notas

- El tamaño del modelo crece con grupos × días × bloques × salones; si una instancia no cabe en memoria, reducir el dominio (salones candidatos por grupo limitados a los sugeridos por `RoomSuggestionQuery`) antes de pensar en más hardware.
- Es el único componente fuera de PHP: nadie del equipo debe quedar como única persona que lo entiende; el modelado se revisa en pareja y queda documentado regla por regla.
- Si por tiempo la fase se recorta, el orden de valor es: API y cliente (para que exista el camino), restricciones duras, objetivo, descomposición, benchmark.
- El servicio no guarda estado entre reinicios; una corrida en curso al reiniciar queda `failed` en `solver_runs` y se relanza.
