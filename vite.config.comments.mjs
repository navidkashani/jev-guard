import { defineConfig } from 'vite';
import { resolve } from 'node:path';
import { COMMENTS, MINIFY, WORDPRESS_GLOBALS } from './vite.config.mjs';

/** The comments-screen script (resources/entries/comments.ts) as one IIFE file: public/js/comments.js. */
export default defineConfig({
  publicDir: false,
  build: {
    outDir: resolve(import.meta.dirname, 'public/js'),
    emptyOutDir: true,
    modulePreload: false,
    minify: false,
    rollupOptions: {
      input: resolve(import.meta.dirname, 'resources/entries/comments.ts'),
      external: Object.keys(WORDPRESS_GLOBALS),
      output: {
        format: 'iife',
        globals: WORDPRESS_GLOBALS,
        entryFileNames: 'comments.js',
        minify: MINIFY,
        comments: COMMENTS,
      },
    },
  },
});
