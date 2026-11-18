# Servicio del motor de horarios (`services/solver`)

Servicio en Python 3.12 (FastAPI + OR-Tools) que implementa el contrato JSON versión 1 de `docs/arquitectura/solver.md`. Nexus (el contenedor `queue`) le manda la entrada de una corrida, consulta su avance y recoge la salida. Nunca lo llama el navegador y no publica puertos.

Hoy trae un **motor de prueba** (`app/engine/trivial.py`, el equivalente de `FixedEngine` en PHP: horas seguidas desde el inicio de la jornada, `status = partial`) para que la API, el cliente de PHP y la pantalla de corridas se construyan antes que el modelo CP-SAT (tareas 7.4 a 7.7), que lo reemplazará.

## Estructura

| Ruta | Qué hay |
|---|---|
| `app/config.py` | Variables de entorno (`Settings`) |
| `app/contract/` | Modelos pydantic de la entrada y la salida v1, espejo de `app/Modules/Solver/Contract/` |
| `app/engine/` | Interfaz de motor (`Engine`, `RunContext`) y el motor de prueba |
| `app/model/` | El modelo CP-SAT (7.4 en adelante): rejilla y patrón de sesiones, datos indexados de la entrada, variables con su dominio pre-filtrado, restricciones duras HC-01 a HC-13, salones en dos fases (`rooms.py`), la búsqueda por vecindarios para la carga de un departamento (`search.py`), las sesiones de una solución y un verificador de duras sobre la salida que no depende del modelo. Todavía no es un motor del servicio; ver `docs/arquitectura/solver.md`, «Modelo CP-SAT: variables y restricciones duras» |
| `app/runs.py` | Corridas en memoria, hilos de trabajo, límite de simultáneas, cancelación |
| `app/api/` | Aplicación FastAPI (`factory.py`), autenticación y esquemas de respuesta |
| `tests/` | pytest: API, contrato, fixtures compartidos, OR-Tools |
| `scripts/benchmark.py` | Reproduce la tabla de «Tamaño de la instancia real» (`docs/arquitectura/solver.md`) con un fixture compartido; solo en la imagen `dev` |

## Configuración

| Variable | Por defecto | Significado |
|---|---|---|
| `SOLVER_TOKEN` | (obligatoria) | Token compartido con Nexus. Sin ella el servicio no arranca. |
| `SOLVER_DEFAULT_TIME_LIMIT` | `60` | Límite de tiempo por defecto, en segundos, que se pasa a los motores |
| `SOLVER_THREADS` | `0` | Hilos de CP-SAT; `0` usa los núcleos disponibles. `options.threads` de la entrada tiene prioridad |
| `SOLVER_MAX_MODEL_VARIABLES` | `1500000` | Variables que puede tener el modelo CP-SAT de una corrida. Si las pasa, la corrida falla al armarlo con un mensaje claro, antes de agotar la memoria del servicio (las corridas comparten un proceso) |
| `SOLVER_MAX_CONCURRENT_RUNS` | `2` | Corridas en cola o en curso antes de responder `429` |
| `SOLVER_MAX_STORED_RUNS` | `50` | Corridas terminadas que se conservan en memoria |
| `SOLVER_ENGINE` | `trivial` | Motor que atiende las corridas: `trivial` (el de prueba) o `cpsat` (el modelo CP-SAT, 7.7) |

En compose, `SOLVER_TOKEN` sale de `.env` (plantilla en `.env.example`; en producción es obligatorio). `queue` lee `SOLVER_URL` (`http://solver:8000`) y `SOLVER_TOKEN` de ese mismo `.env`.

## API

Todo exige `Authorization: Bearer <SOLVER_TOKEN>` (si no, `401`) salvo `GET /health`. Documentación interactiva de FastAPI en `/docs` (también con token).

| Endpoint | Respuesta |
|---|---|
| `GET /health` | `{status, version, contract_versions: [1], runs_in_progress}` |
| `POST /solve` (cuerpo: entrada v1) | `202 {run_id, status: "queued"}`. `422 {detail: [{path, message}]}` si la entrada no cumple el contrato (la ruta es la de PHP: `groups[3].weekly_hours`). `429` si ya hay `SOLVER_MAX_CONCURRENT_RUNS` corridas en curso |
| `GET /runs/{id}` | `{run_id, status: queued\|running\|done\|failed\|cancelled, progress: 0-100, best_score?, output?, error?}`; `output` es la salida completa del contrato cuando `status = done`. `404` si el id no existe |
| `DELETE /runs/{id}` | `{run_id, status: "cancelled"}`. Una corrida ya terminada conserva su estado (`done`, `failed`). `404` si no existe |

`run_id` lo genera el servicio y es distinto del `run_id` de la entrada (el de `solver_runs`), que se devuelve tal cual en la salida.

**Sin estado duradero.** Las corridas viven solo en memoria: un reinicio las pierde y PHP marca `failed` por tiempo de espera lo que estaba en curso (`solver_runs` es el registro duradero). Una corrida cancelada sigue ocupando su lugar hasta que su hilo de trabajo ve la bandera y termina; `runs_in_progress` cuenta esos lugares.

**Logs.** JSON a stdout, una línea por evento (`run_queued`, `run_started`, `run_cancelled`, `run_finished`) con `run_id`, `contract_run_id`, `engine`, `status`, `groups`, `scope` y, al terminar, `elapsed_seconds`.

## Cómo correrlo y probarlo (todo en Docker; no hay Python en la máquina)

Con el stack de desarrollo (código montado, recarga automática, token por defecto `dev-solver-token`):

```bash
docker compose up -d solver
docker compose logs -f solver
docker compose run --rm --no-deps solver pytest          # pruebas con los fixtures compartidos montados
docker compose run --rm --no-deps solver ruff check .    # lint
docker compose run --rm --no-deps solver ruff format --check .
```

Solo la imagen, sin compose:

```bash
docker build --target dev -t nexus-solver-dev services/solver
docker run --rm -e SOLVER_TOKEN=t -v "$PWD/tests/Fixtures/solver:/fixtures:ro" nexus-solver-dev pytest
docker run --rm -e SOLVER_TOKEN=t nexus-solver-dev ruff check .
```

La imagen `runtime` (etapa por defecto) no trae las herramientas de prueba.

### Fixtures compartidos

Las pruebas leen los mismos JSON que PHP (`tests/Fixtures/solver/v1/*/input.json` y `output.json`); no se copian al servicio. En la imagen `dev` se montan en `/fixtures` (`SOLVER_FIXTURES_DIR`); fuera de Docker se leen de `<repositorio>/tests/Fixtures/solver`. Si no se encuentran, las pruebas fallan con un mensaje claro (nunca se omiten). Cada fixture nuevo entra solo a la prueba parametrizada: se valida con los modelos pydantic y vuelve a su JSON exacto, igual que en PHP.

## Dependencias

Versiones exactas en `pyproject.toml` (fuente única; el Dockerfile las instala sin instalar el proyecto). Para actualizarlas: verificar la versión (`docker run --rm python:3.12-slim pip index versions <paquete>`), cambiarla ahí y correr las pruebas. Justificación en `docs/decisiones/0027-servicio-del-motor-en-python.md`. La imagen base se fija por versión y digest (ver el `Dockerfile`).
