import { useEffect, useMemo, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { adminSettings } from '@/settings';
import { api, errorText, type SettingsPayload, type StatsPayload } from '@/lib/api';
import { useFullHeight } from '@/lib/fill';
import { useScreen, type Screen } from '@/lib/screen';
import { Overview } from '@/screens/Overview';
import { Settings } from '@/screens/Settings';
import { Calibration } from '@/screens/Calibration';

/**
 * One app for the three screens. The settings and statistics arrive with the page (window.spamlensAdmin.initial) and
 * are shared by every screen, so switching screens never refetches and a save shows up everywhere at once.
 */
export function App() {
  const titles = useMemo<Record<Screen, string>>(() => ({
    overview: __('SpamLens', 'spamlens'),
    settings: __('SpamLens settings', 'spamlens'),
    calibration: __('SpamLens calibration', 'spamlens'),
  }), []);
  const [screen, go] = useScreen(titles);
  const initial = adminSettings().initial;
  const [settings, setSettings] = useState<SettingsPayload | null>(initial?.settings ?? null);
  const [stats, setStats] = useState<StatsPayload | null>(initial?.stats ?? null);
  const [loadError, setLoadError] = useState('');
  useFullHeight();

  useEffect(() => {
    if (settings && stats) return;
    Promise.all([api.settings(), api.stats()])
      .then(([s, st]) => { setSettings(s); setStats(st); })
      .catch((e) => setLoadError(errorText(e, __('The settings could not be loaded. Reload the page to try again.', 'spamlens'))));
    // Only on mount: later changes come back from the save and reset requests.
  }, []);

  const props = { go, settings, stats, loadError };
  if (screen === 'settings') return <Settings {...props} onSaved={setSettings} />;
  if (screen === 'calibration') return <Calibration {...props} />;
  return <Overview {...props} onStats={setStats} />;
}
