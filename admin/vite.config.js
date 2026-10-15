import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// В контейнере указывает на сервис webserver, локально — на localhost:8080
const apiTarget = process.env.ADMIN_API_TARGET ?? 'http://localhost:8080';

export default defineConfig({
  plugins: [vue()],
  base: '/admin/',
  build: {
    outDir: '../php-producer/public/admin',
    emptyOutDir: true,
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    // bind-mount с Windows не отдаёт события файловой системы
    watch: { usePolling: true, interval: 300 },
    proxy: {
      '/admin/api': apiTarget,
      '/admin/login': apiTarget,
      '/admin/logout': apiTarget,
    },
  },
});