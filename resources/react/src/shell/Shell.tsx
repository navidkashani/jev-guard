import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { ArrowRight, ArrowUpRight, CheckCircle2, AlertCircle, AlertTriangle, ShieldCheck } from 'lucide-react';
import { adminSettings, type Notice } from '@/settings';
import { Notices } from '@/shell/Notices';
import { cn } from '@/lib/utils';
import type { Screen } from '@/lib/screen';

const measure = 'mx-auto w-full max-w-[1260px] px-10 max-[960px]:px-6 max-[782px]:px-4';

/** The SpamLens wordmark: a shield and the name, in the band's text colour. */
export function Wordmark({ size = 'md' }: { size?: 'md' | 'sm' }) {
  return (
    <span className={cn('inline-flex items-center gap-2 font-semibold tracking-[-0.01em]', size === 'md' ? 'text-[16px]' : 'text-[15px]')}>
      <span className="inline-flex h-7 w-7 items-center justify-center rounded-md bg-brand text-white" aria-hidden="true"><ShieldCheck size={17} strokeWidth={2.2} /></span>
      {__('SpamLens', 'spamlens')}
    </span>
  );
}

/** The publisher's SVG (resources/assets/veronalabs.svg), inlined so currentColor applies. A file shipped with the plugin. */
function VeronaLabs() {
  return <span className="inline-flex [&>svg]:block [&>svg]:h-auto [&>svg]:w-28" dangerouslySetInnerHTML={{ __html: adminSettings().logos.veronalabs }} />;
}

/** A link that leaves the admin carries UTM tags so the publisher can tell plugin traffic apart. */
function out(url: string, content: string): string {
  try {
    const u = new URL(url);
    u.searchParams.set('utm_source', 'spamlens');
    u.searchParams.set('utm_medium', 'plugin-admin');
    u.searchParams.set('utm_content', content);
    return u.toString();
  } catch {
    return url;
  }
}

/**
 * The frame: a dark band (name, section links, help), the title area, WordPress's relocated notices, the work area and
 * the footer with the publisher credit.
 */
