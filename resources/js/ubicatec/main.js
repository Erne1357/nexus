// Punto de entrada del mapa del campus (Vite). Registra <three-d-stage> y arranca el mapa cuando existe #ubicatec-root
// en la página; la página puede llegar también por wire:navigate. Al salir libera el renderer (ADR-0030).
import './three-d-stage.js';
import { boot } from './app.js';

function start() {
    const root = document.getElementById('ubicatec-root');
    if (!root || root.dataset.booted) return;
    root.dataset.booted = '1';
    boot(root)
        .then((api) => {
            if (api) root.__ubicatec = api;
        })
        .catch((err) => {
            root.classList.add('ut-failed');
            console.error('[UbicaTec]', err);
        });
}

function stop() {
    const root = document.getElementById('ubicatec-root');
    const api = root && root.__ubicatec;
    if (api) {
        api.destroy();
        root.__ubicatec = null;
    }
}

document.addEventListener('DOMContentLoaded', start);
document.addEventListener('livewire:navigated', start);
document.addEventListener('livewire:navigating', stop);
// El selector de salón (scheduling::components.map-picker) monta el mapa dentro de un cajón, después de la carga.
window.addEventListener('ubicatec:mount', start);
if (document.readyState !== 'loading') start();
