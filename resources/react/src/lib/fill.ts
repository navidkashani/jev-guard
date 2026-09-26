import { useEffect } from 'react';

/**
 * WordPress's admin menu can be taller than the page: on a site with many plugins the menu runs past the bottom of the
 * frame, and the page keeps that extra height with nothing in it. The frame then ended before the page did, leaving a
 * strip under the footer. Measuring the menu and giving the content area that height makes the frame reach the bottom of
 * the page, so the footer is the last thing on it whatever the menu's length.
 */
export function useFullHeight() {
  useEffect(() => {
    const menu = document.getElementById('adminmenuwrap');
    const area = document.getElementById('wpbody-content');
    if (!menu || !area) return;
    const set = () => area.style.setProperty('--sl-fill', Math.ceil(menu.getBoundingClientRect().height) + 'px');
    set();
    const watch = new ResizeObserver(set);
    watch.observe(menu);
    window.addEventListener('resize', set);
    return () => {
      watch.disconnect();
      window.removeEventListener('resize', set);
      area.style.removeProperty('--sl-fill');
    };
  }, []);
}
