#!/usr/bin/env python3
"""Convierte las tablas de tareas de docs/plan/fase-*.md en issues de GitHub.

Crea las etiquetas y los milestones que falten, un issue por tarea en las fases hasta
--tasks-through (4 por defecto: las de R1) y una épica por cada fase posterior. Salta los
títulos que ya existen, así que se puede volver a correr. Solo lee las filas cuyo id es
`N.M` sin marca: una tarea ya marcada (`1.1 ✅ [#94]…`) tiene su issue desde antes.
Detalle en CONTRIBUTING.md, "Issues".

Uso, desde la raíz del repo, con `gh` autenticado:
  python3 -I .github/scripts/plan-issues.py --dry docs/plan/fase-*.md     # imprime lo que crearía
  python3 -I .github/scripts/plan-issues.py --apply docs/plan/fase-*.md   # lo crea
  python3 -I .github/scripts/plan-issues.py --dry --tasks-through 5 docs/plan/fase-5-demanda.md
"""
import argparse
import json
import pathlib
import re
import subprocess

REPO = "Erne1357/nexus"
BRANCH_FOR_LINKS = "develop"

PHASE_RE = re.compile(r"^# Fase (\d+) — (.+)$")
ROW_RE = re.compile(r"^\|\s*(\d+\.\d+)\s*\|")

MODULE_LABELS = {
    "Core": "module:core",
    "Academic": "module:academic",
    "Facilities": "module:facilities",
    "Faculty": "module:faculty",
    "Students": "module:students",
    "Imports": "module:imports",
    "CatalogValidation": "module:catalog-validation",
    "AcademicLoad": "module:academic-load",
    "Scheduling": "module:scheduling",
    "Staffing": "module:staffing",
    "Demand": "module:demand",
    "Solver": "module:solver",
    "Enrollment": "module:enrollment",
    "—": "module:infra",
}

LABELS = [
    ("type:task", "0E8A16", "Tarea planeada en una fase"),
    ("type:epic", "5319E7", "Agrupa las tareas de una fase"),
    ("type:feature", "1D76DB", "Funcionalidad no planeada originalmente"),
    ("type:docs", "0075CA", "Documentación del plan o del dominio"),
    ("type:chore", "BFD4F2", "Infraestructura, CI, dependencias"),
    ("piloto", "B60205", "Incidencia del piloto: se atiende antes que todo"),
    ("release:r1", "FBCA04", "Entrega R1: carga Campus II Ene-Jun 2027"),
    ("release:r2", "F9D0C4", "Entrega R2: motor y re-análisis"),
    ("release:r3", "C2E0C6", "Entrega R3: inscripción Campus II"),
    ("release:r4", "E6E6E6", "Entrega R4: otras modalidades"),
    ("priority:p0", "D93F0B", "Bloquea la entrega en curso"),
    ("priority:p1", "FBCA04", "Importante, no bloquea"),
    ("priority:p2", "C5DEF5", "Cuando haya tiempo"),
    ("module:core", "006B75", "Auth, roles, campus, periodos, bitácora"),
    ("module:academic", "006B75", "Departamentos, carreras, planes, materias"),
    ("module:facilities", "006B75", "Edificios y salones"),
    ("module:faculty", "006B75", "Docentes, plazas, perfil, evaluaciones"),
    ("module:students", "006B75", "Alumnos, kardex, avance reticular"),
    ("module:imports", "006B75", "Importación de archivos del SII"),
    ("module:catalog-validation", "006B75", "Validación de catálogos por jefes"),
    ("module:academic-load", "006B75", "Flujo de la carga académica"),
    ("module:scheduling", "006B75", "Grupos, sesiones, cupos, validador"),
    ("module:staffing", "006B75", "Ranking y asignación docente"),
    ("module:demand", "006B75", "Proyección de demanda"),
    ("module:solver", "006B75", "Motor de horarios"),
    ("module:enrollment", "006B75", "Inscripción"),
    ("module:infra", "006B75", "Docker, CI, despliegue, tooling"),
]

