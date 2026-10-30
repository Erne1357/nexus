// Revisión visual de pantallas: entra con un usuario, abre cada ruta a varios anchos y temas, guarda una
// captura y reporta el estado HTTP, el tiempo de carga y el desborde horizontal (DESIGN.md, "Responsivo":
// ninguna pantalla desborda). Sale con código 1 si alguna desborda, responde con error o lanza un error de JS.
//
// Corre dentro del contenedor `app` (trae Playwright y Chromium), contra un servidor de la app en
// 127.0.0.1:8099 (ver docs/arquitectura/testing.md, "Revisión visual con volumen"):
//
//   docker compose exec -T -e LOGIN=admin -w /app app node .github/scripts/review-screens.mjs \
//     materias=/academic/subjects reticula=/academic/plans/<id>
//
// Variables: BASE (http://127.0.0.1:8099), LOGIN (admin), PASSWORD (password), WIDTHS (390,768,1440),
// THEMES (light,dark), OUT (/app/storage/app/review, ignorado por git), FULL=1 para capturar la página entera.
import { chromium } from 'playwright';

const base = process.env.BASE || 'http://127.0.0.1:8099';
const login = process.env.LOGIN || 'admin';
const password = process.env.PASSWORD || 'password';
const widths = (process.env.WIDTHS || '390,768,1440').split(',').map(Number);
const themes = (process.env.THEMES || 'light,dark').split(',');
const out = process.env.OUT || '/app/storage/app/review';
const pages = process.argv.slice(2).map((arg) => {
  const [name, path] = arg.includes('=') ? [arg.slice(0, arg.indexOf('=')), arg.slice(arg.indexOf('=') + 1)] : [arg.replace(/\W+/g, '-').replace(/^-|-$/g, ''), arg];
  return { name, path };
});

if (pages.length === 0) {
  console.error('Uso: review-screens.mjs nombre=/ruta [nombre=/ruta ...]');
  process.exit(2);
}

// La página pide los assets de Vite a localhost:5173; dentro de `app` ese servidor es el contenedor `vite`.
const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP localhost vite'] });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(String(error)));

await page.goto(`${base}/login`);
await page.fill('input[name="username"]', login);
await page.fill('input[name="password"]', password);
await Promise.all([page.waitForURL(`${base}/`), page.click('button[type="submit"]')]);

let failed = false;

for (const theme of themes) {
  for (const { name, path } of pages) {
    for (const width of widths) {
      await page.setViewportSize({ width, height: 900 });
      const started = Date.now();
      const response = await page.goto(base + path, { waitUntil: 'networkidle' });
      const ms = Date.now() - started;

      if (theme === 'dark') {
        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'nexus-dark'));
      }

      await page.waitForTimeout(300);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      const status = response?.status() ?? 0;
      await page.screenshot({ path: `${out}/${name}-${login}-${theme}-${width}.png`, fullPage: process.env.FULL === '1' });

      failed ||= overflow > 0 || status >= 400;
      console.log(`${name}\t${theme}\t${width}\tstatus=${status}\tload=${ms}ms\toverflow=${overflow}`);
    }
  }
}

await browser.close();

if (errors.length > 0) {
  console.log(`Errores de JS:\n${errors.join('\n')}`);
}

process.exit(failed || errors.length > 0 ? 1 : 0);
