# ADR-0003 — Livewire 4 MFC con MaryUI y daisyUI

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
No hay sistema de diseño definido y hay poco tiempo para construir componentes.

## Decisión
Livewire 4 con componentes multi-archivo; MaryUI (sobre daisyUI y Tailwind 4) como base de componentes; tema por tokens de daisyUI. Sin SPA ni API para la UI.

## Consecuencias
Las pantallas se construyen rápido y uniformes. Las interacciones ricas (editor de horarios) usan Alpine dentro de Livewire. El tema institucional se define en `DESIGN.md` cuando exista.

## Alternativas descartadas
Flux UI (varios componentes necesarios son de la edición de pago). Tailwind y Alpine a mano (más tiempo).
