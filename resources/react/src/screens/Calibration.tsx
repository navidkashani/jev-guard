import { useEffect, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowRight } from 'lucide-react';
import { api, errorText, type SettingsPayload, type StatsPayload } from '@/lib/api';
import { sleep } from '@/lib/async';
import { backoffDelay, decide, disagreements, scoredRows, suggest, tally, type CalibrationRow, type Expected, type Policy } from '@/lib/calibration';
import { fmt, pct, threshold } from '@/lib/format';
import type { Screen } from '@/lib/screen';
import { cn } from '@/lib/utils';
import { NoticeBox, Shell } from '@/shell/Shell';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/field';
import { Loading } from '@/screens/Loading';

type Props = {
  go: (next: Screen) => void;
  settings: SettingsPayload | null;
  stats: StatsPayload | null;
  loadError: string;
};

type Status = { tone: 'busy' | 'error' | 'idle'; text: string };


/** Runs Jev on comments already moderated and compares its answers with those decisions. Read-only. */
export function Calibration({ go, settings, loadError }: Props) {
  return (
    <Shell section="calibration" go={go} title={__('Calibration', 'spamlens')}
      description={__('Runs Jev on a sample of comments you already moderated (newest Spam and Approved comments, excluding those written by moderators) and compares the results with your decisions at the thresholds saved in Settings. Read-only: no comment is changed and nothing is stored.', 'spamlens')}>
      {!settings ? <Loading error={loadError} /> : !settings.key.stored ? (
        <Card>
          <CardBody className="flex flex-col items-start gap-3 pt-[22px]">
            <p className="text-ink2">{__('Save an API key first.', 'spamlens')}</p>
            <Button variant="primary" onClick={() => go('settings')}>{__('Open the settings', 'spamlens')}<ArrowRight size={14} className="sl-flip" aria-hidden="true" /></Button>
          </CardBody>
        </Card>
      ) : <Runner settings={settings} />}
    </Shell>
  );
}

