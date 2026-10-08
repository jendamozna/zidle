import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// In development, API requests are proxied to the PHP built-in server:
//   php -S 127.0.0.1:8000 -t .
const PHP_SERVER = process.env.PHP_SERVER ?? 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react()],
  base: './',
  server: {
    proxy: { '/api': PHP_SERVER },
  },
  preview: {
    proxy: { '/api': PHP_SERVER },
  },
});
