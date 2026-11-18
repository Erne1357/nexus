"""Las restricciones duras HC-01 a HC-13 del modelo CP-SAT (tarea 7.5; docs/arquitectura/solver.md, «Modelo CP-SAT:
variables y restricciones duras»).

Cada regla dice lo mismo que su clase del evaluador en PHP (`Scheduling/Rules/Constraints/*`, sobre la foto de
`Solver/Evaluation/ContractState`) y se activa con `hard_rules` de la entrada. Hay tres formas de cumplirla:

- **restricción**: se agrega al modelo sobre las variables (`add_hc01`, `add_hc02`, `add_hc03`, `add_hc06`);
- **dominio**: `variables.py` no crea lo que la rompe (HC-04, HC-05, HC-09, HC-10, HC-11, HC-12, y la parte fija de
  HC-01 a HC-03: la ocupación de fuera de la corrida y de los grupos fijos);
- **entrada**: no depende del acomodo (HC-07, HC-08, HC-13). No vuelve infactible el modelo: se reporta como problema
  de la entrada (`input_problems`), como `ApplyPlan::INPUT_RULES` en PHP y PS-08.

`add_assignment` no es una regla: dice que cada grupo movible toma exactamente una opción. Es el gancho de la
relajación (7.7), que lo cambia por «a lo más una» con una penalización por grupo sin programar.
"""

from collections import defaultdict
from collections.abc import Callable
from dataclasses import dataclass
from enum import StrEnum

from ortools.sat.python import cp_model

from app.contract.output import HardViolation

from .data import HARD_RULES, ModelData
from .rooms import NONE
from .variables import Occupancy, Variables, Window


class RuleKind(StrEnum):
    CONSTRAINT = "restricción"
    DOMAIN = "dominio"
    INPUT = "entrada"


AddRule = Callable[[cp_model.CpModel, Variables, ModelData], None]


@dataclass(frozen=True)
class RuleModel:
    """Cómo entra una regla dura al modelo; `add` solo en las que agregan restricciones."""

    kind: RuleKind
    add: AddRule | None = None


def add_assignment(model: cp_model.CpModel, variables: Variables) -> None:
    """Cada grupo movible toma exactamente una opción: se programa completo, con sus horas (HC-12). Un grupo sin
    opciones vuelve infactible el modelo duro (`Variables.unplaceable` dice cuáles)."""
    for domain in variables.groups.values():
        model.add_exactly_one([option.literal for option in domain.options])


def add_hc01(model: cp_model.CpModel, variables: Variables, data: ModelData) -> None:
    """HC-01 (`TeacherOverlap`): un docente no da dos sesiones que se empalmen el mismo día, en ningún campus.

    En cada inicio de ventana de los grupos de un docente, a lo más una de las que lo cubren; en minutos, así que vale
    para grupos de campus con rejillas distintas. El traslado entre campus (`travel_minutes`) no entra: en el evaluador
    es SC-09, un aviso que no bloquea, y va en el objetivo (7.6). Su ocupación fija ya salió del dominio.
    """
    _no_overlap(model, variables.teachers)


def add_hc02(model: cp_model.CpModel, variables: Variables, data: ModelData) -> None:
    """HC-02 (`RoomOverlap`): un salón no tiene dos sesiones que se empalmen. En un día de 2 horas las dos van en el
    mismo salón (PS-05), así que una ventana ocupa su salón completa.

    Las ventanas con `z` explícito, con cliques por salón; las del conjunto de salones, con la cuenta por bloque de la
    fase A (`add_pool_capacity`), que garantiza que la fase B las reparte sin choques.
    """
    _no_overlap(model, variables.rooms)
    add_pool_capacity(model, variables, data)


def add_pool_capacity(model: cp_model.CpModel, variables: Variables, data: ModelData) -> None:
    """La fase A de los salones (`rooms.py`): en cada bloque, para cada par de umbrales (a de teoría, b de práctica),
    las sesiones del conjunto que solo caben en `U(a, b)` más los salones de `U(a, b)` que tomaron las ventanas
    explícitas no pasan de `|U(a, b)|`. Es la condición de Hall de cada conjunto de sesiones del bloque."""
    for (campus, day, block), windows in variables.pooled.items():
        pool = variables.pools[campus]
        minute = pool.grid.minute_of(block)
        thresholds = {window: variables.groups[window.group_id].threshold for window in windows}
        theory = [*sorted(set(thresholds.values())), NONE]
        lab = [*sorted({thresholds[window] for window in windows if window.lab is not None}), NONE]
        for low_theory in theory:
            for low_lab in lab:
                if low_theory == NONE and low_lab == NONE:
                    continue
                rooms = pool.union(day, block, low_theory, low_lab)
                counted = [_pool_terms(window, thresholds[window], low_theory, low_lab) for window in windows]
                explicit = [literal for room in rooms for literal in variables.rooms.at(room, day, minute)]
                # Cada ventana del conjunto suma a lo más 1 y cada ventana explícita, a lo más un salón: si ni así
                # pasan de los salones, la restricción sobra.
                if sum(1 for terms in counted if terms) + len(explicit) > len(rooms):
                    terms = [term for window_terms in counted for term in window_terms]
                    model.add(cp_model.LinearExpr.sum(terms + explicit) <= len(rooms))