function Runner({ settings }: { settings: SettingsPayload }) {
  const [size, setSize] = useState(50);
  const [running, setRunning] = useState(false);
  const [status, setStatus] = useState<Status>({ tone: 'idle', text: '' });
  const [progress, setProgress] = useState(0);
  const [rows, setRows] = useState<CalibrationRow[] | null>(null);
  const alive = useRef(true);
  useEffect(() => {
    // Set on every mount, not only the first: React's development mode mounts, unmounts and mounts again.
    alive.current = true;
    return () => { alive.current = false; };
  }, []);

  const policy: Policy = {
    spam: settings.values.spam_threshold,
    hold: settings.values.hold_threshold,
    holdAbusive: settings.values.hold_abusive,
    maxLinks: settings.maxLinks,
  };

  async function run() {
    setRunning(true);
    setRows(null);
    setProgress(0);
    setStatus({ tone: 'busy', text: __('Collecting sample…', 'spamlens') });
    const done: CalibrationRow[] = [];
    try {
      const sample = await api.sample(size);
      let queue: { id: number; expected: Expected }[] = [
        ...sample.spam.map((id) => ({ id, expected: 'spam' as const })),
        ...sample.ham.map((id) => ({ id, expected: 'ham' as const })),
      ];
      if (queue.length < 4) {
        setStatus({ tone: 'error', text: __('Not enough comments: the calibration needs at least a few comments in both the Spam and Approved lists.', 'spamlens') });
        return;
      }
      let attempt = 0;
      while (queue.length && alive.current) {
        const sent = queue.slice(0, 10);
        const { results, halt } = await api.batch(sent);
        done.push(...results);
        // The server works through the batch in order and may stop early (time budget, rate limit). Everything up to
        // the last answered id is done, including ids it skipped without an answer (a deleted comment); the rest is
        // sent again. A batch with no answer and no halt is dropped whole, so the run always ends.
        const answered = new Set(results.map((r) => r.id));
        const last = sent.reduce((at, q, i) => (answered.has(q.id) ? i : at), -1);
        const drop = last === -1 && !halt ? sent.length : last + 1;
        const finished = new Set(sent.slice(0, drop).map((q) => q.id));
        queue = queue.filter((q) => !finished.has(q.id));
        const total = done.length + queue.length;
        setProgress(total ? done.length / total : 1);
        setStatus({ tone: 'busy', text: sprintf(
          /** translators: 1: number classified, 2: total */
          __('%1$d of %2$d comments classified', 'spamlens'), done.length, total) });
        if (halt) {
          if (halt.code === 'rate_limited' || halt.code === 'overloaded') {
            const wait = backoffDelay(attempt++, halt.retryAfter);
            setStatus({ tone: 'busy', text: sprintf(
              /** translators: %d: seconds */
              __('Provider rate limit — pausing for %d s…', 'spamlens'), Math.round(wait / 1000)) });
            await sleep(wait);
            continue;
          }
          setStatus({ tone: 'error', text: sprintf(
            /** translators: %s: error message */
            __('Error: %s', 'spamlens'), `${halt.label} — ${halt.message}`) });
          break;
        }
        attempt = 0;
      }
      if (alive.current) {
        setStatus((s) => (s.tone === 'error' ? s : { tone: 'idle', text: '' }));
      }
    } catch (e) {
      setStatus({ tone: 'error', text: sprintf(
        /** translators: %s: error message */
        __('Error: %s', 'spamlens'), errorText(e, __('Request failed', 'spamlens'))) });
    } finally {
      if (alive.current) {
        setRows(done);
        setRunning(false);
      }
    }
  }

  return (
    <>
      <Card>
        <CardHeader><CardTitle>{__('Run a calibration', 'spamlens')}</CardTitle></CardHeader>
        <CardBody className="flex flex-col gap-4">
          <div className="flex flex-wrap items-end gap-3">
            <div className="flex flex-col gap-2">
              <label htmlFor="spamlens-calibration-size" className="text-[12px] font-medium text-ink2">{__('Sample size per class', 'spamlens')}</label>
              <Select id="spamlens-calibration-size" className="w-32" value={size} disabled={running} onChange={(e) => setSize(Number(e.target.value))}>
                {[25, 50, 100].map((n) => <option key={n} value={n}>{fmt(n)}</option>)}
              </Select>
            </div>
            <Button variant="primary" onClick={run} disabled={running} aria-busy={running}>{__('Run calibration', 'spamlens')}</Button>
          </div>
          <p className="text-[12px] text-ink2">{__('Each comment in the sample is one request to your provider. On a rate-limited free tier the run pauses and resumes by itself.', 'spamlens')}</p>
          {running && (
            <div className="h-2 w-full max-w-[720px] overflow-hidden rounded bg-track" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(progress * 100)}
              aria-label={__('Calibration progress', 'spamlens')}>
              <div className="sl-bar h-full bg-brand" style={{ width: `${Math.round(progress * 100)}%` }} />
            </div>
          )}
          <p aria-live="polite" className={cn('text-[13px]', status.tone === 'error' ? 'text-critical' : 'text-ink2', status.tone === 'busy' && 'italic')}>{status.text}</p>
        </CardBody>
      </Card>
      {rows && rows.length > 0 && <Results rows={rows} policy={policy} settings={settings} />}
    </>
  );
}

