/*
| nexusShell: el estado y el movimiento del shell (DESIGN.md, "El menú que se transforma" y
| "Movimiento"). Vive en <body> del layout de la aplicación y Alpine lo registra al arrancar (Alpine
| viene con Livewire). El menú abierto o cerrado está en <body data-menu>, que leen las utilidades de
| la banda y del menú; la tarjeta de la credencial, en cardOpen.
|
| El menú se transforma con FLIP: mide dónde está cada elemento, cambia de estado, mide dónde quedó,
| lo devuelve a su lugar anterior con un transform y lo deja llegar. Al abrir, con la curva de abrir,
| en cascada por área y por entrada; el buscador entra primero y los nombres y las entradas de las
| demás áreas, cuando su icono aterriza. Al cerrar, lo que se va se desvanece en 110 ms y lo demás
| regresa con la curva de asentar, sin rebote. Con prefers-reduced-motion todo cambia sin animar.
*/

const OPEN_EASING = 'cubic-bezier(.3, 1.06, .5, 1)';
const SETTLE_EASING = 'cubic-bezier(.22, 1, .36, 1)';

/** Lo que tarda el menú en asentarse; un abrir o cerrar pedido mientras espera a que termine. */
const SETTLE_MS = 440;

/** El buscador recibe el cursor cuando ya entró. */
const FOCUS_SEARCH_MS = 260;

const matches = (query) => window.matchMedia(query).matches;
const reducedMotion = () => matches('(prefers-reduced-motion: reduce)');
const isPhone = () => matches('(width < 48rem)');
const isTablet = () => !isPhone() && matches('(width < 75rem)');

/** Para buscar sin importar mayúsculas ni acentos: «Académico» y «academico» son iguales. */
const normalize = (text) => text.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('es');

const isRendered = (element) => element.getClientRects().length > 0;

