import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/App';
import '@/index.css';

// Enqueued only on Settings → SpamLens (src/Service/Assets/AssetManager.php); without the mount there is nothing to do.
const mount = document.getElementById('spamlens-admin');
if (mount && window.spamlensAdmin) {
  createRoot(mount).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}
