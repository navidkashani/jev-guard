import { useEffect, useRef, useState } from 'react';
import { adminSettings } from '@/settings';

export type Screen = 'overview' | 'settings' | 'calibration';

const SCREENS: Screen[] = ['overview', 'settings', 'calibration'];

/**
 * A screen with unsaved work registers a guard; switching screens (the app's menu, or back and forward) asks it first.
 * It returns true when leaving is fine.
 */
let leaveGuard: (() => boolean) | null = null;

export function setLeaveGuard(guard: (() => boolean) | null) {
  leaveGuard = guard;
}

function fromUrl(): Screen {
  const tab = new URLSearchParams(window.location.search).get('tab');
  return SCREENS.includes(tab as Screen) ? (tab as Screen) : 'overview';
}

/**
 * Which screen is open. Overview, Settings and Calibration are one WordPress page (Settings → SpamLens) with a `tab`
 * parameter: switching pushes the other URL without a page load, and back and forward work.
 */
export function useScreen(titles: Record<Screen, string>): [Screen, (next: Screen) => void] {
  const [screen, setScreen] = useState<Screen>(fromUrl);
  const current = useRef(screen);
  current.current = screen;
  useEffect(() => {
    const onPop = () => {
      const next = fromUrl();
      if (next !== current.current && leaveGuard && !leaveGuard()) {
        // Stay: put the URL of the screen that is still open back.
        window.history.pushState({ spamlens: current.current }, '', adminSettings().urls[current.current]);
        return;
      }
      setScreen(next);
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);
  useEffect(() => {
    // "Settings ‹ Site — WordPress": the part before ‹ names the screen.
    document.title = document.title.replace(/^[^‹]*‹/, titles[screen] + ' ‹');
  }, [screen, titles]);
  const go = (next: Screen) => {
    if (next === current.current) return;
    if (leaveGuard && !leaveGuard()) return;
    window.history.pushState({ spamlens: next }, '', adminSettings().urls[next]);
    setScreen(next);
    window.scrollTo({ top: 0 });
  };
  return [screen, go];
}
