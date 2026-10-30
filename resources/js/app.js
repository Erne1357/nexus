/*
| nexusShell: el estado y el movimiento del shell (DESIGN.md, "El menú que se transforma" y
| "Movimiento"). Vive en <body> del layout de la aplicación y Alpine lo registra al arrancar (Alpine
| viene con Livewire). El menú abierto o cerrado está en <body data-menu>, que leen las utilidades de
| la banda y del menú; la tarjeta de la credencial, en cardOpen.
|
| El menú se transforma con FLIP: mide dónde está cada elemento, cambia de estado, mide dónde quedó,
| lo devuelve a su lugar anterior con un transform y lo deja llegar. Al abrir, con la curva de abrir,
| en cascada por área y por entrada; el buscador entra primero y los nombres y las entradas de las
| demás áreas, cuando su icono aterriza. Al cerrar, todo en un solo tiempo: la cortina se recoge, lo
| que se queda regresa junto con ella (la curva de asentar, sin rebote ni cascada) y lo que se va se
| desvanece a la vez en una copia del menú abierto. Lo que espera para moverse lleva fill backwards:
| sin él se pintaría en su lugar final mientras espera. Con prefers-reduced-motion todo cambia sin
| animar.
*/

/** Lo que tarda el menú en asentarse; un abrir o cerrar pedido mientras espera a que termine. */
const SETTLE_MS = 440;

/** Al cerrar, lo que se queda regresa en lo que tarda la cortina en recogerse (duration-[420ms] del Blade). */
const RETURN_MS = 420;

/** Al cerrar, lo que se va se desvanece en este tiempo, mientras la cortina empieza a recogerse. */
const LEAVE_MS = 120;

/** El buscador recibe el cursor cuando ya entró. */
const FOCUS_SEARCH_MS = 260;

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Las curvas de DESIGN.md, de los tokens ease-open y ease-settle de resources/css/app.css. */
const easing = (token, fallback) => getComputedStyle(document.documentElement).getPropertyValue(token).trim() || fallback;
const openEasing = () => easing('--ease-open', 'cubic-bezier(.3, 1.06, .5, 1)');
const settleEasing = () => easing('--ease-settle', 'cubic-bezier(.22, 1, .36, 1)');

/**
 * El ancho del menú cerrado que declara <body> por breakpoint (--menu-w) dice en qué disposición
 * está: 0 en el celular, la franja de 64 px en tableta y 288 px en escritorio. Así el JS no repite
 * los breakpoints del CSS.
 */
const closedMenuWidth = () => parseFloat(getComputedStyle(document.body).getPropertyValue('--menu-w')) || 0;
const isPhone = () => closedMenuWidth() === 0;
const isTablet = () => closedMenuWidth() > 0 && closedMenuWidth() <= 64;

/** Para buscar sin importar mayúsculas ni acentos: «Académico» y «academico» son iguales. */
const normalize = (text) => text.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('es');

/** El nombre normalizado de cada entrada, para no repetirlo en cada tecla. */
const searchableLabels = new WeakMap();
const searchableLabel = (entry) => {
    if (!searchableLabels.has(entry)) {
        searchableLabels.set(entry, normalize(entry.dataset.label ?? ''));
    }

    return searchableLabels.get(entry);
};

const isRendered = (element) => element.getClientRects().length > 0;

/** Lo único que conserva la copia del menú que se desvanece al cerrar: lo que decide cómo se ve. */
const GHOST_ATTRIBUTES = new Set(['class', 'style', 'hidden', 'data-current', 'data-active', 'type', 'placeholder']);

/**
 * Dónde se ve un elemento del menú, para el FLIP: si se ve (su caja) y el punto que el ojo sigue, el
 * centro del icono o el principio del texto. Entre el menú cerrado y el abierto el icono cambia de
 * ancho y el nombre de relleno: si el FLIP alineara las cajas, los dos brincarían al empezar.
 */
