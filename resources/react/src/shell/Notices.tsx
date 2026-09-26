import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

const SELECTOR = '.notice, .update-nag, .updated, .error, #message';

/**
 * One holder for WordPress's own notices (and other plugins'), kept for the life of the page. WordPress prints them
 * at the top of the content area, which on these screens means above the app's band; they are moved in here, under
 * the title, where they read as part of the page. Each screen draws its own Shell, so the holder is re-attached to
 * the new screen's slot instead of being thrown away with the old one. It starts with WordPress's `.wp-header-end`
 * marker, so core's own script moves notices to the same place.
 */
let holder: HTMLDivElement | null = null;

function getHolder(): HTMLDivElement {
  if (!holder) {
    holder = document.createElement('div');
    holder.className = 'flex flex-col gap-2.5 empty:hidden';
    const marker = document.createElement('div');
    marker.className = 'wp-header-end';
    holder.appendChild(marker);
  }
  return holder;
}

export function Notices({ className }: { className?: string }) {
  const slot = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const body = document.getElementById('wpbody-content');
    const here = slot.current;
    if (!body || !here) return;
    const box = getHolder();
    here.appendChild(box);
    const move = () => {
      body.querySelectorAll<HTMLElement>(':scope > *').forEach((el) => {
        if (el.id === 'spamlens-admin' || !el.matches(SELECTOR)) return;
        box.appendChild(el);
      });
      here.classList.toggle('hidden', box.querySelectorAll(SELECTOR).length === 0);
    };
    move();
    // A plugin that prints its notice after the page has loaded lands in the same place.
    const watch = new MutationObserver(move);
    watch.observe(body, { childList: true });
    watch.observe(box, { childList: true });
    return () => {
      watch.disconnect();
      box.remove();
    };
  }, []);
  return <div ref={slot} className={cn(className)} />;
}
