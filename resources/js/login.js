/*
| nexusLogin: el movimiento de la pantalla de acceso G (tarea 1.17; DESIGN.md, "Pantalla de acceso"). Lo carga
| solo el login (@vite en core::auth.login) y Alpine, que viene con Livewire, lo registra al arrancar.
|
| - Bienvenida: la primera vez en ese equipo la foto se abre desde la orilla, los logos suben y la marca se
|   escribe de izquierda a derecha (~1.7 s); después, solo la marca, en medio segundo. Cualquier tecla o toque
|   termina la bienvenida. La marca de «ya vino» es nexus.login.visto en localStorage y se pone al verla.
| - Avisos (LG-01): cambian cada 7 s; el punto del aviso actual se llena mientras dura. Se detienen al pasar
|   el mouse o con el foco dentro.
| - Atajos (LG-02): caen en el lugar del formulario de arriba hacia abajo, uno tras otro, con un rebote corto.
| Con prefers-reduced-motion nada se anima ni avanza solo.
*/

const SEEN_KEY = 'nexus.login.visto';
const NOTICE_MS = 7000;
const OPEN = 'cubic-bezier(.3,1.06,.5,1)';
const SETTLE = 'cubic-bezier(.22,1,.36,1)';
const PEN = 'cubic-bezier(.45,.05,.35,1)';
const SKEW = 'skewX(-14deg)';

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const desktop = () => window.matchMedia('(min-width: 1024px)').matches;

const storage = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // Sin almacenamiento (modo privado): la bienvenida completa se repite, nada más.
        }
    },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nexusLogin', ({ total }) => ({
        view: 'login',
        forgotOpen: false,
        current: 0,
        held: false,
        progress: null,
        running: [],

        init() {
            this.welcome(!storage.get(SEEN_KEY));
            storage.set(SEEN_KEY, '1');
            this.$nextTick(() => this.runProgress());
            ['keydown', 'pointerdown'].forEach((type) => window.addEventListener(type, () => this.finishWelcome(), { capture: true, once: true }));
            window.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && this.view === 'shortcuts') {
                    this.closeShortcuts();
                }
            });
        },

        /* ---------- Bienvenida ---------- */

        animate(element, keyframes, options) {
            if (!element) {
                return;
            }
            this.running.push(element.animate(keyframes, { fill: 'backwards', ...options }));
        },

        finishWelcome() {
            this.running.forEach((animation) => {
                try {
                    animation.finish();
                } catch {
                    // Ya terminó.
                }
            });
            this.running = [];
        },

        writeMark(delay, duration) {
            const mark = this.$root.querySelector('[data-login-mark]');
            const sweep = mark?.querySelector('[data-barrido]');
            const distance = (mark?.viewBox.baseVal.width ?? 3000) + 1000;
            this.animate(sweep, [{ transform: `translateX(${-distance}px) ${SKEW}` }, { transform: `translateX(0) ${SKEW}` }], { duration, delay, easing: PEN });
        },

        welcome(firstTime) {
            if (reducedMotion()) {
                return;
            }
            if (!firstTime) {
                this.writeMark(60, 460);
                return;
            }
            if (desktop()) {
                this.animate(this.$root.querySelector('[data-login-photos]'), [{ clipPath: 'inset(0 0 0 100%)' }, { clipPath: 'inset(0 0 0 0)' }], { duration: 820, easing: SETTLE });
            }
            this.animate(this.$root.querySelector('[data-login-logos]'), [{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: 420, delay: 140, easing: SETTLE });
            this.writeMark(320, 760);
            this.animate(this.$root.querySelector('[data-login-view="login"]'), [{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 440, delay: 1080, easing: SETTLE });
        },

        /* ---------- Avisos ---------- */

        go(index) {
            this.current = (index + total) % total;
            this.$refs.slides.setAttribute('aria-live', 'polite');
            this.$nextTick(() => this.runProgress());
        },

        /* El mouse detiene el carrusel solo donde hay mouse: en pantallas táctiles un toque no trae mouseleave. */
        hover(over) {
            if (window.matchMedia('(hover: hover)').matches) {
                this.hold(over);
            }
        },

        hold(held) {
            this.held = held;
            if (this.progress) {
                held ? this.progress.pause() : this.progress.play();
            }
        },

        runProgress() {
            this.progress?.cancel();
            this.progress = null;
            if (total < 2 || reducedMotion()) {
                return;
            }
            const bar = this.$root.querySelectorAll('[data-progress]')[this.current];
            this.progress = bar.animate([{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], { duration: NOTICE_MS });
            this.progress.onfinish = () => {
                // Al avanzar solo, el lector de pantalla no anuncia cada aviso.
                this.$refs.slides.setAttribute('aria-live', 'off');
                this.current = (this.current + 1) % total;
                this.$nextTick(() => this.runProgress());
            };
            if (this.held) {
                this.progress.pause();
            }
        },

        /* ---------- Atajos ---------- */

        openShortcuts() {
            this.view = 'shortcuts';
            this.$nextTick(() => {
                const section = this.$root.querySelector('[data-login-view="shortcuts"]');
                if (!desktop()) {
                    this.$root.querySelector('main').scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
                }
                section.querySelector('h2').focus({ preventScroll: true });
                if (reducedMotion()) {
                    return;
                }
                section.querySelectorAll('[data-drop]').forEach((element, index) => {
                    element.animate(
                        [
                            { opacity: 0, transform: 'translateY(-16px)' },
                            { opacity: 1, transform: 'translateY(4px)', offset: 0.62 },
                            { transform: 'translateY(-1.5px)', offset: 0.84 },
                            { opacity: 1, transform: 'none' },
                        ],
                        { duration: 520, delay: index * 75, easing: 'cubic-bezier(.25,.8,.35,1)', fill: 'backwards' },
                    );
                });
            });
        },

        closeShortcuts() {
            this.view = 'login';
            this.$nextTick(() => {
                const section = this.$root.querySelector('[data-login-view="login"]');
                if (!reducedMotion()) {
                    section.animate([{ opacity: 0, transform: 'translateX(-28px)' }, { opacity: 1, transform: 'none' }], { duration: 340, easing: OPEN });
                }
                this.$refs.shortcutsButton?.focus({ preventScroll: true });
                this.hold(false);
            });
        },
    }));
});
