# Operación del servicio del motor de horarios

Servicio `solver` (Python 3.12, FastAPI y OR-Tools; ADR-0027) que resuelve el contrato del motor (`docs/arquitectura/solver.md`). Lo llama solo el contenedor `queue`, nunca el navegador, y no tiene puertos públicos.

## Variables

| Variable | Dónde | Para qué |
|---|---|---|
| `SOLVER_TOKEN` | `.env` del servicio y de `app`/`queue` | Token compartido: toda petición salvo `/health` lleva `Authorization: Bearer <token>`. Sin él, el servicio no arranca. En producción, uno largo y aleatorio. |
| `SOLVER_URL` | `.env` de `app`/`queue` | `http://solver:8000` dentro de la red `backend`. En producción es obligatorio, igual que `SOLVER_TOKEN`: `compose.prod.yaml` no arranca sin ellos, porque sin la dirección CP-SAT desaparecería sin aviso de la lista de motores (`CpSatClient::configured()`). |
| `SOLVER_DEFAULT_TIME_LIMIT` | servicio | Segundos por corrida si la entrada no los trae. |
| `SOLVER_THREADS` | servicio | Hilos de CP-SAT por corrida (0, los núcleos del contenedor; nunca más que esos). |
| `SOLVER_ENGINE` | servicio | Motor que atiende las corridas: `cpsat` (el modelo CP-SAT, por omisión desde la 7.10; `docs/arquitectura/solver.md`, «Resolución») o `trivial` (el de prueba, solo para pruebas y CI). |
| `SOLVER_MAX_CONCURRENT_RUNS` | servicio | Corridas en cola o en curso antes de responder 429 (2 por omisión). |
| `SOLVER_MAX_STORED_RUNS` | servicio | Corridas terminadas que se conservan en memoria para consultarlas (50). |

## Salud y métricas

- `GET /health` (sin token): estado, versión del servicio, versiones del contrato que entiende y corridas en curso (`runs_in_progress`). Lo usa el healthcheck de Docker.
- Logs en JSON a stdout, una línea por evento de corrida: `run_id`, motor, grupos, segundos y estado. Se ven con `docker compose logs -f solver`.

## Límites y recursos

- En `compose.yaml`: 2 CPU y 2 GB por omisión. Con CP-SAT conviene darle CPU dedicada durante las corridas (decisión de infraestructura de la fase 7, pendiente del servidor del ITCJ).
- Las corridas viven en memoria. Si el servicio se reinicia, las que estaban corriendo se pierden: PHP las marca fallidas por tiempo de espera (6.8) y se vuelven a lanzar.

## Pruebas y CI

```bash
docker build --target dev -t nexus-solver-dev services/solver
docker run --rm nexus-solver-dev sh -c "ruff check . && ruff format --check ."
docker run --rm -e SOLVER_TOKEN=t -v "$PWD/tests/Fixtures/solver:/fixtures:ro" nexus-solver-dev pytest -q
```

El job `solver` del CI corre lo mismo en cada PR. Todavía no es un check requerido de `develop`. La carga real (`department-multicampus`, marcador `realsize`, alrededor de un minuto) no corre en el CI de PR; se corre a mano, montando el código que se quiere probar en `/srv/solver`:

```bash
docker run --rm --cpus 2 --memory 2g -e SOLVER_TOKEN=t -v "$PWD/services/solver:/srv/solver" \
  -v "$PWD/tests/Fixtures/solver:/fixtures:ro" nexus-solver-dev pytest -m realsize -q
```
