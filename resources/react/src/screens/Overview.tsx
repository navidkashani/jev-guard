import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowRight, ArrowUpRight } from 'lucide-react';
import { adminSettings, type Notice } from '@/settings';
import { api, errorText, type SettingsPayload, type StatKey, type StatsPayload } from '@/lib/api';
import { fmt } from '@/lib/format';
import type { Screen } from '@/lib/screen';
import { KV, NoticeBox, Shell } from '@/shell/Shell';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge, Dot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Loading } from '@/screens/Loading';

type Props = {
  go: (next: Screen) => void;
  settings: SettingsPayload | null;
  stats: StatsPayload | null;
  loadError: string;
  onStats: (stats: StatsPayload) => void;
};

/** Is the plugin working, what has it done, and where to go next. */
export function Overview({ go, settings, stats, loadError, onStats }: Props) {
  const s = adminSettings();
  return (
    <Shell section="overview" go={go} title={__('SpamLens', 'spamlens')}
      description={__('Spam protection for comments, reviews and Contact Form 7. Jev scores each submission; spam goes to the Spam folder, doubtful ones wait for moderation, and WordPress decides the rest.', 'spamlens')}>
      {s.cronDisabled && (
        <NoticeBox notice={{ type: 'warning', text: __('DISABLE_WP_CRON is set. The automatic re-check of comments that could not be classified depends on WP-Cron; make sure a system cron job runs wp-cron.php.', 'spamlens') }} />
      )}
      {!settings || !stats ? <Loading error={loadError} /> : (
        <>
          <div className="grid grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] gap-5 max-[960px]:grid-cols-1">
            <Status settings={settings} go={go} />
            <Links go={go} />
          </div>
          <Statistics stats={stats} onStats={onStats} />
        </>
      )}
    </Shell>
  );
}

function Status({ settings, go }: { settings: SettingsPayload; go: (next: Screen) => void }) {
  const provider = settings.providers.find((p) => p.id === settings.values.provider);
  const model = settings.values.model || provider?.model || '';
  const error = settings.lastError;
  const enabled = settings.integrations.filter((i) => i.available && settings.values.integrations[i.id]);
  const ready = settings.key.stored;
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Status', 'spamlens')}</CardTitle>
        {ready && !error && <Badge variant="pill"><Dot tone="good" />{__('Checking submissions', 'spamlens')}</Badge>}
        {ready && error && <Badge variant="pill"><Dot tone="critical" />{__('Service problem', 'spamlens')}</Badge>}
        {!ready && <Badge variant="pill"><Dot tone="warn" />{__('Not set up', 'spamlens')}</Badge>}
      </CardHeader>
      <CardBody className="flex flex-col gap-4">
        {!ready ? (
          <>
            <p className="text-ink2">{__('SpamLens needs an API key before it can check comments. Pick the service that hosts Jev for you and paste a key from that service.', 'spamlens')}</p>
            <div><Button variant="primary" onClick={() => go('settings')}>{__('Add an API key', 'spamlens')}<ArrowRight size={14} className="sl-flip" aria-hidden="true" /></Button></div>
          </>
        ) : (
          <KV rows={[
            [__('Provider', 'spamlens'), provider?.label ?? settings.values.provider],
            [__('Model', 'spamlens'), model ? <code>{model}</code> : '—'],
            [__('API key', 'spamlens'), settings.key.constant ? __('Defined in wp-config.php', 'spamlens') : <code>{settings.key.hint}</code>],
            [__('Checking', 'spamlens'), enabled.length ? enabled.map((i) => i.label).join(', ') : __('Nothing: every integration is switched off.', 'spamlens')],
          ]} />
        )}
        {error && (
          <NoticeBox notice={{ type: 'error', text: sprintf(
            /** translators: 1: error label, e.g. "Authentication failed", 2: message from the provider */
            __('Last request failed: %1$s — %2$s', 'spamlens'), error.label, error.message) }} />
        )}
      </CardBody>
    </Card>
  );
}