MILESTONES = {
    0: ("Fase 0 — Fundación", "2026-11-02"),
    1: ("Fase 1 — Catálogos e importación", "2026-11-14"),
    2: ("Fase 2 — Validación y periodo", "2026-11-19"),
    3: ("Fase 3 — Captura de carga", "2026-12-04"),
    4: ("Fase 4 — Cierre, publicación y piloto", "2026-12-18"),
    5: ("Fase 5 — Proyección de demanda", "2027-02-26"),
    6: ("Fase 6 — Heurística y contrato del motor", "2027-03-19"),
    7: ("Fase 7 — Solver CP-SAT", "2027-04-16"),
    8: ("Fase 8 — Re-análisis, impacto y junta digital", "2027-04-30"),
    9: ("Fase 9 — Inscripción Campus II", "2027-07-16"),
    10: ("Fase 10 — Otras modalidades", None),
}

RELEASE_BY_PHASE = {0: "r1", 1: "r1", 2: "r1", 3: "r1", 4: "r1", 5: "r2", 6: "r2", 7: "r2", 8: "r2", 9: "r3", 10: "r4"}
PRIORITY_BY_RELEASE = {"r1": "priority:p0", "r2": "priority:p1", "r3": "priority:p2", "r4": "priority:p2"}


def parse(path: pathlib.Path) -> dict:
    lines = path.read_text(encoding="utf-8").splitlines()
    phase = title = None
    for line in lines:
        m = PHASE_RE.match(line)
        if m:
            phase, title = int(m.group(1)), m.group(2).strip()
            break
    status = "⬜"
    for line in lines:
        if line.startswith("**Entrega:**"):
            m = re.search(r"\*\*Estado:\*\*\s*(\S+)", line)
            if m:
                status = m.group(1)
            break
    objetivo = ""
    for i, line in enumerate(lines):
        if line.strip() == "## Objetivo":
            for nxt in lines[i + 1:]:
                if nxt.strip():
                    objetivo = nxt.strip()
                    break
            break
    tasks = []
    for line in lines:
        if ROW_RE.match(line):
            cells = [c.strip() for c in line.strip().strip("|").split("|")]
            if len(cells) < 6:
                continue
            tid, tarea, modulo, donde, pruebas, persona = cells[:6]
            tasks.append({"id": tid, "tarea": tarea, "modulo": modulo, "donde": donde, "pruebas": pruebas, "persona": persona})
    return {"phase": phase, "title": title, "status": status, "objetivo": objetivo, "file": path.as_posix(), "tasks": tasks}


def module_labels(cell: str) -> list[str]:
    out = []
    for part in re.split(r",\s*", cell):
        part = part.strip()
        if part in MODULE_LABELS:
            out.append(MODULE_LABELS[part])
    return out


def short_title(text: str, limit: int = 110) -> str:
    text = re.sub(r"`", "", text)
    if len(text) <= limit:
        return text
    cut = text[:limit].rsplit(" ", 1)[0]
    return cut + "…"


def build_task_issue(doc: dict, t: dict) -> dict:
    phase = doc["phase"]
    release = RELEASE_BY_PHASE[phase]
    labels = ["type:task", f"release:{release}", PRIORITY_BY_RELEASE[release]] + module_labels(t["modulo"])
    url = f"https://github.com/{REPO}/blob/{BRANCH_FOR_LINKS}/{doc['file']}"
    body = f"""**Fase {phase} — {doc['title']}** · Tarea `{t['id']}` · Entrega {release.upper()} · Persona sugerida: **{t['persona']}**
Plan: [{doc['file']}]({url}) (sección *Tareas*)

## Tarea
{t['tarea']}

## Dónde y por qué
{t['donde']}

## Pruebas
{t['pruebas']}

## Criterios de aceptación
- [ ] Pruebas descritas arriba escritas primero (TDD) y verdes en CI
- [ ] Pruebas negativas de alcance por rol (si hay pantalla)
- [ ] Documentación de la fase actualizada con el número de este PR
- [ ] Revisado por otra persona y mezclado en `develop`
"""
    return {
        "title": f"[F{phase}·{t['id']}] {short_title(t['tarea'])}",
        "body": body,
        "labels": labels,
        "milestone": MILESTONES[phase][0],
    }


