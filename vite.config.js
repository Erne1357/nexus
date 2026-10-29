import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Imágenes que Blade usa con Vite::asset() sin importarlas en JS (la liebre).
            assets: ['resources/images/**'],
            // Se declaran en la página con la directiva @fonts de los layouts (DESIGN.md, "Typography").
            fonts: [
                bunny('Atkinson Hyperlegible Next', {
                    weights: [200, 400, 500, 700],
                    // Se precargan los pesos de toda pantalla; 200 es solo para los inicios por rol.
                    preload: [{ weight: 400 }, { weight: 500 }, { weight: 700 }],
                    optimizedFallbacks: false,
                }),
                bunny('Atkinson Hyperlegible Mono', {
                    weights: [400, 600],
                    preload: false,
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // Accesible desde fuera del contenedor; el navegador conecta por localhost.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
