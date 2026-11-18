# ADR-0029 — Staffing depende de Solver

**Estado:** Aceptada · **Fecha:** 2026-11-18 · Tarea 8.7 de la fase 8 (#618) · Relacionada con [ADR-0020](0020-staffing-a-la-derecha-de-scheduling.md) y `docs/arquitectura/solver.md`

## Contexto
La tarea 8.7 pide que el jefe vea, para una solicitud de cambio con choques, dos o tres acomodos del motor sin choques y elija uno. Las solicitudes de cambio viven en Staffing (ADR-0020) y el motor en Solver (fase 6), y en el mapa de `docs/dominio/modelo-de-datos.md` los dos eran hermanos a la derecha de Scheduling: ninguno podía importar al otro. El plan de la fase 8 («Punto de partida», 8.6 y 8.7, pregunta 6) dejó dos salidas:

- agregar la arista `Solver --> Staffing`, para que Staffing llame a Solver, o
- dejar la Action en Solver y que Staffing la pida por un contrato declarado en Scheduling (un evento o una interfaz).

Lo que Staffing necesita de Solver es una respuesta en la misma petición (la lista de acomodos), no un aviso: un evento obligaría a esperar una respuesta de vuelta, y una interfaz en Scheduling sería un contrato del motor dentro del módulo que el motor lee, solo para no dibujar una flecha. Solver, en cambio, no necesita nada de Staffing: arma su entrada desde la foto de Scheduling (grupos, sesiones, docentes, salones) y desde Faculty.

## Decisión
El mapa agrega `Solver --> Staffing`: Staffing puede depender de Solver y Solver nunca depende de Staffing.

- Staffing llama a `Solver\Actions\ProposePlacements` y lee `Solver\Queries\BuildSolverInput`, `Solver\Rules\InputFingerprint` y los DTOs del contrato (`Solver\Contract`). Es la puerta que comparten la 8.7 (alternativas de una solicitud) y la 8.3b (abrir un grupo en el re-análisis).
- Solver sigue sin leer Staffing: el motor no sabe de solicitudes, rankings ni asignaciones. Lo que Staffing le pide va por los parámetros de `ProposePlacements` (los grupos que se mueven y las sesiones que se fijan).

El diagrama y `$leftOf` de `tests/Arch/ModulesTest.php` cambian en el mismo PR, como pide la prueba que los compara.

## Consecuencias
- Staffing queda a la derecha de Scheduling y de Solver, y por transitividad de todo lo que está a su izquierda. Ningún módulo a la izquierda de Staffing cambia.
- Lo que Solver devuelve a Staffing es el contrato v1 (`SolverOutput`), sin campos nuevos: el contrato con el servicio de Python no cambia.
- Una prueba de arquitectura revisa las dos direcciones: Staffing puede importar Solver y Solver no puede importar Staffing.

## Alternativas descartadas
- **Un evento de Staffing que Solver escucha y contesta con otro evento:** la pantalla tendría que esperar la respuesta (cola y sondeo) para algo que tarda menos de un segundo con la heurística en PHP, y la respuesta sería otra copia de los acomodos.
- **Una interfaz declarada en Scheduling e implementada en Solver:** Scheduling cargaría un contrato que solo le sirve al motor y a Staffing, y la dependencia real (Staffing usa el motor) quedaría escondida en el contenedor.
- **Mover las solicitudes de cambio a Solver o la llamada al motor a Scheduling:** mezcla el flujo de las solicitudes con el motor, o el motor con el editor de horarios, contra el reparto de ADR-0020 y de la fase 6.
