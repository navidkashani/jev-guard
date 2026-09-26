import type { SettingsPayload, StatsPayload } from '@/lib/api';

/** What src/Service/Assets/AssetManager.php prints before the bundle (window.spamlensAdmin). */
export type AdminSettings = {
  version: string;
  locale: string;
  urls: {
    overview: string;
    settings: string;
    calibration: string;
    pending: string;
    privacyGuide: string;
    support: string;
  };
  cronDisabled: boolean;
  logos: { veronalabs: string };
  initial: null | { settings: SettingsPayload; stats: StatsPayload };
};

export type Notice = { type: 'success' | 'error' | 'warning'; text: string };

declare global {
  interface Window { spamlensAdmin?: AdminSettings }
}

export function adminSettings(): AdminSettings {
  const s = window.spamlensAdmin;
  if (!s) throw new Error('spamlensAdmin settings missing');
  return s;
}
