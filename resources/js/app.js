/*
| nexusShell: el estado y el movimiento del shell (DESIGN.md, "El menú que se transforma" y
| "Movimiento"). Vive en <body> del layout de la aplicación y Alpine lo registra al arrancar (Alpine
| viene con Livewire). El menú abierto o cerrado está en <body data-menu>, que leen las utilidades de
| la banda y del menú; la tarjeta de la credencial, en cardOpen.
|
| El menú se transforma con FLIP: mide dónde está cada elemento, cambia de estado, mide dónde quedó,
| lo devuelve a su lugar anterior con un transform y lo deja llegar. Al abrir, con la curva de abrir,
| en cascada por columna y por renglón, sin pasar de CASCADE_MAX_MS; el buscador entra primero y los
| nombres, encabezados y entradas de las demás áreas, cuando su icono aterriza. Al cerrar, todo en un
| solo tiempo: la cortina se recoge, lo que se queda regresa junto con ella (la curva de asentar, sin
| rebote ni cascada) y lo que se va se desvanece a la vez en una copia del menú abierto. Lo que espera
| para moverse lleva fill backwards: sin él se pintaría en su lugar final mientras espera. Con
| prefers-reduced-motion todo cambia sin animar.
|
| Cerrado, la franja de iconos se queda fija y la columna del área actual se desplaza sola: al cargar
| deja a la vista la entrada activa, se desvanece arriba y abajo cuando hay más y, al cerrar el menú,
| regresa a donde estaba.
*/

/** Lo que tarda el menú en asentarse; un abrir o cerrar pedido mientras espera a que termine. */
const SETTLE_MS = 440;

/** Al abrir, ningún elemento espera más que esto para moverse o entrar, aunque el menú sea largo. */
const CASCADE_MAX_MS = 400;

/** La columna cerrada se desvanece en esta altura donde hay más arriba o abajo. */
const FADE_TOP_PX = 32;
const FADE_BOTTOM_PX = 56;

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

/** El motivo flotante de lo oculto para los demás: uno solo, junto al menú (MN-02). */
const whyTip = () => document.querySelector('[data-menu-why-tip]');

/** Lo que lleva motivo (data-why) en lo que tocó el cursor o el foco, o null. */
const whySourceOf = (target) => (target instanceof Element ? target.closest('[data-why]') : null);

/**
 * Los renglones con el motivo escrito de un encabezado o una entrada, que solo se ven en la hoja del celular
 * (data-menu-note): van justo después de él, antes del siguiente elemento del menú. El buscador los esconde
 * junto con lo suyo.
 */
const notesOf = (row) => {
    const notes = [];

    for (let next = row.nextElementSibling; next && !next.hasAttribute('data-menu-flip'); next = next.nextElementSibling) {
        if (next.hasAttribute('data-menu-note')) {
            notes.push(next);
        }
    }

    return notes;
};

/** Columnas del menú abierto en escritorio y en tableta (las de --menu-cols-open del menú). */
const DESKTOP_COLUMNS = 5;
const TABLET_COLUMNS = 3;

/**
 * Renglón del nombre de la primera área y renglones de una columna: los de MenuGrid, que el menú trae en
 * data-grid-first-row y data-grid-column-rows para que no haya dos copias de las constantes.
 */
const gridRows = (menu) => ({
    firstRow: Number(menu.dataset.gridFirstRow) || 3,
    columnRows: Number(menu.dataset.gridColumnRows) || 12,
});

/**
 * La retícula del menú abierto: el mismo algoritmo que App\Modules\Core\Navigation\MenuGrid::place, para
 * reacomodar sin huecos lo que deja el buscador. Con el buscador vacío debe dar exactamente lo que pintó
 * el servidor (lo revisa tests/Browser/Core/ShellTest.php). Recibe las áreas en orden, cada una con
 * `headings` y sus grupos ({ heading, entries }: si se ve su encabezado y cuántas entradas tiene), y da
 * por área { icon, name, groups: [{ heading, entries }] } con { column, row, span } en cada lugar.
 */
const packMenu = (areas, columns, { firstRow, columnRows }) => {
    const units = areas.map((area) => {
        const blocks = area.groups.map((group, index) => [
            ...(group.heading ? [[index, true]] : []),
            ...Array.from({ length: group.entries }, () => [index, false]),
        ]);

        if (!area.headings) {
            return { columns: [blocks.flat()], stacks: true };
        }

        const split = [[]];

        blocks.forEach((block) => {
            const current = split[split.length - 1];

            if (current.length > 0 && current.length + block.length > columnRows - 1) {
                split.push([...block]);
            } else {
                current.push(...block);
            }
        });

        while (split.length > columns) {
            const extra = split.pop();
            split[split.length - 1].push(...extra);
        }

        return { columns: split, stacks: false };
    });

    const tallest = Math.max(columnRows, ...units.map((unit) => (unit.columns.length === 1 ? unit.columns[0].length + 1 : 0)));
    const cell = (column, row, span = 1) => ({ column, row, span });
    let top = firstRow;
    let column = 0;
    let bandBottom = firstRow;
    let last = null;

    return areas.map((area, index) => {
        const unit = units[index];
        const width = unit.columns.length;
        const height = 1 + Math.max(...unit.columns.map((rows) => rows.length));
        let firstColumn;
        let firstRow;

        if (unit.stacks && last?.stacks && last.width === 1 && last.bottom + 2 + height <= top + tallest) {
            [firstColumn, firstRow] = [last.column, last.bottom + 2];
        } else {
            if (column + width > columns) {
                top = bandBottom + 1;
                column = 0;
            }

            [firstColumn, firstRow] = [column + 1, top];
            column += width;
        }

        const groups = area.groups.map(() => ({ heading: null, entries: [] }));

        unit.columns.forEach((rows, offset) => rows.forEach(([group, isHeading], line) => {
            const place = cell(firstColumn + offset, firstRow + 1 + line);

            if (isHeading) {
                groups[group].heading = place;
            } else {
                groups[group].entries.push(place);
            }
        }));

        const bottom = firstRow + height - 1;
        bandBottom = Math.max(bandBottom, bottom + 1);
        last = { column: firstColumn, bottom, width, stacks: unit.stacks };

        return { icon: cell(firstColumn, firstRow), name: cell(firstColumn, firstRow, width), groups };
    });
};

/** Pone en un elemento del menú su lugar en la retícula de cinco columnas y en la de tres. */
const placeInGrid = (element, wide, narrow) => {
    element.style.setProperty('--oc', String(wide.column));
    element.style.setProperty('--or', String(wide.row));
    element.style.setProperty('--os', String(wide.span));
    element.style.setProperty('--oc3', String(narrow.column));
    element.style.setProperty('--or3', String(narrow.row));
    element.style.setProperty('--os3', String(narrow.span));
};