def build_epic_issue(doc: dict) -> dict:
    phase = doc["phase"]
    release = RELEASE_BY_PHASE[phase]
    url = f"https://github.com/{REPO}/blob/{BRANCH_FOR_LINKS}/{doc['file']}"
    vaga = doc["status"].startswith("🟦")
    nota = (
        "Esta fase está marcada como **vaga**: antes de crear tareas se hace una sesión de planeación detallada (brainstorming + writing-plans) que actualice el archivo de la fase y `PLAN.md`."
        if vaga
        else "Las tareas de esta fase se crean como issues individuales al iniciarla, a partir de la tabla *Tareas* del archivo de la fase (se revisan en la retrospectiva de R1)."
    )
    body = f"""**Fase {phase} — {doc['title']}** · Entrega {release.upper()} · Estado {doc['status']}
Plan: [{doc['file']}]({url})

## Objetivo
{doc['objetivo']}

## Cómo se inicia
{nota}

## Tareas
- [ ] (se completan al iniciar la fase)
"""
    return {
        "title": f"Épica: Fase {phase} — {doc['title']}",
        "body": body,
        "labels": ["type:epic", f"release:{release}", PRIORITY_BY_RELEASE[release]],
        "milestone": MILESTONES[phase][0],
    }


def gh(*args: str, input_text: str | None = None) -> str:
    res = subprocess.run(["gh", *args], capture_output=True, text=True, input=input_text)
    if res.returncode != 0:
        raise RuntimeError(f"gh {' '.join(args)} failed: {res.stderr.strip()}")
    return res.stdout.strip()


def ensure_labels() -> None:
    for name, color, desc in LABELS:
        gh("label", "create", name, "--color", color, "--description", desc, "--force", "--repo", REPO)
        print(f"label ok: {name}")


def ensure_milestones() -> None:
    existing = json.loads(gh("api", f"repos/{REPO}/milestones?state=all&per_page=100"))
    by_title = {m["title"]: m["number"] for m in existing}
    for phase, (title, due) in MILESTONES.items():
        if title in by_title:
            print(f"milestone exists: {title}")
            continue
        args = ["api", f"repos/{REPO}/milestones", "-f", f"title={title}"]
        if due:
            args += ["-f", f"due_on={due}T23:59:59Z"]
        gh(*args)
        print(f"milestone created: {title}")


def existing_issue_titles() -> set[str]:
    out = gh("issue", "list", "--repo", REPO, "--state", "all", "--limit", "10000", "--json", "title")
    return {i["title"] for i in json.loads(out)}


def create_issue(issue: dict) -> str:
    args = ["issue", "create", "--repo", REPO, "--title", issue["title"], "--body", issue["body"], "--milestone", issue["milestone"]]
    for label in issue["labels"]:
        args += ["--label", label]
    return gh(*args)


def main() -> None:
    parser = argparse.ArgumentParser(description="Issues de GitHub desde docs/plan/fase-*.md")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--dry", action="store_true", help="imprime lo que crearía, sin escribir nada")
    mode.add_argument("--apply", action="store_true", help="crea etiquetas, milestones e issues")
    parser.add_argument("--tasks-through", type=int, default=4, help="última fase con un issue por tarea; las siguientes llevan solo su épica")
    parser.add_argument("files", nargs="+", type=pathlib.Path)
    args = parser.parse_args()

    docs = [parse(f) for f in args.files]
    docs = [d for d in docs if d["phase"] is not None]
    unknown = sorted(d["phase"] for d in docs if d["phase"] not in MILESTONES)
    if unknown:
        raise SystemExit(f"Fases sin milestone ni entrega en este script: {unknown}. Agrégalas a MILESTONES y RELEASE_BY_PHASE.")
    docs.sort(key=lambda d: d["phase"])
    issues: list[dict] = []
    for d in docs:
        if d["phase"] <= args.tasks_through:
            issues += [build_task_issue(d, t) for t in d["tasks"]]
        else:
            issues.append(build_epic_issue(d))
    done = existing_issue_titles()
    if args.dry:
        for d in docs:
            print(f"Fase {d['phase']} ({d['status']}): {len(d['tasks'])} tareas · {d['title']}")
        pending = [i for i in issues if i["title"] not in done]
        print(f"\nIssues: {len(issues)}, ya existen {len(issues) - len(pending)}, por crear {len(pending)}")
        for i in pending:
            print(f"  {i['milestone']:<45} {i['title']}  {i['labels']}")
        return
    ensure_labels()
    ensure_milestones()
    for i in issues:
        if i["title"] in done:
            print(f"skip (exists): {i['title']}")
            continue
        url = create_issue(i)
        print(f"created: {url}  {i['title']}")


if __name__ == "__main__":
    main()
