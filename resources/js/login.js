/*
| nexusLogin: la pantalla de acceso con credencial (tarea 1.23 sobre el login G de la 1.17; DESIGN.md, "Pantalla de
| acceso"). Lo carga solo el login (@vite en core::auth.login) y Alpine, que viene con Livewire, lo registra al arrancar.
|
| - Bienvenida: la primera vez en ese equipo la foto se abre desde la orilla, las placas de los logos se clavan una tras
|   otra, la credencial cae, la cinta la detiene y se balancea hasta quedar quieta, y la marca se escribe (~2.1 s);
|   después, la credencial solo se asienta y la marca se escribe en medio segundo. Cualquier tecla o toque termina la
|   bienvenida. La marca de «ya vino» es nexus.login.visto en localStorage.
| - Avisos (LG-01): cambian cada 7 s; el punto del aviso actual se llena mientras dura. Se detienen al pasar el mouse o
|   con el foco dentro.
| - Atajos (LG-02): el botón de la foto voltea la credencial y en su reverso caen los atajos uno tras otro; Esc o
|   «Iniciar sesión» la regresan.
| - Entrar: el formulario se manda pidiendo JSON (LoginResponse). Si no coincide, el aviso sale arriba del formulario y
|   la credencial se mece. Si entra, la ficha se llena, la cabecera se vuelve la banda, la liebre salta a su esquina, la
|   ficha sube a la credencial de arriba a la derecha y la página cambia; nexus.entrada en sessionStorage le dice a la
|   siguiente (nexusShell, app.js) que su contenido llegue. Cualquier otra respuesta (419, error, sin red) manda el
|   formulario como siempre.
| Con prefers-reduced-motion nada se anima ni avanza solo.
*/

const SEEN_KEY = 'nexus.login.visto';
const ARRIVAL_KEY = 'nexus.entrada';
const NOTICE_MS = 7000;
const OPEN = 'cubic-bezier(.3,1.06,.5,1)';
const SETTLE = 'cubic-bezier(.22,1,.36,1)';
const PEN = 'cubic-bezier(.45,.05,.35,1)';
const MOVE = 'cubic-bezier(.6,0,.2,1)';
const DROP = 'cubic-bezier(.25,.8,.35,1)';
const SKEW = 'skewX(-14deg)';

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const desktop = () => window.matchMedia('(min-width: 1024px)').matches;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const settled = (animations) => Promise.all(animations.map((animation) => animation.finished.catch(() => {})));