export function Shell({ section, go, title, description, actions, children }: { section: Screen; go: (next: Screen) => void; title: string; description?: ReactNode; actions?: ReactNode; children: ReactNode }) {
  const s = adminSettings();
  const sections: { id: Screen; label: string }[] = [
    { id: 'overview', label: __('Overview', 'spamlens') },
    { id: 'settings', label: __('Settings', 'spamlens') },
    { id: 'calibration', label: __('Calibration', 'spamlens') },
  ];
  return (
    <>
      <header className="bg-band text-band-ink">
        <div className={cn(measure, 'flex min-h-16 items-center gap-7 max-[782px]:flex-wrap max-[782px]:gap-x-4 max-[782px]:gap-y-0')}>
          <a href={s.urls.overview} onClick={(e) => { e.preventDefault(); go('overview'); }} className="inline-flex min-h-16 items-center text-band-ink hover:text-white">
            <Wordmark />
          </a>
          <nav className="flex self-stretch gap-[22px] max-[782px]:order-3 max-[782px]:w-full max-[782px]:gap-4" aria-label={__('SpamLens sections', 'spamlens')}>
            {sections.map((e) => (
              <a key={e.id} href={s.urls[e.id]} onClick={(ev) => { if (ev.metaKey || ev.ctrlKey || ev.shiftKey) return; ev.preventDefault(); go(e.id); }} aria-current={section === e.id ? 'page' : undefined}
                className={cn('inline-flex min-h-11 items-center border-b-2 pt-[2px] text-[13px] font-medium', section === e.id ? 'border-brand text-band-ink' : 'border-transparent text-band-muted hover:text-white')}>{e.label}</a>
            ))}
          </nav>
          <div className="ms-auto flex items-center gap-1.5">
            <a href={s.urls.support} target="_blank" rel="noopener noreferrer" className="inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-[12.5px] font-medium text-band-muted hover:bg-band-edge hover:text-white">
              {__('Help', 'spamlens')}<ArrowUpRight size={14} className="sl-flip" aria-hidden="true" />
              <span className="sr-only">{__('(opens in a new tab)', 'spamlens')}</span>
            </a>
          </div>
        </div>
      </header>
      <div className={cn(measure, 'flex flex-wrap items-start justify-between gap-x-6 gap-y-3 pb-1.5 pt-[30px]')}>
        <div className="flex min-w-0 flex-[1_1_420px] flex-col gap-1.5">
          <h1 className="text-[26px] font-semibold leading-tight tracking-[-0.02em] text-ink">{title}</h1>
          {description && <p className="max-w-[72ch] text-ink2">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2 pt-1">{actions}</div>}
      </div>
      {/* WordPress moves its own notices to just before this marker; anything it leaves behind is collected below. */}
      <Notices className={cn(measure, 'flex flex-col gap-2.5 pt-3')} />
      <main className={cn(measure, 'flex flex-[1_0_auto] flex-col gap-5 pb-10 pt-[22px]')}>{children}</main>
      <Footer go={go} />
    </>
  );
}

function Footer({ go }: { go: (next: Screen) => void }) {
  const s = adminSettings();
  return (
    <footer className="mt-auto bg-band text-band-ink">
      <div className={cn(measure, 'grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-8 py-7 max-[960px]:grid-cols-[minmax(0,1fr)_auto] max-[960px]:gap-5 max-[782px]:grid-cols-1')}>
        <div className="inline-flex items-center gap-2.5"><Wordmark size="sm" /><span className="rounded-md border border-band-edge px-[7px] py-[2px] text-[11px] font-medium text-band-muted">v{s.version}</span></div>
        <div className="min-w-0 border-s border-band-edge ps-7 max-[960px]:col-start-1 max-[960px]:border-0 max-[960px]:ps-0">
          <span className="mb-1.5 block text-[11px] uppercase tracking-[.08em] text-band-muted">{__('Your data', 'spamlens')}</span>
          <a href={s.urls.settings + '#spamlens-privacy'} onClick={(e) => { e.preventDefault(); go('settings'); window.setTimeout(() => document.getElementById('spamlens-privacy')?.scrollIntoView(), 0); }}
            className="inline-flex items-center gap-[7px] text-[13px] text-band-ink hover:text-white hover:underline hover:underline-offset-4">{__('What is sent to the provider', 'spamlens')}<ArrowRight size={14} className="sl-flip" aria-hidden="true" /></a>
        </div>
        <a href={s.urls.support} target="_blank" rel="noopener noreferrer" className="block rounded-[9px] border border-band-edge px-[18px] py-3 text-start text-band-ink hover:bg-band-edge hover:text-white max-[960px]:col-start-2 max-[960px]:row-span-2 max-[960px]:row-start-1 max-[782px]:col-auto max-[782px]:row-auto">
          <span className="mb-1.5 block text-[11px] text-band-muted">{__('Need a hand?', 'spamlens')}</span>
          <strong className="flex items-center justify-between gap-5 text-[13px] font-medium">{__('Support forum', 'spamlens')}<ArrowUpRight size={14} className="sl-flip" aria-hidden="true" /></strong>
          <span className="sr-only">{__('(opens in a new tab)', 'spamlens')}</span>
        </a>
      </div>
      <div className={measure}>
        <div className="flex items-center justify-center gap-3 border-t border-band-edge pb-[18px] pt-4 text-[11px] text-band-muted">
          <span>{__('A product by', 'spamlens')}</span>
          <a href={out('https://veronalabs.com/', 'footer-publisher')} target="_blank" rel="noopener noreferrer" aria-label="VeronaLabs" className="inline-flex min-h-7 items-center text-band-muted opacity-75 hover:opacity-100"><VeronaLabs /></a>
        </div>
      </div>
    </footer>
  );
}

/** The result of an action, with WordPress's notice classes and the app's card look. */
export function NoticeBox({ notice, onDismiss }: { notice: Notice | null; onDismiss?: () => void }) {
  if (!notice) return null;
  const tone = { success: 'text-good-ink', error: 'text-critical', warning: 'text-warn' }[notice.type];
  const Icon = { success: CheckCircle2, error: AlertCircle, warning: AlertTriangle }[notice.type];
  return (
    // `inline` keeps WordPress's common.js from moving this box next to .wp-header-end, out of React's tree.
    <div className={cn('notice', 'inline', `notice-${notice.type}`, 'flex items-start gap-2.5')} role={notice.type === 'error' ? 'alert' : 'status'}>
      <Icon size={16} className={cn('mt-[1px] shrink-0', tone)} aria-hidden="true" />
      <p className="flex-1 text-ink">{notice.text}</p>
      {onDismiss && <button type="button" onClick={onDismiss} className="cursor-pointer border-0 bg-transparent p-0 text-[12px] text-mute hover:text-ink">{__('Dismiss', 'spamlens')}</button>}
    </div>
  );
}

/** Label / value pairs, 170px label column. */
export function KV({ rows }: { rows: [string, ReactNode][] }) {
  return (
    <div className="grid grid-cols-[170px_minmax(0,1fr)] gap-x-3 gap-y-2 text-[13px] max-[960px]:grid-cols-1">
      {rows.map(([k, v], i) => (
        <div key={i} className="contents"><div className="text-mute max-[960px]:mt-1">{k}</div><div className="min-w-0 [overflow-wrap:anywhere]">{v}</div></div>
      ))}
    </div>
  );
}
