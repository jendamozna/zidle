import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import ScannerApp from './ScannerApp.jsx';
import '../styles.css';
import './scanner.css';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <ScannerApp />
  </StrictMode>,
);

// Offline: the service worker keeps the scanner on the device (public/sw.js).
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(() => {
    /* no offline start, everything else works */
  });
}