const numberFrom = (element, property) => Number(element.style.getPropertyValue(property)) || 0;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nexusShell', () => ({
        menuOpen: false,
        cardOpen: false,
        busy: false,
        queued: null,
        timers: [],

        init() {
            // Livewire guarda la página para el botón de atrás justo después de este evento: se
            // guarda cerrada y sin búsqueda.
            this.onNavigating = () => this.reset();
            document.addEventListener('livewire:navigating', this.onNavigating);
        },

        destroy() {
            document.removeEventListener('livewire:navigating', this.onNavigating);
            this.timers.forEach((timer) => clearTimeout(timer));
        },

        toggleMenu() {
            this.setMenu(!this.menuOpen);
        },

        openMenu() {
            if (this.menuOpen) {
                this.focusSearch();
            } else {
                this.setMenu(true);
            }
        },

        closeMenu() {
            this.setMenu(false);
        },

        toggleCard() {
            if (this.cardOpen) {
                this.closeCard();
            } else {
                this.cardOpen = true;
            }
        },

        closeCard(returnFocus = true) {
            if (!this.cardOpen) {
                return;
            }

            this.cardOpen = false;

            if (returnFocus) {
                document.getElementById('nexus-credential-toggle')?.focus();
            }
        },

        onKeydown(event) {
            if (event.key === 'Escape') {
                this.closeCard();
                this.closeMenu();
            }

            // Sin key, como el keydown que manda el autocompletado de Chrome, no es un atajo.
            if ((event.ctrlKey || event.metaKey) && event.key?.toLowerCase() === 'k') {
                event.preventDefault();
                this.openMenu();
            }
        },

        async setMenu(open) {
            const menu = document.getElementById('nexus-menu');

            // Nunca dos movimientos encimados: lo que se pida mientras uno corre, se hace al terminar.
            if (this.busy) {
                this.queued = open;

                return;
            }

            if (!menu || open === this.menuOpen) {
                return;
            }

            this.busy = true;
            this.cardOpen = false;

            const reduce = reducedMotion();
            const items = [...menu.querySelectorAll('[data-menu-flip]')];

            if (!open && !reduce) {
                const leaving = items.filter((item) => isRendered(item) && !this.staysWhenClosed(item));

                // Una animación cancelada (al cambiar de página a medio cerrar) no es un error.
                await Promise.all(leaving.map((item, index) => item.animate(
                    [{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-4px)' }],
                    { duration: 110, delay: (leaving.length - index) * 3, easing: 'ease-in', fill: 'forwards' },
                ).finished.catch(() => {})));

                if (!this.menuOpen) {
                    return;
                }
            }

            const first = new Map(items.map((item) => [item, item.getBoundingClientRect()]));

            this.apply(open);
            items.forEach((item) => item.getAnimations().forEach((animation) => animation.cancel()));

            if (!reduce) {
                if (open) {
                    this.hop();
                }

                this.play(items, first, open);
            }

            if (open) {
                this.later(() => this.focusSearch(), reduce ? 0 : FOCUS_SEARCH_MS);
            } else {
                document.getElementById('nexus-hare')?.focus();
            }

            this.later(() => this.settle(), reduce ? 0 : SETTLE_MS);
        },

        /** Termina un movimiento y hace el que se pidió mientras corría, si cambia algo. */
        settle() {
            this.busy = false;

            const queued = this.queued;
            this.queued = null;

            if (queued !== null && queued !== this.menuOpen) {
                this.setMenu(queued);
            }
        },

        /**
         * Lleva cada elemento de donde estaba (first) a donde quedó. Lo que no se veía entra en
         * su lugar: el buscador de inmediato; lo demás, cuando su icono aterriza (en el celular, en
         * cascada).
         */
        play(items, first, open) {
            const phone = isPhone();

            items.forEach((item, order) => {
                const from = first.get(item);
                const to = item.getBoundingClientRect();

                if (!to.width) {
                    return;
                }

                const area = numberFrom(item, '--c');
                const index = numberFrom(item, '--i');

                if (!from.width) {
                    const delay = item.dataset.menuFlip === 'search'
                        ? 90
                        : (phone ? 120 + order * 14 : 340 + area * 35 + index * 22);

                    item.animate(
                        [{ opacity: 0, transform: 'translateY(-8px)' }, { opacity: 1, transform: 'none' }],
                        { duration: 320, delay, easing: OPEN_EASING, fill: 'backwards' },
                    );

                    return;
                }

                const dx = from.left - to.left;
                const dy = from.top - to.top;

                if (!dx && !dy) {
                    return;
                }

                item.animate(
                    [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }],
                    open
                        ? { duration: 580, delay: area * 30 + index * 14, easing: OPEN_EASING }
                        : { duration: 420, delay: index * 6, easing: SETTLE_EASING },
                );
            });
        },

        /** Cambia de estado sin animar: <body data-menu>, la liebre, el contenido y la búsqueda. */
        apply(open) {
            this.menuOpen = open;
            document.body.dataset.menu = open ? 'open' : 'closed';

            const hare = document.getElementById('nexus-hare');
            hare?.setAttribute('aria-expanded', String(open));
            hare?.setAttribute('aria-label', open ? 'Cerrar el menú completo' : 'Abrir el menú completo');

            // Con el menú abierto, el contenido de atrás no recibe el foco ni los clics.
            document.getElementById('nexus-content')?.toggleAttribute('inert', open);

            if (!open) {
                this.clearSearch();
            }
        },

        /** Lo que se ve con el menú cerrado: en escritorio, los iconos y el área actual; en tableta, los iconos; en el celular, nada. */
        staysWhenClosed(item) {
            if (isPhone()) {
                return false;
            }

            return isTablet() ? item.dataset.menuFlip === 'icon' : 'keep' in item.dataset;
        },

        /** La liebre da un salto de 4 px al abrir. */
        hop() {
            document.querySelector('#nexus-hare [data-hare-mark]')?.animate(
                [{ transform: 'none' }, { transform: 'translate(1px, -4px) rotate(-4deg)', offset: 0.4 }, { transform: 'none' }],
                { duration: 500, easing: OPEN_EASING },
            );
        },

        focusSearch() {
            document.getElementById('nexus-menu-search')?.focus();
        },

        /**
         * Deja solo las entradas cuyo nombre contiene lo buscado, sin importar mayúsculas ni acentos,
         * y las vuelve a numerar para que suban dentro de su columna. Oculta las áreas sin ninguna y,
         * si no queda ninguna entrada, muestra «Sin resultados».
         */
        filterMenu(query) {
            const menu = document.getElementById('nexus-menu');

            if (!menu) {
                return;
            }

            const needle = normalize(query.trim());
            let total = 0;

            menu.querySelectorAll('[data-menu-area]').forEach((area) => {
                let shown = 0;

                area.querySelectorAll('[data-menu-entry]').forEach((entry) => {
                    const match = needle === '' || normalize(entry.dataset.label).includes(needle);

                    entry.hidden = !match;

                    if (match) {
                        shown += 1;
                        entry.style.setProperty('--i', String(shown));
                    }
                });

                area.hidden = shown === 0;
                total += shown;
            });

            const empty = menu.querySelector('[data-menu-empty]');

            if (empty) {
                empty.hidden = total > 0;
            }
        },

        clearSearch() {
            const search = document.getElementById('nexus-menu-search');

            if (search) {
                search.value = '';
            }

            this.filterMenu('');
        },

        /** Enter en el buscador abre la primera entrada que quedó. */
        openFirstMatch() {
            const entries = document.querySelectorAll('#nexus-menu [data-menu-entry]');

            [...entries].find((entry) => !entry.closest('[hidden]'))?.click();
        },

        /** Cierra todo sin animar, antes de que Livewire cambie de página. */
        reset() {
            this.timers.forEach((timer) => clearTimeout(timer));
            this.timers = [];
            document.querySelectorAll('#nexus-menu [data-menu-flip]').forEach((item) => item.getAnimations().forEach((animation) => animation.cancel()));
            this.apply(false);
            this.cardOpen = false;
            this.busy = false;
            this.queued = null;
        },

        later(callback, delay) {
            const timer = setTimeout(() => {
                this.timers = this.timers.filter((pending) => pending !== timer);
                callback();
            }, delay);

            this.timers.push(timer);
        },
    }));
});