const storage = {
    get(key, area = 'localStorage') {
        try {
            return window[area].getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value, area = 'localStorage') {
        try {
            window[area].setItem(key, value);
        } catch {
            // Sin almacenamiento (modo privado): la bienvenida completa se repite, nada más.
        }
    },
};

/** Rectángulo de un elemento en la ventana. */
const box = (element) => {
    const rect = element.getBoundingClientRect();
    return { x: rect.left, y: rect.top, w: rect.width, h: rect.height };
};

/** clip-path que deja ver solo `rect` de una capa que cubre la ventana (W × H). */
const clipTo = (rect, W, H, radius = '0px') => `inset(${rect.y}px ${W - rect.x - rect.w}px ${H - rect.y - rect.h}px ${rect.x}px round ${radius})`;

/** Lo que se mueve el centro de `from` para caer en el centro de `to`. */
const travel = (from, to) => ({ dx: to.x + to.w / 2 - (from.x + from.w / 2), dy: to.y + to.h / 2 - (from.y + from.h / 2) });

/** Una copia sin Alpine: las piezas que vuelan van fuera del componente y Alpine no debe leerlas. */
const plain = (element) => {
    [element, ...element.querySelectorAll('*')].forEach((node) =>
        [...node.attributes].forEach(({ name }) => {
            if (/^(x-|:|@)/.test(name)) {
                node.removeAttribute(name);
            }
        }),
    );
    return element;
};

/** El tema de la aplicación a la que se llega (el de la credencial de la banda, o el del sistema). */
const appTheme = () => (storage.get('mary-theme') ?? '').replaceAll('"', '') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'nexus-dark' : 'nexus');

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nexusLogin', ({ total }) => ({
        current: 0,
        held: false,
        progress: null,
        running: [],
        flipped: false,
        turning: false,
        busy: false,
        leaving: false,
        error: '',
        revealed: false,
        credential: { name: '', username: '', initials: '', role: '', term: '', summary: '' },

        init() {
            this.welcome(!storage.get(SEEN_KEY));
            storage.set(SEEN_KEY, '1');
            this.$nextTick(() => this.runProgress());
            ['keydown', 'pointerdown'].forEach((type) => window.addEventListener(type, () => this.finishWelcome(), { capture: true, once: true }));
            window.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && this.flipped) {
                    this.closeShortcuts();
                }
            });
            // Si el navegador regresa con «atrás» a la página que ya se volvió banda, se carga de nuevo.
            window.addEventListener('pageshow', (event) => {
                if (event.persisted && this.leaving) {
                    window.location.reload();
                }
            });
        },

        /* ---------- Bienvenida ---------- */

        animate(element, keyframes, options) {
            if (!element) {
                return null;
            }
            const animation = element.animate(keyframes, { fill: 'backwards', ...options });
            this.running.push(animation);
            return animation;
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

        /** La credencial gira y se mece desde arriba de la pantalla, donde está atada su cinta. */
        pivot() {
            const badge = this.$refs.badge;
            const rect = badge.getBoundingClientRect();
            badge.style.transformOrigin = `50% ${-(rect.top + window.scrollY)}px`;
            return rect;
        },

        writeMark(delay, duration) {
            const sweep = this.$refs.mark?.querySelector('[data-barrido]');
            const distance = (this.$refs.mark?.viewBox.baseVal.width ?? 3000) + 1000;
            this.animate(sweep, [{ transform: `translateX(${-distance}px) ${SKEW}` }, { transform: `translateX(0) ${SKEW}` }], { duration, delay, easing: PEN });
        },

        /** Cada placa baja un poco girada y se clava en su sitio. */
        pinPlates(delay) {
            this.$root.querySelectorAll('[data-login-plate]').forEach((plate, index) => {
                const tilt = Number(plate.dataset.tilt) || 0;
                this.animate(
                    plate,
                    [
                        { opacity: 0, translate: '0 -18px', scale: '1.04', rotate: `${tilt + 2.4}deg` },
                        { opacity: 1, translate: '0 -5px', scale: '1.012', rotate: `${tilt + 0.7}deg`, offset: 0.4 },
                        { opacity: 1, translate: '0 0', scale: '1', rotate: `${tilt}deg` },
                    ],
                    { duration: 520, delay: delay + index * 130, easing: SETTLE },
                );
            });
        },

        welcome(firstTime) {
            if (reducedMotion()) {
                return;
            }
            const rect = this.pivot();
            if (!firstTime) {
                this.animate(this.$refs.badge, [{ transform: 'translateY(-16px)', opacity: 0 }, { transform: 'none', opacity: 1, offset: 0.5 }, { transform: 'none', opacity: 1 }], { duration: 560, easing: OPEN });
                this.writeMark(80, 460);
                return;
            }
            if (desktop()) {
                this.animate(this.$root.querySelector('[data-login-photos]'), [{ clipPath: 'inset(0 0 0 100%)' }, { clipPath: 'inset(0 0 0 0)' }], { duration: 820, easing: SETTLE });
            }
            this.pinPlates(120);

            // Cae desde arriba, la cinta la detiene y se balancea cada vez menos hasta quedar quieta.
            const fall = rect.top + rect.height + 80;
            const swing = desktop() ? 3.4 : 1.8;
            const catchAt = 0.4;
            const seconds = 1.9;
            const steps = 120;
            const frames = [];
            for (let step = 0; step <= steps; step++) {
                const t = (step / steps) * seconds;
                let y = 0;
                let angle = 0;
                if (step < steps && t < catchAt) {
                    const k = t / catchAt;
                    y = -fall * (1 - k * k);
                    angle = swing;
                } else if (step < steps) {
                    const s = t - catchAt;
                    y = 12 * Math.exp(-9 * s) * Math.sin((2 * Math.PI * s) / 0.34);
                    angle = swing * Math.exp(-2.8 * s) * Math.cos((2 * Math.PI * s) / 0.62);
                }
                frames.push({ transform: `translateY(${y.toFixed(2)}px) rotate(${angle.toFixed(3)}deg)`, offset: step / steps });
            }
            this.animate(this.$refs.badge, frames, { duration: seconds * 1000, delay: 180, easing: 'linear' });
            this.writeMark(720, 760);
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

        /* ---------- Atajos: el reverso de la credencial ---------- */

        drop(root, delay) {
            if (reducedMotion()) {
                return;
            }
            root.querySelectorAll('[data-drop]').forEach((element, index) => {
                element.animate(
                    [
                        { opacity: 0, transform: 'translateY(-16px)' },
                        { opacity: 1, transform: 'translateY(4px)', offset: 0.62 },
                        { transform: 'translateY(-1.5px)', offset: 0.84 },
                        { opacity: 1, transform: 'none' },
                    ],
                    { duration: 520, delay: delay + index * 75, easing: DROP, fill: 'backwards' },
                );
            });
        },

        /* El giro empieza antes de cambiar el estado: así el reverso nunca se ve un cuadro antes de tiempo. */
        async openShortcuts() {
            if (this.flipped || this.turning || this.busy) {
                return;
            }
            this.finishWelcome();
            this.hold(true);
            let turn = null;
            if (!reducedMotion()) {
                this.turning = true;
                turn = this.$refs.turn.animate([{ transform: 'rotateY(0)' }, { transform: 'rotateY(96deg) scale(.97)', offset: 0.5 }, { transform: 'rotateY(180deg)' }], { duration: 640, easing: SETTLE });
                this.drop(this.$refs.back, 300);
            }
            this.flipped = true;
            if (!desktop()) {
                this.$refs.badge.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
            }
            await this.$nextTick();
            this.$refs.back.querySelector('h2').focus({ preventScroll: true });
            if (turn) {
                await turn.finished.catch(() => {});
                this.turning = false;
            }
        },

        async closeShortcuts() {
            if (!this.flipped || this.turning) {
                return;
            }
            let turn = null;
            if (!reducedMotion()) {
                this.turning = true;
                turn = this.$refs.turn.animate([{ transform: 'rotateY(180deg)' }, { transform: 'rotateY(84deg) scale(.97)', offset: 0.5 }, { transform: 'rotateY(0)' }], { duration: 560, easing: SETTLE });
            }
            this.flipped = false;
            await this.$nextTick();
            this.$refs.shortcutsButton?.focus({ preventScroll: true });
            this.hold(false);
            if (turn) {
                await turn.finished.catch(() => {});
                this.turning = false;
            }
        },

        /* ---------- Entrar ---------- */

        fields() {
            return [...this.$root.querySelectorAll('form [name="username"], form [name="password"]')];
        },

        /** El aviso del servidor queda arriba del formulario y los dos campos lo citan. */
        markInvalid(invalid) {
            this.fields().forEach((field) => {
                if (invalid) {
                    field.setAttribute('aria-invalid', 'true');
                    field.setAttribute('aria-describedby', 'login-error');
                } else if (field.getAttribute('aria-describedby') === 'login-error') {
                    field.removeAttribute('aria-invalid');
                    field.removeAttribute('aria-describedby');
                }
            });
        },

        /** «No»: la credencial se mece apenas en su cinta. */
        sway() {
            if (reducedMotion()) {
                return;
            }
            this.pivot();
            this.$refs.badge.animate([0, -1.3, 1, -0.55, 0.22, 0].map((angle, index, all) => ({ transform: `rotate(${angle}deg)`, offset: index / (all.length - 1) })), { duration: 620, easing: 'ease-out' });
        },

        async submit(event) {
            const form = event.target;
            if (this.busy) {
                return;
            }
            this.finishWelcome();
            this.busy = true;
            this.error = '';
            this.markInvalid(false);

            let response = null;
            try {
                response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
            } catch {
                response = null;
            }

            const data = response ? await response.json().catch(() => null) : null;

            if (response?.ok && data?.redirect) {
                await this.enter(data);
                return;
            }

            if (response && (response.status === 422 || response.status === 429) && data) {
                this.busy = false;
                this.error = data.errors?.username?.[0] ?? data.errors?.password?.[0] ?? data.message ?? '';
                this.markInvalid(true);
                this.sway();
                const password = form.querySelector('[name="password"]');
                password?.focus();
                password?.select();
                return;
            }

            // Sesión vencida (419), error o sin red: el formulario se manda como siempre y el servidor responde.
            form.submit();
        },

        async enter({ redirect, band, credential }) {
            this.leaving = true;
            this.credential = credential;
            await this.$nextTick();
            await this.reveal();
            if (band && !reducedMotion()) {
                await this.arrive();
                storage.set(ARRIVAL_KEY, '1', 'sessionStorage');
            }
            window.location.assign(redirect);
        },

        /** Entrar 1: los datos aparecen en la ficha como recién impresos y los renglones vacíos se van. */
        async reveal() {
            const card = this.$refs.card;
            if (reducedMotion()) {
                this.revealed = true;
                return;
            }
            const animations = [...card.querySelectorAll('[data-value]')].map((element, index) =>
                element.animate([{ opacity: 0, transform: 'translateY(3px)' }, { opacity: 1, transform: 'none' }], { duration: 460, delay: index * 40, easing: SETTLE, fill: 'both' }),
            );
            card.querySelectorAll('[data-ghost]').forEach((element) => animations.push(element.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 280, easing: SETTLE, fill: 'both' })));
            await settled(animations);
            this.revealed = true;
            await this.$nextTick();
            animations.forEach((animation) => animation.cancel());
            await wait(260);
        },

        /**
         * Entrar 2: el azul y la franja de la cabecera se extienden hasta ser la banda, la marca vuela a «Nexus», la liebre
         * salta a su esquina y la foto, el nombre y el puesto suben a la credencial. Las piezas vuelan en una capa encima;
         * al final queda la banda (la de la plantilla x-ref="band", con la forma de core::components.band) sobre el fondo de
         * la aplicación, que es lo primero que pinta la página siguiente.
         */
        async arrive() {
            const W = document.documentElement.clientWidth;
            const H = window.innerHeight;
            const from = {
                header: box(this.$refs.header),
                stripe: box(this.$refs.stripe),
                hare: box(this.$refs.hare),
                photo: box(this.$refs.photo),
                mark: box(this.$refs.mark),
                name: box(this.$refs.name),
                role: box(this.$refs.role),
            };

            // La capa no lleva tema: lo que sale de la credencial es del login (claro); el fondo, el azul, la franja y la banda
            // a los que llega llevan el de la aplicación (daisyUI pinta el fondo de todo lo que tiene data-theme).
            const theme = appTheme();
            const layer = document.createElement('div');
            layer.setAttribute('aria-hidden', 'true');
            layer.setAttribute('x-ignore', '');
            layer.style.cssText = 'position:fixed;inset:0;z-index:100;pointer-events:none';
            layer.style.setProperty('--hare', getComputedStyle(this.$root).getPropertyValue('--hare'));
            const piece = (element, rect, extra = '') => {
                element.style.cssText += `;position:fixed;left:${rect.x}px;top:${rect.y}px;width:${rect.w}px;height:${rect.h}px;margin:0;${extra}`;
                layer.append(element);
                return element;
            };
            const block = (classes, rect) => {
                const element = document.createElement('div');
                element.className = classes;
                element.dataset.theme = theme;
                return piece(element, rect);
            };

            // La banda de llegada: invisible mientras se mide adónde va cada pieza.
            const band = this.$refs.band.content.firstElementChild.cloneNode(true);
            band.querySelector('[data-arrival="avatar"]').textContent = this.credential.initials;
            band.querySelector('[data-arrival="name"]').textContent = this.credential.name;
            const summary = band.querySelector('[data-arrival="summary"]');
            summary.textContent = this.credential.summary;
            summary.hidden = !this.credential.summary;
            band.style.visibility = 'hidden';
            band.dataset.theme = theme;

            const full = { x: 0, y: 0, w: W, h: H };
            const backdrop = block('bg-base-200', full);
            const navy = block('bg-band', full);
            const red = block('bg-accent', full);
            document.body.append(layer);
            layer.append(band);
            const target = (name) => band.querySelector(`[data-arrival="${name}"]`);
            const at = (name) => box(target(name));
            const to = { hare: at('hare'), wordmark: at('wordmark'), avatar: at('avatar') };
            const bandHeight = to.hare.h;
            const moves = [];

            moves.push(backdrop.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 320, delay: 220, easing: SETTLE, fill: 'both' }));
            moves.push(navy.animate([{ clipPath: clipTo(from.header, W, H, '12px 12px 0 0') }, { clipPath: clipTo({ x: 0, y: 0, w: W, h: bandHeight }, W, H) }], { duration: 640, easing: MOVE, fill: 'both' }));
            moves.push(red.animate([{ clipPath: clipTo(from.stripe, W, H) }, { clipPath: clipTo({ x: 0, y: bandHeight, w: W, h: 3 }, W, H) }], { duration: 640, easing: MOVE, fill: 'both' }));

            // La marca vuela al lugar de «Nexus» y se funde.
            const mark = plain(this.$refs.mark.cloneNode(true));
            mark.querySelector('defs')?.remove();
            mark.querySelector('g')?.removeAttribute('mask');
            piece(mark, from.mark, 'transform-origin:0 50%');
            const markScale = (to.wordmark.h / from.mark.h) * 1.25;
            moves.push(
                mark.animate(
                    [
                        { transform: 'none', opacity: 1 },
                        { transform: `translate(${to.wordmark.x - from.mark.x}px, ${to.wordmark.y + to.wordmark.h / 2 - (from.mark.y + from.mark.h / 2)}px) scale(${markScale})`, opacity: 0 },
                    ],
                    { duration: 560, delay: 20, easing: MOVE, fill: 'both' },
                ),
            );

            // «Nexus» de la banda aparece donde la marca se funde.
            const wordmark = piece(plain(target('wordmark').cloneNode(true)), to.wordmark, `line-height:${to.wordmark.h}px`);
            wordmark.classList.add('text-band-content');
            moves.push(wordmark.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 300, delay: 320, easing: SETTLE, fill: 'both' }));

            // La liebre se acomoda: sube primero, luego se recorre a su esquina y se asienta con un brinco de 4 px.
            const hare = piece(plain(this.$refs.hare.cloneNode(true)), from.hare, 'transform-origin:50% 50%');
            const hop = travel(from.hare, to.hare);
            const scaleX = to.hare.w / from.hare.w;
            const scaleY = to.hare.h / from.hare.h;
            moves.push(
                hare.animate(
                    [
                        { transform: 'none', easing: 'cubic-bezier(.45,0,.55,1)' },
                        { transform: `translate(${hop.dx * 0.3}px, ${hop.dy * 0.72}px) scale(${(1 + scaleX) / 2}, ${(1 + scaleY) / 2})`, offset: 0.46, easing: 'cubic-bezier(.3,0,.25,1)' },
                        { transform: `translate(${hop.dx}px, ${hop.dy}px) scale(${scaleX}, ${scaleY})`, offset: 0.8, easing: 'ease-out' },
                        { transform: `translate(${hop.dx}px, ${hop.dy - 4}px) scale(${scaleX}, ${scaleY})`, offset: 0.9, easing: 'ease-in' },
                        { transform: `translate(${hop.dx}px, ${hop.dy}px) scale(${scaleX}, ${scaleY})` },
                    ],
                    { duration: 820, delay: 40, fill: 'both' },
                ),
            );
            moves.push(hare.firstElementChild.animate([{ transform: 'none' }, { transform: 'rotate(-12deg)', offset: 0.4 }, { transform: 'rotate(3deg)', offset: 0.78 }, { transform: 'none' }], { duration: 820, delay: 40, easing: 'ease-in-out', fill: 'both' }));

            // La foto se vuelve el avatar.
            const photo = piece(plain(this.$refs.photo.cloneNode(true)), from.photo, 'transform-origin:50% 50%');
            const lift = travel(from.photo, to.avatar);
            const cut = (from.photo.h - from.photo.w) / 2;
            moves.push(
                photo.animate(
                    [
                        { transform: 'none', clipPath: 'inset(0 round 8px)' },
                        { transform: `translate(${lift.dx}px, ${lift.dy}px) scale(${to.avatar.w / from.photo.w})`, clipPath: `inset(${cut}px 0 round 50%)` },
                    ],
                    { duration: 720, easing: MOVE, fill: 'both' },
                ),
            );

            // El nombre y el puesto suben a su texto en la credencial de la banda y cambian de tinta; en celular, al avatar.
            const words = (source, rect, goal, outText, outClass) => {
                const holder = document.createElement('div');
                const inside = document.createElement('span');
                const outside = document.createElement('span');
                inside.textContent = source.textContent;
                outside.textContent = outText;
                inside.className = 'absolute inset-0 text-primary';
                outside.className = `absolute inset-0 opacity-0 ${outClass}`;
                holder.append(inside, outside);
                piece(holder, { ...rect, w: rect.w + 40 }, `transform-origin:0 0;white-space:nowrap;font:${getComputedStyle(source).font}`);
                const end = box(goal);
                const visible = end.w > 2;
                const scale = visible ? parseFloat(getComputedStyle(goal).fontSize) / parseFloat(getComputedStyle(source).fontSize) : 0.4;
                const x = visible ? end.x : to.avatar.x;
                const y = visible ? end.y + end.h / 2 - (rect.h * scale) / 2 : to.avatar.y;
                moves.push(holder.animate([{ transform: 'none', opacity: 1 }, { transform: `translate(${x - rect.x}px, ${y - rect.y}px) scale(${scale})`, opacity: visible ? 1 : 0 }], { duration: 700, delay: 30, easing: MOVE, fill: 'both' }));
                moves.push(inside.animate([{ opacity: 1 }, { opacity: 1, offset: 0.25 }, { opacity: 0, offset: 0.6 }, { opacity: 0 }], { duration: 700, delay: 30, fill: 'both' }));
                moves.push(outside.animate([{ opacity: 0 }, { opacity: 0, offset: 0.25 }, { opacity: 1, offset: 0.6 }, { opacity: 1 }], { duration: 700, delay: 30, fill: 'both' }));
            };
            words(this.$refs.name, from.name, target('name'), this.credential.name, 'text-band-content');
            words(this.$refs.role, from.role, target('summary'), this.credential.summary, 'text-band-muted');

            // Lo demás del login se va: la cinta sube; la credencial, las placas y la foto se desvanecen.
            const rise = -(this.$refs.badge.getBoundingClientRect().top + 60);
            [this.$refs.strap, this.$refs.clip].forEach((element) => element.animate([{ transform: 'none' }, { transform: `translateY(${rise}px)` }], { duration: 420, easing: SETTLE, fill: 'forwards' }));
            this.$refs.turn.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 200, easing: SETTLE, fill: 'forwards' });
            this.$root.querySelector('[data-login-logos]').animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, fill: 'forwards' });
            this.$root.querySelector('[data-login-photos]').animate([{ opacity: 1 }, { opacity: 0 }], { duration: 420, delay: 100, easing: SETTLE, fill: 'forwards' });

            await settled(moves);

            // Queda la banda en su lugar; las piezas se funden en ella.
            band.style.visibility = '';
            const pieces = [...layer.children].filter((element) => element !== band && element !== backdrop && element !== navy && element !== red);
            await settled(pieces.map((element) => element.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 140, easing: SETTLE, fill: 'forwards' })));
        },
    }));
});
