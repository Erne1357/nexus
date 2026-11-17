import pytest

from app.config import ConfigError, Settings


def test_el_token_es_obligatorio():
    with pytest.raises(ConfigError, match="SOLVER_TOKEN"):
        Settings.from_env({})
    with pytest.raises(ConfigError, match="SOLVER_TOKEN"):
        Settings.from_env({"SOLVER_TOKEN": "  "})


def test_valores_por_defecto():
    settings = Settings.from_env({"SOLVER_TOKEN": "t"})

    assert settings.token == "t"
    assert settings.default_time_limit == 60
    assert settings.threads == 0
    assert settings.max_concurrent_runs == 2
    assert settings.engine == "trivial"


def test_lee_las_variables_de_entorno():
    settings = Settings.from_env(
        {
            "SOLVER_TOKEN": "t",
            "SOLVER_DEFAULT_TIME_LIMIT": "120",
            "SOLVER_THREADS": "4",
            "SOLVER_MAX_CONCURRENT_RUNS": "3",
        }
    )

    assert (settings.default_time_limit, settings.threads, settings.max_concurrent_runs) == (120, 4, 3)


@pytest.mark.parametrize(
    ("name", "value"),
    [("SOLVER_THREADS", "muchos"), ("SOLVER_MAX_CONCURRENT_RUNS", "0"), ("SOLVER_DEFAULT_TIME_LIMIT", "-5")],
)
def test_rechaza_valores_invalidos(name: str, value: str):
    with pytest.raises(ConfigError, match=name):
        Settings.from_env({"SOLVER_TOKEN": "t", name: value})


def test_la_version_del_servicio_coincide_con_pyproject():
    import tomllib
    from pathlib import Path

    from app import __version__

    pyproject = tomllib.loads((Path(__file__).parents[1] / "pyproject.toml").read_text())

    assert pyproject["project"]["version"] == __version__