function Links({ go }: { go: (next: Screen) => void }) {
  const s = adminSettings();
  const item = 'flex items-center justify-between gap-3 rounded-lg border border-border px-4 py-3 text-ink hover:border-line hover:bg-surface2';
  return (
    <Card>
      <CardHeader><CardTitle>{__('Next steps', 'spamlens')}</CardTitle></CardHeader>
      <CardBody className="flex flex-col gap-2">
        <a className={item} href={s.urls.pending}>
          <span><strong className="block text-[13px] font-medium text-ink">{__('Pending comments', 'spamlens')}</strong><span className="text-[12px] text-ink2">{__('Use Check for Spam to sweep the queue.', 'spamlens')}</span></span>
          <ArrowUpRight size={14} className="sl-flip shrink-0" aria-hidden="true" />
        </a>
        <a className={item} href={s.urls.calibration} onClick={(e) => { e.preventDefault(); go('calibration'); }}>
          <span><strong className="block text-[13px] font-medium text-ink">{__('Calibration', 'spamlens')}</strong><span className="text-[12px] text-ink2">{__('Compare Jev with your past moderation.', 'spamlens')}</span></span>
          <ArrowRight size={14} className="sl-flip shrink-0" aria-hidden="true" />
        </a>
        <a className={item} href={s.urls.settings} onClick={(e) => { e.preventDefault(); go('settings'); }}>
          <span><strong className="block text-[13px] font-medium text-ink">{__('Settings', 'spamlens')}</strong><span className="text-[12px] text-ink2">{__('Provider, thresholds, privacy and integrations.', 'spamlens')}</span></span>
          <ArrowRight size={14} className="sl-flip shrink-0" aria-hidden="true" />
        </a>
      </CardBody>
    </Card>
  );
}

function Statistics({ stats, onStats }: { stats: StatsPayload; onStats: (stats: StatsPayload) => void }) {
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const rows: [StatKey, string][] = [
    ['checked', __('Checked', 'spamlens')],
    ['spam', __('Marked as spam', 'spamlens')],
    ['held', __('Held for moderation', 'spamlens')],
    ['errors', __('Errors (service unavailable)', 'spamlens')],
    ['fp', __('False positives (un-spammed by a moderator)', 'spamlens')],
    ['fn', __('Missed spam (spammed by a moderator)', 'spamlens')],
  ];
  async function reset() {
    setBusy(true);
    try {
      onStats(await api.resetStats());
      setNotice({ type: 'success', text: __('Statistics were reset.', 'spamlens') });
    } catch (e) {
      setNotice({ type: 'error', text: errorText(e, __('The statistics could not be reset.', 'spamlens')) });
    } finally {
      setBusy(false);
      setConfirm(false);
    }
  }
  const cell = 'border-b border-border px-3 py-2.5 text-start';
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Statistics', 'spamlens')}</CardTitle>
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[12px] text-mute">{sprintf(
            /** translators: %s: date */
            __('Counting since %s.', 'spamlens'), stats.sinceLabel)}</span>
          {!confirm ? (
            <Button size="sm" onClick={() => { setNotice(null); setConfirm(true); }}>{__('Reset', 'spamlens')}</Button>
          ) : (
            <>
              <Button size="sm" variant="dangerSolid" onClick={reset} disabled={busy} aria-busy={busy}>{__('Reset statistics', 'spamlens')}</Button>
              <Button size="sm" variant="ghost" onClick={() => setConfirm(false)} disabled={busy}>{__('Cancel', 'spamlens')}</Button>
            </>
          )}
        </div>
      </CardHeader>
      <CardBody className="flex flex-col gap-3">
        <NoticeBox notice={notice} />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[520px] text-[13px]">
            <thead>
              <tr className="text-[12px] text-mute">
                <th className={cell} scope="col"><span className="sr-only">{__('Counter', 'spamlens')}</span></th>
                <th className={cell} scope="col">{__('Total', 'spamlens')}</th>
                {stats.columns.map((c) => <th key={c.id} className={cell} scope="col">{c.label}</th>)}
              </tr>
            </thead>
            <tbody>
              {rows.map(([key, label]) => (
                <tr key={key}>
                  <th className={cell + ' font-normal text-ink2'} scope="row">{label}</th>
                  <td className={cell + ' font-semibold tabular-nums'}>{fmt(stats.totals[key])}</td>
                  {stats.columns.map((c) => <td key={c.id} className={cell + ' tabular-nums'}>{fmt(stats.integrations[c.id]?.[key] ?? 0)}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardBody>
    </Card>
  );
}
