/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './styles.css';

function registerStockServiceWorker(): void {
  if (!('serviceWorker' in navigator)) {
    return;
  }

  const dataUrl = new URL(
    window.__KREAPRODUCTS_STOCK_DATA_URL__ || '/custom/kreaproducts/stock_mobile.php',
    window.location.href
  );
  const scopeUrl = new URL('./', dataUrl);
  dataUrl.searchParams.set('kps_action', 'service_worker');

  void navigator.serviceWorker
    .register(dataUrl.toString(), { scope: scopeUrl.pathname })
    .then((registration) => {
      setInterval(() => void registration.update(), 60 * 1000);
    })
    .catch((error: unknown) => {
      console.error('Unable to register KreaProducts Stock service worker.', error);
    });
}

registerStockServiceWorker();

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