def _pool_terms(window: Window, threshold: int, low_theory: int, low_lab: int) -> list:
    """Lo que la ventana pone en la cuenta de `U(low_theory, low_lab)`: toda si cabe por los dos lados, la teoría
    (`v - l`) o la práctica (`l`) si solo por uno."""
    in_theory = threshold >= low_theory
    in_lab = window.lab is not None and threshold >= low_lab
    if in_theory and (in_lab or window.lab is None):
        return list(window.literals)
    if in_theory:
        return [*window.literals, -window.lab]
    if in_lab:
        return [window.lab]

    return []


def add_hc03(model: cp_model.CpModel, variables: Variables, data: ModelData) -> None:
    """HC-03 (`TypicalStudentSchedule`): los grupos que comparten una clave de cohorte no se empalman.

    Es la regla del contrato y de la heurística. El evaluador busca una combinación del alumno tipo entre los grupos de
    cada materia de un plan, semestre y campus, pero la entrada solo pone la clave `plan:` en el grupo único de su
    materia, así que ahí dicen lo mismo; con `package:` el evaluador también revisa los pares (de la primera clave de
    paquete de cada grupo). Solo difieren en una entrada mal armada (dos grupos de la misma materia con la misma clave
    `plan:`), donde el modelo es más estricto.
    """
    _no_overlap(model, variables.cohorts)


def add_hc06(model: cp_model.CpModel, variables: Variables, data: ModelData) -> None:
    """HC-06 (`LabRoom`): las unidades de práctica de una materia con laboratorio van en laboratorio, taller o sala de
    cómputo.

    Por grupo: las unidades de sus ventanas en esos espacios alcanzan las de práctica de su opción. Cuáles de esas
    unidades son de práctica se decide al escribir la salida (`solution.py`); las de teoría pueden quedar en un
    laboratorio, que HC-06 permite. Que haya algún espacio de práctica libre en las ventanas ya lo filtró el dominio.
    """
    for domain in variables.groups.values():
        if not domain.lab_practice:
            continue
        lab_units = [
            window.units * literal
            for window in domain.windows.values()
            for room_id, literal in window.rooms.items()
            if data.is_practice_space(room_id)
        ] + [window.units * window.lab for window in domain.windows.values() if window.lab is not None]
        practice = [option.practice * option.literal for option in domain.options]
        model.add(cp_model.LinearExpr.sum(lab_units) >= cp_model.LinearExpr.sum(practice))


RULES: dict[str, RuleModel] = {
    "HC-01": RuleModel(RuleKind.CONSTRAINT, add_hc01),
    "HC-02": RuleModel(RuleKind.CONSTRAINT, add_hc02),
    "HC-03": RuleModel(RuleKind.CONSTRAINT, add_hc03),
    "HC-04": RuleModel(RuleKind.DOMAIN),
    "HC-05": RuleModel(RuleKind.DOMAIN),
    "HC-06": RuleModel(RuleKind.CONSTRAINT, add_hc06),
    "HC-07": RuleModel(RuleKind.INPUT),
    "HC-08": RuleModel(RuleKind.INPUT),
    "HC-09": RuleModel(RuleKind.DOMAIN),
    "HC-10": RuleModel(RuleKind.DOMAIN),
    "HC-11": RuleModel(RuleKind.DOMAIN),
    "HC-12": RuleModel(RuleKind.DOMAIN),
    "HC-13": RuleModel(RuleKind.INPUT),
}


def add_hard_constraints(model: cp_model.CpModel, variables: Variables, data: ModelData) -> None:
    """La asignación y las restricciones de las reglas activas de la entrada."""
    add_assignment(model, variables)
    for rule in HARD_RULES:
        add = RULES[rule].add
        if add is not None and data.active(rule):
            add(model, variables, data)


def input_problems(data: ModelData) -> list[HardViolation]:
    """Las reglas que no dependen del acomodo y la entrada ya rompe.

    - HC-08 (`TeacherHours`): con las horas de la entrada, a un docente le queda `remaining_hours` más las horas de sus
      grupos fijos y lleva las de todos sus grupos, así que se pasa si sus grupos movibles suman más que
      `remaining_hours`. Se reporta en cada uno de sus grupos movibles, como el evaluador; el grupo se programa igual
      (PS-08).
    - HC-07 (cupos) y HC-13 (materia, plan y carrera ofrecida): el contrato no trae cupos ni catálogos, así que, como
      el evaluador, no se pueden revisar; los revisa Nexus al armar la entrada y el validador después de aplicar.
    """
    if not data.active("HC-08"):
        return []

    movable_hours: dict[str, int] = defaultdict(int)
    fixed_hours: dict[str, int] = defaultdict(int)
    groups_of: dict[str, list[str]] = defaultdict(list)
    for group in data.groups.values():
        if group.teacher_id is None:
            continue
        if group.movable:
            movable_hours[group.teacher_id] += group.weekly_hours
            groups_of[group.teacher_id].append(group.id)
        else:
            fixed_hours[group.teacher_id] += group.weekly_hours

    problems = []
    for teacher_id, teacher in data.teachers.items():
        if movable_hours[teacher_id] <= teacher.remaining_hours:
            continue
        limit = teacher.remaining_hours + fixed_hours[teacher_id]
        total = movable_hours[teacher_id] + fixed_hours[teacher_id]
        message = f"El docente suma {total} h y su límite es {limit} h: se pasa por {total - limit} h."
        problems.extend(
            HardViolation(rule="HC-08", group_ids=[group_id], teacher_id=teacher_id, message=message)
            for group_id in groups_of[teacher_id]
        )

    return problems


def _no_overlap(model: cp_model.CpModel, occupancy: Occupancy) -> None:
    for _, _, _, literals in occupancy.cliques():
        model.add_at_most_one(literals)