const placeOf = (item) => {
    const box = item.getBoundingClientRect();

    if (item.dataset.menuFlip === 'icon') {
        return { shown: box.width > 0, x: box.left + box.width / 2, y: box.top + box.height / 2 };
    }

    const text = document.createRange();
    text.selectNodeContents(item);
    const { left, top } = text.getBoundingClientRect();

    return { shown: box.width > 0, x: left, y: top };
};

const numberFrom = (element, property) => Number(element.style.getPropertyValue(property)) || 0;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nexusShell', () => ({
        menuOpen: false,

        /** Hacia dónde va el menú: cambia al empezar un movimiento, antes de que el menú cambie. */
        menuTarget: false,
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

        /** Hacia dónde quedará el menú: lo último que se pidió, aunque espere a que termine un movimiento. */
        intendedOpen() {
            return this.queued ?? this.menuTarget;
        },

        toggleMenu() {
            this.setMenu(!this.intendedOpen());
        },

        openMenu() {
            if (this.intendedOpen()) {
                this.focusSearch();
            } else {
                this.setMenu(true);
            }
        },

        closeMenu() {
            this.setMenu(false);
        },

        /** Al abrir, el foco entra a la tarjeta (es un diálogo) para que el lector de pantalla la anuncie. */
        toggleCard() {
            if (this.cardOpen) {
                this.closeCard();

                return;
            }

            this.cardOpen = true;
            this.$nextTick(() => document.getElementById('nexus-credential')?.focus());
        },

        /** Si el foco sale de la credencial con el teclado, la tarjeta se cierra. */
        onCardFocusOut(event) {
            const credential = event.currentTarget;

            if (event.relatedTarget && !credential.contains(event.relatedTarget)) {
                this.closeCard(false);
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

        /** Esc cierra solo la capa de arriba: la tarjeta si está abierta; si no, el menú. */
        onKeydown(event) {
            if (event.key === 'Escape') {
                if (this.cardOpen) {
                    this.closeCard();
                } else {
                    this.closeMenu();
                }
            }

            // Sin key, como el keydown que manda el autocompletado de Chrome, no es un atajo.
            if ((event.ctrlKey || event.metaKey) && event.key?.toLowerCase() === 'k') {
                event.preventDefault();
                this.openMenu();
            }
        },

        setMenu(open) {
            const menu = document.getElementById('nexus-menu');

            // Nunca dos movimientos encimados: lo que se pida mientras uno corre, se hace al terminar.
            if (this.busy) {
                this.queued = open;

                return;
            }

            if (!menu || open === this.menuTarget) {
                return;
            }

            this.busy = true;
            this.menuTarget = open;
            this.cardOpen = false;

            const reduce = reducedMotion();
            const items = [...menu.querySelectorAll('[data-menu-flip]')];

            // Dónde está cada elemento, con lo que lleve de un movimiento anterior.
            const first = new Map(items.map((item) => [item, placeOf(item)]));

            if (!open && !reduce) {
                this.fadeOut(menu, items);
            }

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

            if (queued !== null && queued !== this.menuTarget) {
                this.setMenu(queued);
            }
        },

        /**
         * Lleva cada elemento de donde estaba (first) a donde quedó. Al abrir, lo que no se veía entra
         * en su lugar: el buscador de inmediato; lo demás, cuando su icono aterriza (en el celular, en
         * cascada). Al cerrar, todo regresa junto con la cortina, y lo que no se veía (lo que ocultó la
         * búsqueda) aparece en su lugar a la vez.
         */
        play(items, first, open) {
            const phone = isPhone();
            const spring = openEasing();
            const settle = settleEasing();

            // Primero todas las lecturas y después todas las animaciones, para no forzar el layout
            // en cada elemento.
            const last = new Map(items.map((item) => [item, placeOf(item)]));

            items.forEach((item, order) => {
                const from = first.get(item);
                const to = last.get(item);

                if (!to.shown) {
                    return;
                }

                const area = numberFrom(item, '--c');
                const index = numberFrom(item, '--i');

                if (!from.shown) {
                    const delay = !open ? 0 : item.dataset.menuFlip === 'search'
                        ? 90
                        : (phone ? 120 + order * 14 : 340 + area * 35 + index * 22);

                    item.animate(
                        [{ opacity: 0, transform: 'translateY(-8px)' }, { opacity: 1, transform: 'none' }],
                        open
                            ? { duration: 320, delay, easing: spring, fill: 'backwards' }
                            : { duration: RETURN_MS, easing: settle },
                    );

                    return;
                }

                const dx = from.x - to.x;
                const dy = from.y - to.y;

                if (!dx && !dy) {
                    return;
                }

                item.animate(
                    [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }],
                    open
                        ? { duration: 580, delay: area * 30 + index * 14, easing: spring, fill: 'backwards' }
                        : { duration: RETURN_MS, easing: settle },
                );
            });
        },

        /**
         * Al cerrar, lo que se va desaparece del menú en cuanto cambia el estado. Para que se desvanezca
         * mientras la cortina ya se recoge, queda una copia del menú abierto con solo lo que se va, sin
         * nada que se pueda tocar, leer ni buscar: se desvanece recortada con la cortina y se quita. Va
         * debajo del menú, así lo que regresa pasa por encima de ella.
         */
        fadeOut(menu, items) {
            const leaving = new Set(items.filter((item) => isRendered(item) && !this.staysWhenClosed(item)));

            if (leaving.size === 0) {
                return;
            }

            // Clonar copia también lo escrito en el buscador.
            const copy = menu.cloneNode(true);
            const twins = copy.querySelectorAll('[data-menu-flip]');

            items.forEach((item, index) => {
                if (!leaving.has(item)) {
                    twins[index].style.visibility = 'hidden';
                } else if (item.getAnimations().length > 0) {
                    // A medio abrir: la copia sale de donde iba, no de donde terminaría.
                    const { opacity, transform } = getComputedStyle(item);
                    twins[index].style.opacity = opacity;
                    twins[index].style.transform = transform;
                }
            });

            // Sin ids, nombres accesibles, enlaces ni directivas: nada que una prueba o un lector confunda con el menú.
            [copy, ...copy.querySelectorAll('*')].forEach((element) => {
                if (!(element instanceof SVGElement)) {
                    [...element.attributes]
                        .filter(({ name }) => !GHOST_ATTRIBUTES.has(name))
                        .forEach(({ name }) => element.removeAttribute(name));
                }
            });

            // Las utilidades del menú abierto leen data-menu="open" de un ancestro.
            const ghost = document.createElement('div');
            ghost.dataset.menu = 'open';
            ghost.dataset.menuGhost = '';
            ghost.inert = true;
            ghost.append(copy);
            menu.before(ghost);
            copy.scrollTop = menu.scrollTop;

            const curtain = getComputedStyle(document.body).getPropertyValue('--panel-clip').trim();
            copy.animate([{ clipPath: 'inset(0)' }, { clipPath: curtain }], { duration: RETURN_MS, easing: settleEasing() });
            // Cancelada (al cambiar de página a medio cerrar) también se quita; no es un error.
            copy.animate([{ opacity: 1 }, { opacity: 0 }], { duration: LEAVE_MS, easing: 'ease-out', fill: 'forwards' })
                .finished.catch(() => {})
                .finally(() => ghost.remove());
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
                { duration: 500, easing: openEasing() },
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
                    const match = needle === '' || searchableLabel(entry).includes(needle);

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
            document.querySelectorAll('[data-menu-ghost]').forEach((ghost) => ghost.remove());
            this.apply(false);
            this.menuTarget = false;
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

/*
| studentGrid: las cadenas de seriación de la retícula del alumno (DESIGN.md, "Retícula del alumno"). Al
| tocar una materia se resalta su cadena (lo que pide y lo que abre, hasta el final) y se atenúa lo demás;
| al tocar una cadena de la leyenda, todas sus materias. Tocar lo mismo otra vez o Esc lo quita. Solo con
| clic, no al pasar el cursor (decidido por el usuario el 29 de octubre de 2026). La seriación viene en
| data-prereqs de cada materia; el resaltado se pinta con data-focus en la retícula y data-me / data-on-chain
| en las materias, que leen las utilidades de Tailwind.
*/
document.addEventListener('alpine:init', () => {
    window.Alpine.data('studentGrid', () => ({
        /** { code } de una materia, { chain } de una cadena, o null. */
        selected: null,
        hint: '',
        tiles: [],
        byCode: {},
        pre: {},
        post: {},

        /** La seriación no cambia mientras la ficha está abierta: se arma una vez. */
        init() {
            this.hint = this.$refs.info.textContent.trim();
            this.tiles = [...this.$root.querySelectorAll('[data-subject]')];
            this.byCode = Object.fromEntries(this.tiles.map((tile) => [tile.dataset.subject, tile]));

            for (const tile of this.tiles) {
                for (const prerequisite of (tile.dataset.prereqs ?? '').split(' ').filter((code) => this.byCode[code])) {
                    (this.pre[tile.dataset.subject] ??= []).push(prerequisite);
                    (this.post[prerequisite] ??= []).push(tile.dataset.subject);
                }
            }
        },

        toggleSubject(tile) {
            const code = tile.dataset.subject;
            this.select(this.selected?.code === code ? null : { code });
        },

        toggleChain(chain) {
            this.select(this.selected?.chain === chain ? null : { chain });
        },

        /** Esc cierra solo la capa de arriba (DESIGN.md, "Movimiento"): con el menú o un diálogo abierto, no toca el resaltado. */
        clearUnlessLayerOpen() {
            const layerOpen = document.body.dataset.menu === 'open' || document.querySelector('dialog[open]') !== null;

            if (this.selected !== null && !layerOpen) {
                this.select(null);
            }
        },

        walk(graph, start) {
            const seen = new Set();
            const pending = [start];

            while (pending.length) {
                for (const next of graph[pending.pop()] ?? []) {
                    if (!seen.has(next)) {
                        seen.add(next);
                        pending.push(next);
                    }
                }
            }

            return seen;
        },

        select(selection) {
            this.selected = selection;
            let chain = new Set();
            let text = this.hint;

            if (selection?.code) {
                const name = (code) => `${code} ${this.byCode[code].dataset.name}`;
                const requires = this.pre[selection.code] ?? [];
                const opens = this.post[selection.code] ?? [];
                chain = new Set([selection.code, ...this.walk(this.pre, selection.code), ...this.walk(this.post, selection.code)]);
                text = `${name(selection.code)}: `
                    + (requires.length ? `pide ${requires.map(name).join(', ')}` : 'no pide seriación')
                    + ' · '
                    + (opens.length ? `abre ${opens.map(name).join(', ')}` : 'no abre ninguna');
            } else if (selection?.chain !== undefined) {
                const chip = this.$root.querySelector(`[data-chain-chip][data-chain="${selection.chain}"]`);
                const members = this.tiles.filter((tile) => tile.dataset.chain === selection.chain);
                chain = new Set(members.map((tile) => tile.dataset.subject));
                text = `Cadena de ${chip?.dataset.name ?? ''}: ${members.length} materias.`;
            }

            this.$refs.grid.toggleAttribute('data-focus', selection !== null);

            for (const tile of this.tiles) {
                tile.toggleAttribute('data-on-chain', chain.has(tile.dataset.subject));
                tile.toggleAttribute('data-me', selection?.code === tile.dataset.subject);
                tile.setAttribute('aria-pressed', selection?.code === tile.dataset.subject ? 'true' : 'false');
            }

            for (const chip of this.$root.querySelectorAll('[data-chain-chip]')) {
                chip.setAttribute('aria-pressed', selection?.chain === chip.dataset.chain ? 'true' : 'false');
            }

            this.$refs.info.textContent = text;
        },
    }));
});
