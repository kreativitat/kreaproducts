/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

const moduleBase = process.env.KREAPRODUCTS_STOCK_BASE || '/custom/kreaproducts/stock_frontend/';

export default defineConfig({
  base: moduleBase,
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: false,
      includeManifestIcons: false,
      manifest: {
        name: 'KreaProducts Stock',
        short_name: 'KreaProducts Stock',
        lang: 'pt-PT',
        description: 'Mobile stock counting for Dolibarr inventories.',
        theme_color: '#087f72',
        background_color: '#eef3f0',
        display: 'standalone',
        scope: '/custom/kreaproducts/',
        start_url: '/custom/kreaproducts/stock_mobile.php',
        icons: [
          { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          { src: 'icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' }
        ]
      },
      workbox: {
        skipWaiting: true,
        clientsClaim: true,
        cleanupOutdatedCaches: true,
        globPatterns: ['**/*.{js,css,html,png,ico}'],
        modifyURLPrefix: {
          '': 'stock_frontend/'
        },
        navigateFallbackDenylist: [/.*/]
      }
    })
  ],
  build: {
    sourcemap: false,
    target: 'es2020'
  }
});