/** Columna y renglón de un elemento en la retícula del menú abierto que se ve, para la cascada. */
const gridPlaceOf = (item) => (isTablet()
    ? { column: numberFrom(item, '--oc3'), row: numberFrom(item, '--or3') }
    : { column: numberFrom(item, '--oc'), row: numberFrom(item, '--or') });

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nexusShell', () => ({
        menuOpen: false,

        /** Hacia dónde va el menú: cambia al empezar un movimiento, antes de que el menú cambie. */
        menuTarget: false,
        cardOpen: false,
        busy: false,
        queued: null,
        timers: [],

        /** Quién abrió el motivo flotante: 'pointer', 'focus' o null. */
        whyOwner: null,

        /** Dónde estaba la columna cerrada al abrir el menú, para regresarla ahí al cerrar. */
        columnScroll: 0,

        init() {
            // Livewire guarda la página para el botón de atrás justo después de este evento: se
            // guarda cerrada y sin búsqueda.
            this.onNavigating = () => this.reset();
            document.addEventListener('livewire:navigating', this.onNavigating);

            // Cada página llega con su menú: la entrada activa, a la vista en la columna.
            requestAnimationFrame(() => this.revealActive());
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

        /**
         * Esc cierra solo la capa de arriba: la tarjeta si está abierta; si no, el motivo flotante que abrió el foco;
         * si no, el menú (y con él el motivo que abrió el cursor).
         */
        onKeydown(event) {
            if (event.key === 'Escape') {
                if (this.cardOpen) {
                    this.closeCard();
                } else if (this.whyOwner === 'focus') {
                    this.hideWhy();
                } else {
                    this.hideWhy();
                    this.closeMenu();
                }
            }

            // Sin key, como el keydown que manda el autocompletado de Chrome, no es un atajo.
            if ((event.ctrlKey || event.metaKey) && event.key?.toLowerCase() === 'k') {
                event.preventDefault();
                this.openMenu();
            }

            if (this.menuOpen && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
                this.moveThroughEntries(event);
            }
        },

        /**
         * Con el menú abierto, ↓ y ↑ recorren las entradas que se ven, en el orden del menú, desde el
         * buscador o desde la entrada que tiene el foco; al llegar al final, dan la vuelta.
         */
        moveThroughEntries(event) {
            const entries = [...document.querySelectorAll('#nexus-menu [data-menu-entry]')].filter(isRendered);
            const at = entries.indexOf(document.activeElement);
            const next = event.key === 'ArrowDown'
                ? entries[at + 1] ?? entries[0]
                : entries[at - 1] ?? entries[entries.length - 1];

            if (next) {
                event.preventDefault();
                next.focus();
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
            this.hideWhy();
            this.cardOpen = false;

            const reduce = reducedMotion();
            const items = [...menu.querySelectorAll('[data-menu-flip]')];

            // Dónde está cada elemento, con lo que lleve de un movimiento anterior.
            const first = new Map(items.map((item) => [item, placeOf(item)]));

            // Abierta, la columna deja de desplazarse y pierde su lugar: se recuerda para cerrar.
            if (open) {
                this.columnScroll = menu.querySelector('[data-menu-column]')?.scrollTop ?? 0;
            }

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

        /**
         * El motivo flotante de lo oculto para los demás (MN-02) tiene dueño: el cursor o el foco, el que lo abrió, y
         * solo ese lo cierra. Lo abierto por el foco manda: mientras lo tenga, el cursor no lo mueve ni lo cierra.
         */
        onWhyPointer(target) {
            if (this.whyOwner === 'focus') {
                return;
            }

            const source = whySourceOf(target);

            if (source) {
                this.showWhy(source, 'pointer');
            } else {
                this.hideWhy('pointer');
            }
        },

        onWhyFocus(target) {
            const source = whySourceOf(target);

            if (source) {
                this.showWhy(source, 'focus');
            } else {
                this.hideWhy('focus');
            }
        },

        /**
         * Muestra el motivo de algo con data-why junto a su entrada, su encabezado o el nombre de su área: a la
         * derecha con el menú cerrado; abierto o en el celular, debajo, o arriba si abajo no cabe.
         */
        showWhy(source, owner) {
            const tip = whyTip();
            const anchor = source.closest('[data-menu-entry], [data-menu-group], [data-menu-flip="name"]') ?? source;
            const box = anchor.getBoundingClientRect();

            if (!tip || box.width === 0) {
                this.hideWhy();

                return;
            }

            tip.querySelector('[data-menu-why-text]').textContent = source.dataset.why;

            const size = tip.getBoundingClientRect();
            const below = this.menuOpen || isPhone();
            const left = below ? Math.min(box.left + 10, window.innerWidth - size.width - 12) : box.right + 10;
            let top = below ? box.bottom + 4 : box.top + box.height / 2 - size.height / 2;

            if (below && top + size.height > window.innerHeight - 8) {
                top = box.top - size.height - 4;
            }

            const shellTop = parseFloat(getComputedStyle(document.body).getPropertyValue('--shell-top')) || 0;

            tip.style.left = `${Math.max(8, left)}px`;
            tip.style.top = `${Math.max(shellTop + 4, top)}px`;
            tip.toggleAttribute('data-on', true);
            this.whyOwner = owner;
        },

        /**
         * Esconde el motivo flotante. Con dueño, solo si es suyo (el cursor al salir, el foco al irse); sin dueño,
         * siempre: al desplazar, al mover el menú y con Esc.
         */
        hideWhy(owner = null) {
            if (owner !== null && this.whyOwner !== owner) {
                return;
            }

            const tip = whyTip();

            if (tip?.hasAttribute('data-on')) {
                tip.removeAttribute('data-on');
            }

            this.whyOwner = null;
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
         * cascada). La cascada va por columna y por renglón y ningún elemento espera más de
         * CASCADE_MAX_MS. Al cerrar, todo regresa junto con la cortina, y lo que no se veía (lo que
         * ocultó la búsqueda) aparece en su lugar a la vez.
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

                const { column, row } = gridPlaceOf(item);

                if (!from.shown) {
                    const delay = !open ? 0 : item.dataset.menuFlip === 'search'
                        ? 90
                        : Math.min(phone ? 120 + order * 12 : 280 + column * 24 + row * 8, CASCADE_MAX_MS);

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
                        ? { duration: 580, delay: Math.min(column * 30 + row * 10, CASCADE_MAX_MS), easing: spring, fill: 'backwards' }
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
                this.restoreColumn();
            }
        },

        /** Cerrado, la columna regresa a donde estaba antes de abrir el menú. */
        restoreColumn() {
            const column = document.querySelector('#nexus-menu [data-menu-column]');

            if (column) {
                column.scrollTop = this.columnScroll;
            }

            this.fadeColumn();
        },

        /**
         * La columna cerrada se desvanece arriba si hay más arriba, y abajo si hay más abajo. Abierta (o
         * en tableta, donde no se ve) no se desvanece.
         */
        fadeColumn(column = document.querySelector('#nexus-menu [data-menu-column]')) {
            if (!column) {
                return;
            }

            const scrolls = !this.menuOpen && column.clientHeight > 0;
            const above = scrolls && column.scrollTop > 4;
            const below = scrolls && column.scrollHeight - column.scrollTop - column.clientHeight > 4;
            const state = `${above ? 'top' : ''}${below ? 'bottom' : ''}`;

            // Al desplazar llega un evento tras otro: solo se escribe cuando cambia qué orilla se desvanece.
            if (column.dataset.fadeState === state) {
                return;
            }

            column.dataset.fadeState = state;
            column.style.setProperty('--fade-top', above ? `${FADE_TOP_PX}px` : '0px');
            column.style.setProperty('--fade-bottom', below ? `${FADE_BOTTOM_PX}px` : '0px');
            column.toggleAttribute('data-fade', above || below);
        },

        /** Al cargar la página, si la entrada activa quedó fuera de la columna cerrada, la centra. */
        revealActive() {
            const column = document.querySelector('#nexus-menu [data-menu-column]');
            const active = column?.querySelector('[data-active]');

            if (column && active && isRendered(active) && getComputedStyle(column).display !== 'contents') {
                const box = column.getBoundingClientRect();
                const entry = active.getBoundingClientRect();

                if (entry.bottom > box.bottom - FADE_BOTTOM_PX || entry.top < box.top + FADE_TOP_PX) {
                    column.scrollTop += entry.top - box.top - box.height / 2 + entry.height / 2;
                }
            }

            this.fadeColumn();
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
         * Deja solo las entradas cuyo nombre contiene lo buscado, sin importar mayúsculas ni acentos.
         * Oculta los encabezados de los grupos sin ninguna, las áreas sin ninguna (su icono y su nombre)
         * e Inicio, y vuelve a acomodar lo que queda en la retícula, sin huecos. Si no queda ninguna
         * entrada, muestra «Sin resultados».
         */
        filterMenu(query) {
            const menu = document.getElementById('nexus-menu');

            if (!menu) {
                return;
            }

            const needle = normalize(query.trim());
            const areas = [];

            menu.querySelectorAll('[data-menu-column] [data-menu-area]').forEach((area) => {
                const groups = [];
                let group = null;

                // Un encabezado abre su grupo; las entradas sin grupo van antes del primero.
                area.querySelectorAll('[data-menu-group], [data-menu-entry]').forEach((row) => {
                    if ('menuGroup' in row.dataset) {
                        group = { heading: row, entries: [] };
                        groups.push(group);

                        return;
                    }

                    if (group === null) {
                        group = { heading: null, entries: [] };
                        groups.push(group);
                    }

                    const match = needle === '' || searchableLabel(row).includes(needle);

                    row.hidden = !match;
                    notesOf(row).forEach((note) => { note.hidden = !match; });

                    if (match) {
                        group.entries.push(row);
                    }
                });

                groups.forEach(({ heading, entries }) => {
                    if (heading) {
                        heading.hidden = entries.length === 0;
                        notesOf(heading).forEach((note) => { note.hidden = entries.length === 0; });
                    }
                });

                const kept = groups.filter(({ entries }) => entries.length > 0);
                const slot = menu.querySelector(`[data-menu-rail] [data-menu-area="${area.dataset.menuArea}"]`);
                const icon = slot?.querySelector('[data-menu-flip="icon"]');

                area.hidden = kept.length === 0;
                slot?.toggleAttribute('hidden', kept.length === 0);

                if (kept.length > 0) {
                    areas.push({ area, icon, headings: 'headings' in area.dataset, groups: kept });
                }
            });

            // Inicio no es de ningún área, pero también se busca: «inicio» y Enter llevan a él.
            const home = menu.querySelector('[data-menu-home]');
            const homeMatches = needle === '' || normalize(home?.getAttribute('aria-label') ?? '').includes(needle);

            home?.toggleAttribute('hidden', !homeMatches);

            const empty = menu.querySelector('[data-menu-empty]');

            if (empty) {
                empty.hidden = areas.length > 0 || (home !== null && homeMatches);
            }

            this.placeMenu(areas);
        },

        /** Da a lo que dejó el buscador su lugar en la retícula de cinco columnas y en la de tres (packMenu). */
        placeMenu(areas) {
            const shape = areas.map(({ headings, groups }) => ({
                headings,
                groups: groups.map(({ heading, entries }) => ({ heading: heading !== null, entries: entries.length })),
            }));
            const rows = gridRows(document.getElementById('nexus-menu'));
            const wide = packMenu(shape, DESKTOP_COLUMNS, rows);
            const narrow = packMenu(shape, TABLET_COLUMNS, rows);

            areas.forEach(({ area, icon, groups }, index) => {
                const name = area.querySelector('[data-menu-flip="name"]');

                if (icon) {
                    placeInGrid(icon, wide[index].icon, narrow[index].icon);
                }

                if (name) {
                    placeInGrid(name, wide[index].name, narrow[index].name);
                }

                groups.forEach(({ heading, entries }, position) => {
                    if (heading) {
                        placeInGrid(heading, wide[index].groups[position].heading, narrow[index].groups[position].heading);
                    }

                    entries.forEach((entry, order) => placeInGrid(entry, wide[index].groups[position].entries[order], narrow[index].groups[position].entries[order]));
                });
            });
        },

        clearSearch() {
            const search = document.getElementById('nexus-menu-search');

            if (search) {
                search.value = '';
            }

            this.filterMenu('');
        },

        /** Enter en el buscador abre lo primero que quedó: Inicio, si coincide, o la primera entrada. */
        openFirstMatch() {
            const links = document.querySelectorAll('#nexus-menu [data-menu-home], #nexus-menu [data-menu-entry]');

            [...links].find((link) => !link.closest('[hidden]'))?.click();
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
| al tocar una cadena de la leyenda, todas sus materias. Tocar lo mismo otra vez, «Quitar resaltado» o Esc lo
| quita. Solo con clic, no al pasar el cursor (decidido por el usuario el 29 de octubre de 2026). La seriación
| viene en data-prereqs de cada materia; el resaltado se pinta con data-focus en la retícula y data-me /
| data-on-chain en las materias, que leen las utilidades de Tailwind. Lo resaltado se dice en la barra de
| selección (core::semester-grid.selection-bar), que solo se ve con data-shown.
*/
document.addEventListener('alpine:init', () => {
    window.Alpine.data('studentGrid', () => ({
        /** { code } de una materia, { chain } de una cadena, o null. */
        selected: null,
        tiles: [],
        byCode: {},
        pre: {},
        post: {},

        /** La seriación no cambia mientras la ficha está abierta: se arma una vez. */
        init() {
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
            let text = '';

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
            this.$refs.bar.toggleAttribute('data-shown', selection !== null);
        },
    }));
});

/*
| availabilityEditor: el editor de disponibilidad de un docente (DESIGN.md, "Cuadrícula día x hora"; variante A,
| «herramienta y pintar», elegida por el usuario el 30 de octubre de 2026). Vive en faculty::pages.teacher-availability,
| dentro de wire:ignore: Livewire pinta la cuadrícula una vez y aquí se lleva lo que cambia.
|
| Se elige una herramienta («No disponible», «Preferido» o «Borrar»; teclas N, P y B) y se pinta con clic o arrastre
| (pointer capture en la cuadrícula; en pantalla táctil también, porque las celdas llevan touch-action: none). Pintar
| sobre otro tipo lo reemplaza. El botón de un día pinta su columna y el de una hora, su renglón; Enter o Espacio
| pintan lo que tiene el foco y las flechas, Inicio y Fin lo mueven por las celdas y por los botones de los días (arriba)
| y de las horas (a la izquierda), con un solo botón de la cuadrícula en el orden de tabulación. Lo pintado queda
| pendiente: la barra de guardar dice cuántos bloques cambiaron, «Descartar» vuelve a lo guardado y «Guardar
| disponibilidad» manda los bloques marcados a save(). «Copiar de <periodo>» pide a copyPrevious() la del semestre
| anterior y la deja pendiente. Salir con bloques sin guardar pide confirmar (beforeunload y wire:navigate); Atrás y
| Adelante no se pueden detener. Si una llamada al servidor falla, un toast lo dice y lo pendiente se queda.
|
| Cuando Livewire restaura la página de su caché (Atrás o Adelante tras wire:navigate) o el navegador la saca de su
| bfcache, el x-data trae los tipos de cuando se abrió, no lo guardado después: el editor pide lo guardado a
| currentKinds() y vuelve a poner encima lo pintado sin guardar (de la caché de wire:navigate lo lee de las celdas,
| que salieron con data-changed), que sigue pendiente. Mientras trae lo guardado o mientras guarda, no deja pintar.
|
| Cada celda se actualiza a mano, como en studentGrid: su tipo en data-kind (que leen las utilidades del relleno y del
| icono), data-continues, data-changed (el punto de «sin guardar»), su aria-label y su texto. Las cifras, las horas de
| cada día y los rangos por día son estado de Alpine. config trae los días ({day, name}), los bloques ({starts_at,
| label, end_label}), blockMinutes, el tipo de cada celda (kinds[día][bloque], null es disponible) y el nombre de cada
| tipo (labels; '' es disponible).
*/
const AVAILABILITY_TOOL_KEYS = { n: 'unavailable', p: 'preferred', b: '' };
const AVAILABILITY_TOOLS = ['unavailable', 'preferred', ''];

/** Si la página que se va a iniciar viene de la caché de wire:navigate (Atrás o Adelante): lo dice livewire:navigate. */
let availabilityFromCache = false;
document.addEventListener('livewire:navigate', (event) => {
    availabilityFromCache = Boolean(event.detail?.history && event.detail?.cached);
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('availabilityEditor', (config) => {
        // Fuera del estado de Alpine: los elementos y las celdas no necesitan ser reactivos.
        const toCells = (kinds) => kinds.map((day) => day.map((kind) => kind ?? ''));
        let cells = toCells(config.kinds);
        let saved = cells.map((day) => [...day]);
        let elements = [];
        let painting = false;
        let last = null;
        // [día, bloque] del botón con el foco; -1 en el bloque es el botón del día y -1 en el día, el de la hora.
        let focus = [0, 0];

        const minutes = config.blockMinutes;
        const hours = (count) => {
            const value = (count * minutes) / 60;

            return Number.isInteger(value) ? value : Math.round(value * 10) / 10;
        };

        /** Los rangos unidos de un día: [{ kind, from, to }] con `to` exclusivo, como los guarda SetTeacherAvailability. */
        const runsOf = (day) => {
            const runs = [];

            day.forEach((kind, block) => {
                const last = runs[runs.length - 1];

                if (!kind) {
                    return;
                }

                if (last && last.kind === kind && last.to === block) {
                    last.to = block + 1;
                } else {
                    runs.push({ kind, from: block, to: block + 1 });
                }
            });

            return runs;
        };

        const span = (run) => `${config.blocks[run.from].label}–${config.blocks[run.to - 1].end_label}`;

        return {
            tool: 'unavailable',
            pending: 0,
            /** Ya se confirmó salir sin guardar: la navegación que sigue no vuelve a preguntar. */
            leaving: false,
            saving: false,
            copying: false,
            syncing: false,
            figures: { available: 0, preferred: 0, unavailable: 0 },
            dayAvailable: [],
            ranges: [],

            init() {
                elements = cells.map((day, d) => day.map((_, h) => this.$refs.grid.querySelector(`[data-d="${d}"][data-h="${h}"]`)));
                const restored = availabilityFromCache;
                availabilityFromCache = false;

                // La página de la caché de wire:navigate se guardó al salir con lo pintado sin guardar en cada celda
                // (data-kind con data-changed); el x-data, en cambio, trae lo de cuando se abrió. Se recupera de ahí.
                if (restored) {
                    elements.forEach((day, d) => day.forEach((element, h) => {
                        if (element.hasAttribute('data-changed')) {
                            cells[d][h] = element.dataset.kind ?? '';
                        }
                    }));
                }

                this.refresh();

                if (restored) {
                    this.sync();
                }
            },

            /**
             * Trae lo guardado en el servidor y vuelve a poner encima lo pintado sin guardar, que sigue pendiente: ni lo
             * guardado viejo de la página ni el trabajo sin guardar se pierden.
             */
            async sync() {
                this.syncing = true;
                const painted = cells.map((day, d) => day.map((kind, h) => (kind !== saved[d][h] ? kind : null)));

                try {
                    const kinds = await this.$wire.currentKinds();

                    if (Array.isArray(kinds)) {
                        saved = toCells(kinds);
                        cells = saved.map((day, d) => day.map((kind, h) => painted[d][h] ?? kind));
                        this.refresh();
                    }
                } catch {
                    this.failed('No se cargó la disponibilidad guardada');
                } finally {
                    this.syncing = false;
                }
            },

            /** Mientras se trae lo guardado o se guarda, no se pinta: la barra de pendientes no queda desfasada. */
            busy() {
                return this.syncing || this.saving;
            },

            /** Toast de error de MaryUI para una llamada al servidor que no respondió. */
            failed(title) {
                window.toast?.({
                    toast: {
                        type: 'error',
                        title,
                        description: 'No hubo respuesta del servidor. Revisa tu conexión y vuelve a intentarlo.',
                        icon: this.$refs.errorIcon?.innerHTML ?? '',
                        css: 'alert-error',
                        timeout: 5000,
                    },
                });
            },

            /** Al cerrar o recargar la pestaña con bloques sin guardar, el navegador pide confirmar. */
            warnBeforeUnload(event) {
                if (this.pending > 0) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            },

            /**
             * Al ir a otra pantalla con wire:navigate con bloques sin guardar, pide confirmar en el diálogo de Nexus
             * (ADR-0023); Atrás y Adelante, no. Detiene la navegación y, si se confirma, la repite sin preguntar.
             */
            confirmLeave(event) {
                const url = event.detail?.url ? String(event.detail.url) : '';

                if (this.pending === 0 || event.detail?.history || this.leaving || url === '') {
                    return;
                }

                event.preventDefault();

                const blocks = this.pending === 1 ? '1 bloque sin guardar' : `${this.pending} bloques sin guardar`;

                window.nexusConfirm({
                    title: '¿Salir sin guardar?',
                    message: `Tienes ${blocks}; se pierden al salir.`,
                    button: 'Salir sin guardar',
                    tone: 'warning',
                    run: () => {
                        // livewire:navigate se dispara dentro de navigate(): mientras, `leaving` deja pasar esta
                        // navegación; después vuelve a preguntar por si la navegación no se completó.
                        this.leaving = true;

                        try {
                            window.Livewire.navigate(url);
                        } finally {
                            this.leaving = false;
                        }
                    },
                });
            },

            /** Una sola cuenta tras cada cambio: las celdas, las cifras, las horas de cada día y los rangos. */
            refresh() {
                let pending = 0;
                let unavailable = 0;
                let preferred = 0;
                const dayAvailable = [];
                const ranges = [];

                cells.forEach((day, d) => {
                    const runs = runsOf(day);
                    let dayUnavailable = 0;

                    day.forEach((kind, h) => {
                        const element = elements[d][h];
                        const block = config.blocks[h];
                        const continues = kind !== '' && h > 0 && day[h - 1] === kind;
                        const changed = kind !== saved[d][h];
                        const run = runs.find((candidate) => h >= candidate.from && h < candidate.to);
                        const text = element.querySelector('[data-text]');

                        if (kind === '') {
                            element.removeAttribute('data-kind');
                        } else {
                            element.dataset.kind = kind;
                        }

                        element.toggleAttribute('data-continues', continues);
                        element.toggleAttribute('data-changed', changed);
                        element.setAttribute('aria-label', `${config.days[d].name} de ${block.label} a ${block.end_label}: ${config.labels[kind]}${changed ? ', sin guardar' : ''}`);
                        text.textContent = kind !== '' && !continues ? config.labels[kind] : (run && h === run.from + 1 ? span(run) : '');
                        text.toggleAttribute('data-mono', Boolean(run) && h === run.from + 1);

                        pending += changed ? 1 : 0;
                        dayUnavailable += kind === 'unavailable' ? 1 : 0;
                        preferred += kind === 'preferred' ? 1 : 0;
                    });

                    unavailable += dayUnavailable;
                    dayAvailable.push(day.length - dayUnavailable);
                    ranges.push(runs.map((run) => ({ key: `${run.kind}-${run.from}-${run.to}`, kind: run.kind, label: config.labels[run.kind], time: span(run) })));
                    this.$refs.grid.querySelector(`[data-day-group="${d}"]`)
                        ?.setAttribute('aria-label', `${config.days[d].name}: ${hours(day.length - dayUnavailable)} h disponibles`);
                });

                const total = cells.length * config.blocks.length;
                this.pending = pending;
                this.figures = { available: total - unavailable, preferred, unavailable };
                this.dayAvailable = dayAvailable;
                this.ranges = ranges;
            },

            /** Horas de unos bloques: enteras si caben, si no con un decimal. */
            hours(count) {
                return hours(count);
            },

            paint(d, h) {
                if (cells[d][h] === this.tool) {
                    return false;
                }

                cells[d][h] = this.tool;

                return true;
            },

            /** El botón de la cuadrícula en [día, bloque]: -1 en el bloque es el del día y -1 en el día, el de la hora. */
            buttonAt(d, h) {
                if (h === -1) {
                    return this.$refs.grid.querySelector(`[data-day="${d}"]`);
                }

                return d === -1 ? this.$refs.grid.querySelector(`[data-hour="${h}"]`) : elements[d][h];
            },

            moveFocus(d, h, giveFocus) {
                this.buttonAt(...focus).tabIndex = -1;
                focus = [d, h];
                this.buttonAt(d, h).tabIndex = 0;

                if (giveFocus) {
                    this.buttonAt(d, h).focus();
                }
            },

            down(event) {
                const cell = event.target.closest('[data-d]');

                if (!cell || event.button > 0 || this.busy()) {
                    return;
                }

                event.preventDefault();
                painting = true;
                last = [Number(cell.dataset.d), Number(cell.dataset.h)];
                this.$refs.grid.setPointerCapture(event.pointerId);
                this.moveFocus(...last, false);

                if (this.paint(...last)) {
                    this.refresh();
                }
            },

            /** Pinta también las celdas entre la anterior y esta, por si el puntero saltó alguna al moverse rápido. */
            move(event) {
                if (!painting) {
                    return;
                }

                const cell = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-d]');

                if (!cell || !this.$refs.grid.contains(cell)) {
                    return;
                }

                const [d, h] = [Number(cell.dataset.d), Number(cell.dataset.h)];
                const steps = Math.max(Math.abs(d - last[0]), Math.abs(h - last[1]));
                let changed = false;

                for (let step = 1; step <= steps; step++) {
                    changed = this.paint(Math.round(last[0] + ((d - last[0]) * step) / steps), Math.round(last[1] + ((h - last[1]) * step) / steps)) || changed;
                }

                last = [d, h];

                if (changed) {
                    this.refresh();
                }
            },

            stop() {
                painting = false;
                last = null;
            },

            /** El clic del ratón en una celda ya pintó al bajar; aquí solo Enter o Espacio (detail 0), el día y la hora. */
            click(event) {
                const cell = event.target.closest('[data-d]');

                if (this.busy()) {
                    return;
                }

                if (cell) {
                    if (event.detail === 0 && this.paint(Number(cell.dataset.d), Number(cell.dataset.h))) {
                        this.refresh();
                    }

                    return;
                }

                const day = event.target.closest('[data-day]');
                const hour = event.target.closest('[data-hour]');

                if (day) {
                    cells[Number(day.dataset.day)].forEach((_, h) => this.paint(Number(day.dataset.day), h));
                } else if (hour) {
                    cells.forEach((_, d) => this.paint(d, Number(hour.dataset.hour)));
                } else {
                    return;
                }

                this.refresh();
            },

            /** Las flechas recorren celdas, días y horas; la esquina (sin botón) se salta quedándose en su lugar. */
            key(event) {
                const button = event.target.closest('[data-d], [data-day], [data-hour]');

                if (!button) {
                    return;
                }

                let d = button.dataset.d !== undefined ? Number(button.dataset.d) : (button.dataset.day !== undefined ? Number(button.dataset.day) : -1);
                let h = button.dataset.h !== undefined ? Number(button.dataset.h) : (button.dataset.hour !== undefined ? Number(button.dataset.hour) : -1);
                const lastDay = cells.length - 1;
                const lastBlock = config.blocks.length - 1;

                switch (event.key) {
                    case 'ArrowRight': d = Math.min(lastDay, d + 1); break;
                    case 'ArrowLeft': d = h === -1 ? Math.max(0, d - 1) : Math.max(-1, d - 1); break;
                    case 'ArrowDown': h = Math.min(lastBlock, h + 1); break;
                    case 'ArrowUp': h = d === -1 ? Math.max(0, h - 1) : Math.max(-1, h - 1); break;
                    case 'Home': h = d === -1 ? 0 : (h === -1 ? -1 : 0); break;
                    case 'End': h = lastBlock; break;
                    default: return;
                }

                event.preventDefault();
                this.moveFocus(d, h, true);
            },

            /** N, P y B eligen la herramienta, salvo al escribir, con un modificador o con el menú o un diálogo abierto. */
            toolKey(event) {
                if (event.ctrlKey || event.metaKey || event.altKey || event.target.closest?.('input, select, textarea, [contenteditable]')
                    || document.body.dataset.menu === 'open' || document.querySelector('dialog[open]')) {
                    return;
                }

                const tool = AVAILABILITY_TOOL_KEYS[event.key.toLowerCase()];

                if (tool !== undefined) {
                    this.tool = tool;
                }
            },

            /** Las flechas recorren las herramientas, como un grupo de radios. */
            toolArrow(event) {
                const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];

                if (!step) {
                    return;
                }

                event.preventDefault();
                const group = event.currentTarget;
                const index = (AVAILABILITY_TOOLS.indexOf(this.tool) + step + AVAILABILITY_TOOLS.length) % AVAILABILITY_TOOLS.length;
                this.tool = AVAILABILITY_TOOLS[index];
                this.$nextTick(() => group.querySelector(`[data-tool="${this.tool}"]`)?.focus());
            },

            discard() {
                cells = saved.map((day) => [...day]);
                this.refresh();
            },

            async save() {
                if (this.saving || this.syncing) {
                    return;
                }

                this.saving = true;
                const sent = cells.map((day) => [...day]);
                const payload = [];

                sent.forEach((day, d) => day.forEach((kind, h) => {
                    if (kind !== '') {
                        payload.push({ day: config.days[d].day, starts_at: config.blocks[h].starts_at, kind });
                    }
                }));

                try {
                    const result = await this.$wire.save(payload);

                    if (result === true) {
                        saved = sent;
                        this.refresh();
                    } else if (result !== false) {
                        this.failed('No se guardó la disponibilidad');
                    }
                } catch {
                    this.failed('No se guardó la disponibilidad');
                } finally {
                    this.saving = false;
                }
            },

            async copyPrevious() {
                if (this.copying || this.busy()) {
                    return;
                }

                this.copying = true;

                try {
                    const kinds = await this.$wire.copyPrevious();

                    if (Array.isArray(kinds)) {
                        cells = toCells(kinds);
                        this.refresh();
                    } else if (kinds !== null) {
                        this.failed('No se copió la disponibilidad');
                    }
                } catch {
                    this.failed('No se copió la disponibilidad');
                } finally {
                    this.copying = false;
                }
            },
        };
    });
});

/*
| scheduleEditor: el editor de horarios (DESIGN.md, "Editor de horarios"; maqueta A «Semana con bandeja»). Livewire
| pinta la semana; aquí va lo que es del navegador, sin ir al servidor:
| - El día de la lista del celular (`day`, enlazado a `?dia=`): cambiarlo solo muestra otra lista.
| - Las flechas recorren las celdas y las sesiones de la semana por su lugar (data-x: el día y el carril; data-r0 y
|   data-r1: los renglones que ocupa), Inicio y Fin van a la primera y la última del día. La semana es una sola parada
|   de tabulación: la que tiene el foco se queda con tabindex 0 (rove). Enter o Espacio la tocan (son botones).
| - Esc quita el grupo elegido, salvo con un cajón, el menú o un diálogo abiertos.
| - «Ver» de un choque (found) lleva la vista a la sesión, le da el foco y la marca un momento (data-found); en celular
|   cambia antes al día de la sesión. Colocar (editor-placed) deja el foco en la sesión que quedó, porque la celda
|   tocada ya no existe.
| - Al cerrar el último cajón abierto, el foco regresa a lo que lo abrió (data-opener). Pasar de un cajón al otro
|   (data-drawer-switch) no lo regresa.
| - La bandeja también es una sola parada (trayRove, trayKey) y «Ir a la semana» (toWeek) salta a la semana sin
|   recorrer la bandeja ni los choques.
| - El motivo de una celda que choca o no se puede (data-why) se ve junto a ella con el foco o bajo el puntero (tip,
|   `position: fixed` para que la caja de la semana no lo recorte), se mueve con ella al desplazar y, cuando Livewire
|   vuelve a pintar, se acomoda o se esconde si la celda ya no está (MutationObserver).
| - Las flechas no se tocan con Alt, Ctrl o Cmd (Alt+← es «atrás»).
*/
document.addEventListener('alpine:init', () => {
    window.Alpine.data('scheduleEditor', (day) => ({
        day,
        opener: null,
        switching: false,
        tipFor: null,
        tip: { text: '', left: 0, top: 0, above: false },
        tipObserver: null,
        tipQueued: false,

        /**
         * Livewire puede cambiar o quitar la celda del aviso al volver a pintar (colocar, elegir otro grupo): después
         * de cada cambio del DOM el aviso se vuelve a poner junto a ella o se esconde si ya no está o no tiene motivo.
         */
        init() {
            this.tipObserver = new MutationObserver(() => {
                if (!this.tipFor || this.tipQueued) {
                    return;
                }

                this.tipQueued = true;
                requestAnimationFrame(() => {
                    this.tipQueued = false;
                    this.placeTip();
                });
            });
            this.tipObserver.observe(this.$el, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-why'] });
        },

        destroy() {
            this.tipObserver?.disconnect();
        },

        /** El motivo de la celda (data-why) con el foco o bajo el puntero; cualquier otro destino lo esconde. */
        explain(event) {
            this.tipFor = event.target?.closest?.('[data-why]') ?? null;
            this.placeTip();
        },

        /** Pone el aviso junto a su celda (abajo, o arriba si no cabe); se vuelve a poner al desplazar. */
        placeTip() {
            const cell = this.tipFor;

            if (!cell?.isConnected || !cell.dataset.why) {
                this.tipFor = null;
                this.tip.text = '';

                return;
            }

            const rect = cell.getBoundingClientRect();
            const above = rect.bottom + 112 > window.innerHeight;

            this.tip = {
                text: cell.dataset.why,
                left: Math.max(8, Math.min(rect.left, window.innerWidth - 328)),
                top: above ? rect.top - 4 : rect.bottom + 4,
                above,
            };
        },

        remember(event) {
            if (event.target.closest('[data-drawer-switch]')) {
                this.switching = true;
                setTimeout(() => (this.switching = false), 3000);

                return;
            }

            this.opener = event.target.closest('[data-opener]') ?? this.opener;
        },

        drawerOpen() {
            return document.querySelector('.drawer-toggle:checked') !== null;
        },

        drawerOpened() {
            this.switching = false;
        },

        drawerClosed() {
            this.$nextTick(() => {
                if (!this.switching && !this.drawerOpen() && this.opener?.isConnected) {
                    this.opener.focus();
                }
            });
        },

        escape() {
            if (this.$wire.groupId !== '' && this.$wire.view === 'semestre' && !this.drawerOpen()
                && document.body.dataset.menu !== 'open' && !document.querySelector('dialog[open]')) {
                this.$wire.clearSelection();
            }
        },

        /** La copia visible de una sesión (la de la semana o la de la lista del celular). */
        visible(id) {
            return [...document.querySelectorAll(`[data-session="${CSS.escape(id)}"]`)].find((element) => element.offsetParent !== null);
        },

        found(id) {
            const hidden = this.visible(id) ? null : document.querySelector(`[data-day-list] [data-session="${CSS.escape(id)}"]`);

            if (hidden) {
                this.day = hidden.dataset.day;
            }

            this.$nextTick(() => {
                const element = this.visible(id);

                if (!element) {
                    return;
                }

                element.scrollIntoView({ block: 'center', inline: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
                element.focus({ preventScroll: true });
                element.setAttribute('data-found', '');
                setTimeout(() => element.removeAttribute('data-found'), 2500);
            });
        },

        keep(id) {
            if (document.activeElement && document.activeElement !== document.body) {
                return;
            }

            this.visible(id)?.focus({ preventScroll: true });
        },

        /** «Ir a la semana»: el foco a la parada de la semana (o al día elegido de la lista en celular). */
        toWeek() {
            const week = [...document.querySelectorAll('[data-testid=week], [data-testid=day-list]')].find((element) => element.offsetParent !== null);
            const target = week?.querySelector('[data-nav][tabindex="0"], [data-day-button][aria-pressed=true]') ?? week;

            target?.focus({ preventScroll: true });
            target?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
        },

        trayRove(event) {
            const current = event.target.closest('[data-tray-item]');

            if (!current) {
                return;
            }

            for (const element of event.currentTarget.querySelectorAll('[data-tray-item]')) {
                element.tabIndex = element === current ? 0 : -1;
            }
        },

        /**
         * La bandeja es una sola parada. Desde 768 px las piezas van en columna: arriba y abajo cambian de grupo (en la
         * misma columna: la pieza o el botón de su cajón), la derecha va al botón del cajón y la izquierda regresa.
         * En celular cada materia es una tira de lado: izquierda y derecha cambian de grupo, abajo va al botón del
         * cajón y arriba regresa. Inicio y Fin van al primero y al último. Con Alt, Ctrl o Cmd no se toca la tecla
         * (Alt+← es «atrás» del navegador).
         */
        trayKey(event) {
            const current = event.target.closest('[data-tray-item]');

            if (event.altKey || event.ctrlKey || event.metaKey || !current
                || !['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) {
                return;
            }

            const strip = !matchMedia('(min-width: 48rem)').matches;
            const pieces = [...event.currentTarget.querySelectorAll('[data-piece]')];
            const piece = current.closest('[data-piece]');
            const index = pieces.indexOf(piece);
            const onDrawer = current.hasAttribute('data-group-drawer');
            const column = (element) => element?.querySelector(onDrawer ? '[data-group-drawer]' : '[data-group]');
            const moves = {
                next: () => column(pieces[index + 1]),
                previous: () => column(pieces[index - 1]),
                first: () => column(pieces[0]),
                last: () => column(pieces.at(-1)),
                drawer: () => (onDrawer ? null : piece.querySelector('[data-group-drawer]')),
                piece: () => (onDrawer ? piece.querySelector('[data-group]') : null),
            };
            const keys = strip
                ? { ArrowRight: 'next', ArrowLeft: 'previous', ArrowDown: 'drawer', ArrowUp: 'piece', Home: 'first', End: 'last' }
                : { ArrowDown: 'next', ArrowUp: 'previous', ArrowRight: 'drawer', ArrowLeft: 'piece', Home: 'first', End: 'last' };
            const next = moves[keys[event.key]]();

            event.preventDefault();
            next?.focus();
            next?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        },

        rove(event) {
            const current = event.target.closest('[data-nav]');

            if (!current) {
                return;
            }

            for (const element of event.currentTarget.querySelectorAll('[data-nav]')) {
                element.tabIndex = element === current ? 0 : -1;
            }
        },

        key(event) {
            const current = event.target.closest('[data-nav]');

            if (event.altKey || event.ctrlKey || event.metaKey || !current
                || !['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                return;
            }

            const items = [...event.currentTarget.querySelectorAll('[data-nav]')].map((element) => ({
                element,
                x: Number(element.dataset.x),
                r0: Number(element.dataset.r0),
                r1: Number(element.dataset.r1),
                day: Math.floor(Number(element.dataset.x)),
            }));
            const me = items.find((item) => item.element === current);
            const middle = (me.r0 + me.r1) / 2;
            const away = (item) => (item.r0 <= middle && middle < item.r1 ? 0 : Math.min(Math.abs(item.r0 - middle), Math.abs(item.r1 - middle)));
            const sameDay = items.filter((item) => item.day === me.day);
            const pick = {
                ArrowDown: () => sameDay.filter((item) => item.r0 >= me.r1 - 0.01).sort((a, b) => a.r0 - b.r0 || Math.abs(a.x - me.x) - Math.abs(b.x - me.x)),
                ArrowUp: () => sameDay.filter((item) => item.r1 <= me.r0 + 0.01).sort((a, b) => b.r1 - a.r1 || Math.abs(a.x - me.x) - Math.abs(b.x - me.x)),
                ArrowRight: () => items.filter((item) => item.x > me.x + 0.01).sort((a, b) => a.day - b.day || away(a) - away(b) || a.x - b.x),
                ArrowLeft: () => items.filter((item) => item.x < me.x - 0.01).sort((a, b) => b.day - a.day || away(a) - away(b) || b.x - a.x),
                Home: () => [...sameDay].sort((a, b) => a.r0 - b.r0),
                End: () => [...sameDay].sort((a, b) => b.r1 - a.r1),
            }[event.key]();

            event.preventDefault();
            pick[0]?.element.focus();
            pick[0]?.element.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        },
    }));
});

/*
| AZ-S5: si el perfil activo de la sesión ya no es con el que se pintó la página (cambió en otra pestaña, o cambiaron
| sus puestos: perdió el activo, se desactivó su campus o recibió el primero), el servidor responde 419 con
| X-Nexus-Role-Changed (Core\Livewire\ActiveRoleHook). Aquí se ofrece recargar en español; cualquier otro 419 (sesión
| o CSRF vencidos) sigue con el manejo de Livewire.
*/
document.addEventListener('livewire:init', () => {
    let asked = false;

    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status !== 419 || response.headers?.get('X-Nexus-Role-Changed') !== '1') {
                return;
            }

            preventDefault();

            if (asked) {
                return;
            }

            asked = true;

            window.nexusConfirm({
                title: 'Tu perfil cambió',
                message: 'Cambió en otra pestaña o por un cambio en tus puestos. Recarga la página para seguir con el perfil de ahora.',
                button: 'Recargar',
                tone: 'primary',
                run: () => window.location.reload(),
                onCancel: () => {
                    asked = false;
                },
            });
        });
    });
});

/*
| Confirmar sin diálogos del navegador (ADR-0023). `x-confirm="mensaje"` en un botón o enlace detiene su clic (en
| captura, antes que su wire:click o su enlace) y abre el diálogo de Core (`x-core::confirm-dialog`, uno por layout);
| al confirmar, vuelve a pulsar el mismo elemento y esta vez lo deja pasar. `data-confirm-title`, `data-confirm-button`
| y `data-confirm-tone` (error, warning o primary) lo ajustan. Desde JS se abre con `window.nexusConfirm({title,
| message, button, tone, run, onCancel})`.
*/
window.nexusConfirm = (detail) => window.dispatchEvent(new CustomEvent('nexus-confirm', { detail }));

document.addEventListener('alpine:init', () => {
    window.Alpine.directive('confirm', (el, _directive, { cleanup }) => {
        const intercept = (event) => {
            if (el.__nexusConfirmed) {
                el.__nexusConfirmed = false;

                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            const label = (el.dataset.confirmTitle || el.innerText || '').trim().split('\n')[0].trim();
            const popover = el.closest('[popover]');

            window.nexusConfirm({
                title: label || 'Confirmar',
                message: el.getAttribute('x-confirm') || '',
                button: el.dataset.confirmButton || label || 'Confirmar',
                tone: el.dataset.confirmTone || 'primary',
                // Dentro de un menú de renglón, el foco vuelve a su botón «Acciones», no al elemento del menú cerrado.
                origin: popover?.id ? document.querySelector(`[popovertarget="${popover.id}"]`) ?? el : el,
                run: () => {
                    // Si Livewire volvió a pintar la lista mientras el diálogo estaba abierto, el botón ya no existe.
                    if (!el.isConnected) {
                        window.toast?.({ toast: { type: 'warning', title: 'La lista cambió mientras confirmabas. Vuelve a intentarlo.', position: 'toast-top toast-end', timeout: 4000 } });

                        return;
                    }

                    el.__nexusConfirmed = true;
                    el.click();
                },
            });
        };

        el.addEventListener('click', intercept, { capture: true });
        cleanup(() => el.removeEventListener('click', intercept, { capture: true }));
    });

    // El elemento al que vuelve el foco va fuera del estado de Alpine: un nodo del DOM dentro de un Proxy reactivo no
    // se deja enfocar.
    let origin = null;

    window.Alpine.data('nexusConfirm', () => ({
        open: false,
        title: '',
        message: '',
        button: '',
        tone: 'primary',
        run: null,
        onCancel: null,

        get toneClass() {
            return { error: 'btn-error', warning: 'btn-warning', primary: 'btn-primary' }[this.tone] ?? 'btn-primary';
        },

        show(detail) {
            // Una confirmación nueva sobre otra abierta cancela la anterior (con su onCancel) en lugar de perderla.
            if (this.open) {
                const previous = this.onCancel;
                this.onCancel = null;
                previous?.();
            }

            Object.assign(this, {
                title: detail.title,
                message: detail.message,
                button: detail.button,
                tone: detail.tone ?? 'primary',
                run: detail.run ?? null,
                onCancel: detail.onCancel ?? null,
            });
            origin = detail.origin ?? null;

            this.open = true;

            const dialog = document.getElementById('nexus-confirm');

            if (dialog && !dialog.open) {
                dialog.showModal();
            }

            this.$nextTick(() => this.$refs.cancel?.focus());
        },

        close() {
            this.open = false;
            document.getElementById('nexus-confirm')?.close();
        },

        cancel() {
            if (!this.open) {
                return;
            }

            const back = origin;
            const onCancel = this.onCancel;
            origin = null;
            this.run = null;
            this.onCancel = null;
            this.close();
            back?.focus?.();
            onCancel?.();
        },

        accept() {
            const run = this.run;
            origin = null;
            this.run = null;
            this.onCancel = null;
            this.close();
            run?.();
        },
    }));
});
