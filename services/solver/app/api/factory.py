"""La fábrica de la aplicación FastAPI del servicio (tarea 7.3).

Todos los endpoints exigen el token salvo `GET /health`. La entrada se valida a mano, después de la autenticación, para
que una petición sin token siempre reciba 401 y una entrada inválida reciba 422 con la ruta de cada campo malo.
"""

import json
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import APIRouter, Depends, FastAPI, HTTPException, Request, status
from fastapi.responses import JSONResponse

from app import CONTRACT_VERSIONS, __version__
from app.config import Settings
from app.contract import ContractError, parse_input
from app.engine import Engine, make_engine
from app.logging import configure_logging
from app.runs import RunManager, RunView, ServiceBusy

from .auth import require_token
from .schemas import HealthResponse, RunRef, RunResponse


def create_app(settings: Settings | None = None, engine: Engine | None = None) -> FastAPI:
    settings = settings or Settings.from_env()
    configure_logging()
    # Sin motor explícito, uno nuevo por corrida: los motores no guardan estado entre corridas.
    runs = RunManager(settings, (lambda: engine) if engine else (lambda: make_engine(settings.engine)))

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        yield
        runs.shutdown()

    app = FastAPI(
        title="Nexus solver",
        version=__version__,
        description="Motor de horarios de Nexus. Contrato versión 1: docs/arquitectura/solver.md.",
        lifespan=lifespan,
    )
    app.state.settings = settings
    app.state.runs = runs

    @app.get("/health", response_model=HealthResponse)
    def health() -> HealthResponse:
        return HealthResponse(
            status="ok",
            version=__version__,
            contract_versions=CONTRACT_VERSIONS,
            runs_in_progress=runs.in_progress(),
        )

    protected = APIRouter(dependencies=[Depends(require_token)])

    @protected.post("/solve", status_code=status.HTTP_202_ACCEPTED, response_model=RunRef)
    async def solve(request: Request) -> RunRef | JSONResponse:
        try:
            body = json.loads(await request.body())
        except ValueError:
            return _unprocessable([{"path": "", "message": "El cuerpo debe ser un JSON válido."}])
        try:
            data = parse_input(body)
        except ContractError as error:
            return _unprocessable(error.as_detail())
        try:
            run = runs.submit(data)
        except ServiceBusy as busy:
            raise HTTPException(
                status_code=status.HTTP_429_TOO_MANY_REQUESTS,
                detail=str(busy),
                headers={"Retry-After": "5"},
            ) from None

        # Siempre «queued»: el hilo de trabajo puede tomarla antes de que se arme la respuesta.
        return RunRef(run_id=run.id, status="queued")

    @protected.get("/runs/{run_id}", response_model=RunResponse, response_model_exclude_none=True)
    def show_run(run_id: str) -> RunResponse:
        return _response(_found(runs.get(run_id)))

    @protected.delete("/runs/{run_id}", response_model=RunRef)
    def cancel_run(run_id: str) -> RunRef:
        view = _found(runs.cancel(run_id))

        return RunRef(run_id=view.id, status=view.state.value)

    app.include_router(protected)

    return app


def _found(view: RunView | None) -> RunView:
    if view is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Corrida desconocida.")

    return view


def _response(view: RunView) -> RunResponse:
    return RunResponse(
        run_id=view.id,
        status=view.state.value,
        progress=view.progress,
        best_score=view.best_score,
        output=view.output,
        error=view.error,
    )


def _unprocessable(detail: list[dict[str, str]]) -> JSONResponse:
    return JSONResponse(status_code=422, content={"detail": detail})