function Results({ rows, policy, settings }: { rows: CalibrationRow[]; policy: Policy; settings: SettingsPayload }) {
  const scored = scoredRows(rows);
  const errors = rows.length - scored.length;
  const spam = scored.filter((r) => r.expected === 'spam').length;
  const ham = scored.length - spam;
  const t = tally(scored, policy);
  const best = suggest(scored, policy);
  const model = scored.length ? scored[scored.length - 1].model ?? '' : '';
  const context = settings.pageContext.find((l) => l.id === settings.values.page_context)?.label ?? '';
  const off = disagreements(scored, policy);
  const ratio = (n: number, of: number) => `${fmt(n)} / ${fmt(of)}`;
  const cell = 'border-b border-border px-3 py-2.5 text-start align-top';
  const label = (d: string) => (d === 'spam' ? __('Spam', 'spamlens') : d === 'hold' ? __('Hold', 'spamlens') : __('Allow', 'spamlens'));
  const tone: Record<string, string> = { spam: 'bg-[var(--tintBad)] text-critical', hold: 'bg-[var(--tintWarn)] text-ink', allow: 'bg-[var(--tintGood)] text-good-ink' };

  return (
    <>
      <Card>
        <CardHeader><CardTitle>{__('Results', 'spamlens')}</CardTitle></CardHeader>
        <CardBody className="flex flex-col gap-4">
          <table className="w-full max-w-[720px] text-[13px]">
            <tbody>
              {([
                [__('Accuracy', 'spamlens'), scored.length ? `${pct(t.correct / scored.length, 1)} (${ratio(t.correct, scored.length)})` : '—'],
                [__('False positives (approved comments that would be marked spam)', 'spamlens'), ratio(t.fp, ham)],
                [__('Approved comments that would be held', 'spamlens'), ratio(t.heldHam, ham)],
                [__('Spam caught', 'spamlens'), ratio(t.caught, spam)],
                [__('Spam that would be held for moderation', 'spamlens'), ratio(t.heldSpam, spam)],
                [__('Missed spam (spam that would be allowed)', 'spamlens'), ratio(t.missed, spam)],
              ] as [string, string][]).map(([k, v]) => (
                <tr key={k}><th scope="row" className={cell + ' w-[60%] font-normal text-ink2'}>{k}</th><td className={cell + ' font-medium tabular-nums'}>{v}</td></tr>
              ))}
            </tbody>
          </table>
          {best && (
            <p className="font-medium text-ink">{sprintf(
              /** translators: 1: spam threshold, 2: hold threshold, 3: false positives, 4: spam caught, 5: total spam */
              __('On this sample, a spam threshold of %1$s and a hold threshold of %2$s would give %3$d false positives and catch %4$d of %5$d spam.', 'spamlens'),
              threshold(best.spam), threshold(best.hold), best.fp, best.caught, spam)}</p>
          )}
          <div className="flex flex-col gap-1 text-[12px] text-mute">
            {model && <span>{sprintf(
              /** translators: %s: model id */
              __('Model: %s', 'spamlens'), model)}</span>}
            {context && <span>{sprintf(
              /** translators: %s: page context level, e.g. "Standard" */
              __('Page context: %s', 'spamlens'), context)}</span>}
            {errors > 0 && <span>{sprintf(
              /** translators: %d: number of comments */
              __('%d comments could not be classified.', 'spamlens'), errors)}</span>}
          </div>
        </CardBody>
      </Card>
      <Card>
        <CardHeader><CardTitle>{__('Where Jev disagreed', 'spamlens')}</CardTitle></CardHeader>
        <CardBody className="flex flex-col gap-3">
          <p className="text-ink2">{__('This reflects past comments only; nothing was changed. Comments Jev scored differently from your moderation:', 'spamlens')}</p>
          {!off.length ? <NoticeBox notice={{ type: 'success', text: __('Jev agreed with every moderated comment in the sample.', 'spamlens') }} /> : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-[13px]">
                <thead>
                  <tr className="text-[12px] text-mute">
                    <th scope="col" className={cell}>{__('Comment', 'spamlens')}</th>
                    <th scope="col" className={cell}>{__('Your moderation', 'spamlens')}</th>
                    <th scope="col" className={cell}>{__('SpamLens', 'spamlens')}</th>
                  </tr>
                </thead>
                <tbody>
                  {off.map((r) => {
                    const d = decide(r, policy);
                    return (
                      <tr key={r.id}>
                        <td className={cell}><a href={r.link}>{r.title || `#${r.id}`}</a><span className="block text-[12px] text-mute">{r.author}</span></td>
                        <td className={cell}>{r.expected === 'spam' ? __('Spam', 'spamlens') : __('Approved', 'spamlens')}</td>
                        <td className={cell}><span className={cn('inline-block rounded px-2 py-[2px] font-medium', tone[d])}>{`${pct(r.p ?? 0)} · ${r.category ?? ''} → ${label(d)}`}</span></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>
    </>
  );
}
