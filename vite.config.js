import { existsSync, readFileSync } from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ command }) => {
    const certificate = '.ddev/traefik/certs/homereel.crt';
    const key = '.ddev/traefik/certs/homereel.key';
    const ddevHttps = command === 'serve' && existsSync(certificate) && existsSync(key);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/css/upload.css', 'resources/js/app.js', 'resources/js/components/resumable-upload.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            ...(ddevHttps ? {
                https: { cert: readFileSync(certificate), key: readFileSync(key) },
                origin: 'https://homereel.ddev.site:5173',
                cors: { origin: 'https://homereel.ddev.site' },
                hmr: { host: 'homereel.ddev.site', protocol: 'wss' },
            } : {}),
            watch: { ignored: ['**/storage/framework/views/**'] },
        },
    };
});
