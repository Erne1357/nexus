# syntax=docker/dockerfile:1.7

# Servicio del motor de horarios (fase 7). Imagen base fijada por versión exacta + digest, como el resto del stack.
# Para actualizar: cambiar tag y digest juntos (`docker pull python:<tag> && docker inspect --format '{{index .RepoDigests 0}}' python:<tag>`).
ARG PYTHON_IMAGE=python:3.12.16-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b

# ---------------------------------------------------------------------------
# base: Python + dependencias de ejecución + usuario sin privilegios
# ---------------------------------------------------------------------------
FROM ${PYTHON_IMAGE} AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

RUN groupadd --system --gid 10001 solver \
    && useradd --system --uid 10001 --gid solver --no-create-home --shell /usr/sbin/nologin solver

WORKDIR /srv/solver

# Solo pyproject.toml: la capa de dependencias se reconstruye únicamente cuando cambian las versiones.
COPY pyproject.toml ./

RUN python -c "import tomllib; p = tomllib.load(open('pyproject.toml', 'rb'))['project']; print('\n'.join(p['dependencies']))" > /tmp/requirements.txt \
    && pip install -r /tmp/requirements.txt \
    && rm /tmp/requirements.txt

# ---------------------------------------------------------------------------
# dev: más pytest, httpx y ruff. Pruebas: docker build --target dev -t nexus-solver-dev services/solver
# Los fixtures compartidos (tests/Fixtures/solver del repositorio) se montan en /fixtures; no se copian a la imagen.
# ---------------------------------------------------------------------------
FROM base AS dev

RUN python -c "import tomllib; p = tomllib.load(open('pyproject.toml', 'rb'))['project']; print('\n'.join(p['optional-dependencies']['dev']))" > /tmp/requirements-dev.txt \
    && pip install -r /tmp/requirements-dev.txt \
    && rm /tmp/requirements-dev.txt

ENV SOLVER_FIXTURES_DIR=/fixtures

COPY app ./app
COPY tests ./tests

USER solver

CMD ["pytest"]

# ---------------------------------------------------------------------------
# runtime: lo que corre en compose (etapa por defecto, la última)
# ---------------------------------------------------------------------------
FROM base AS runtime

COPY app ./app

USER solver

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=2)"]

CMD ["uvicorn", "app.api.main:app", "--host", "0.0.0.0", "--port", "8000"]
