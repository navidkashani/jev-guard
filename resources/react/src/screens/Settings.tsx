import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowUpRight } from 'lucide-react';
import { adminSettings, type Notice } from '@/settings';
import { api, errorText, type SettingsInput, type SettingsPayload, type SettingsValues, type StatsPayload, type TestResult } from '@/lib/api';
import { pct } from '@/lib/format';
import { setLeaveGuard, type Screen } from '@/lib/screen';
import { cn } from '@/lib/utils';
import { NoticeBox, Shell } from '@/shell/Shell';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Choice, Field, Select, Textarea } from '@/components/ui/field';
import { Loading } from '@/screens/Loading';

type Props = {
  go: (next: Screen) => void;
  settings: SettingsPayload | null;
  stats: StatsPayload | null;
  loadError: string;
  onSaved: (settings: SettingsPayload) => void;
};

/** Settings → SpamLens → Settings: connection, detection, privacy and integrations in one form. */
export function Settings({ go, settings, loadError, onSaved }: Props) {
  return (
    <Shell section="settings" go={go} title={__('Settings', 'spamlens')}
      description={__('Connect a provider, tune how submissions are judged, and choose what is sent and which forms are checked.', 'spamlens')}>
      {!settings ? <Loading error={loadError} /> : <Form payload={settings} onSaved={onSaved} />}
    </Shell>
  );
}

type Draft = { values: SettingsValues; apiKey: string; clearKey: boolean };

function draftFrom(payload: SettingsPayload): Draft {
  return { values: structuredClone(payload.values), apiKey: '', clearKey: false };
}

function Form({ payload, onSaved }: { payload: SettingsPayload; onSaved: (settings: SettingsPayload) => void }) {
  const [draft, setDraft] = useState<Draft>(() => draftFrom(payload));
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  // Bumped when the draft is replaced (saved or discarded), so fields with local state start over.
  const [version, setVersion] = useState(0);
  const saved = useMemo(() => JSON.stringify(draftFrom(payload)), [payload]);
  const dirty = JSON.stringify(draft) !== saved;

  // Switching to another screen of the app also asks first.
  useEffect(() => {
    if (!dirty) return;
    setLeaveGuard(() => window.confirm(__('You have unsaved changes. Leave this screen and lose them?', 'spamlens')));
    return () => setLeaveGuard(null);
  }, [dirty]);

  // Leaving the page with unsaved changes asks first, as WordPress's own settings screens do.
  useEffect(() => {
    if (!dirty) return;
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  const set = <K extends keyof SettingsValues>(key: K, value: SettingsValues[K]) => setDraft((d) => ({ ...d, values: { ...d.values, [key]: value } }));

  async function save(e: FormEvent) {
    e.preventDefault();
    const problem = invalid(draft.values);
    if (problem) {
      setNotice({ type: 'error', text: problem.text });
      // The message is at the top of the form; take the admin to the field that needs fixing.
      const field = document.getElementById(problem.field);
      field?.scrollIntoView({ block: 'center' });
      field?.focus();
      return;
    }
    setSaving(true);
    setNotice(null);
    const input: SettingsInput = { ...draft.values };
    if (draft.clearKey) input.clear_api_key = true;
    else if (draft.apiKey.trim()) input.api_key = draft.apiKey.trim();
    try {
      const next = await api.save(input);
      onSaved(next);
      setDraft(draftFrom(next));
      setVersion((v) => v + 1);
      setNotice({ type: 'success', text: __('Settings saved.', 'spamlens') });
    } catch (err) {
      setNotice({ type: 'error', text: errorText(err, __('The settings could not be saved.', 'spamlens')) });
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={save} className="flex flex-col gap-5" noValidate>
      <NoticeBox notice={notice} onDismiss={() => setNotice(null)} />
      <Connection key={version} payload={payload} draft={draft} setDraft={setDraft} set={set} />
      <Detection payload={payload} values={draft.values} set={set} />
      <Privacy values={draft.values} set={set} />
      <Integrations payload={payload} values={draft.values} set={set} />
      {/* While there are unsaved changes the bar floats above the cards (lifted off the bottom edge, with a shadow) so
          Save stays in reach; otherwise it sits after the last card like any other block. */}
      <div className={cn(
        'z-10 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-surface px-[22px] py-3',
        dirty && 'sticky bottom-4 border-line shadow-[0_8px_24px_rgba(20,24,31,.12),0_1px_3px_rgba(20,24,31,.08)]',
      )}>
        <Button variant="primary" type="submit" disabled={saving || !dirty} aria-busy={saving}>{__('Save changes', 'spamlens')}</Button>
        {dirty && <Button type="button" variant="ghost" disabled={saving} onClick={() => { setDraft(draftFrom(payload)); setVersion((v) => v + 1); setNotice(null); }}>{__('Discard changes', 'spamlens')}</Button>}
        <span className="text-[12px] text-mute" aria-live="polite">{dirty ? __('You have unsaved changes.', 'spamlens') : __('All changes saved.', 'spamlens')}</span>
      </div>
    </form>
  );
}

/** An empty number field is NaN, not 0, so validation catches it instead of saving 0. */
function toNumber(raw: string): number {
  return raw.trim() === '' ? Number.NaN : Number(raw);
}

/** Shows NaN as an empty field. */
function numberValue(n: number): number | '' {
  return Number.isFinite(n) ? n : '';
}

/** The first problem with the values and the id of its field, or null when they can be saved. Mirrors Settings::sanitize(). */
function invalid(v: SettingsValues): { text: string; field: string } | null {
  if (!Number.isFinite(v.spam_threshold) || v.spam_threshold < 0.5 || v.spam_threshold > 1) {
    return { field: 'spamlens_spam_threshold', text: __('The spam threshold must be a number from 0.5 to 1.', 'spamlens') };
  }
  if (!Number.isFinite(v.hold_threshold) || v.hold_threshold < 0.1 || v.hold_threshold > 1) {
    return { field: 'spamlens_hold_threshold', text: __('The hold threshold must be a number from 0.1 to 1.', 'spamlens') };
  }
  if (v.hold_threshold > v.spam_threshold) {
    return { field: 'spamlens_hold_threshold', text: __('The hold threshold cannot be higher than the spam threshold.', 'spamlens') };
  }
  if (!Number.isInteger(v.timeout) || v.timeout < 1 || v.timeout > 30) {
    return { field: 'spamlens_timeout', text: __('The timeout must be a whole number of seconds from 1 to 30.', 'spamlens') };
  }
  return null;
}

type SetValue = <K extends keyof SettingsValues>(key: K, value: SettingsValues[K]) => void;

function Section({ id, title, intro, children }: { id: string; title: string; intro: ReactNode; children: ReactNode }) {
  return (
    <Card id={id} className="scroll-mt-12">
      <CardHeader><CardTitle>{title}</CardTitle></CardHeader>
      <CardBody className="flex flex-col">
        <p className="mb-4 max-w-[80ch] text-ink2">{intro}</p>
        {children}
      </CardBody>
    </Card>
  );
}

function ExternalLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1">
      {children}<ArrowUpRight size={12} className="sl-flip" aria-hidden="true" />
      <span className="sr-only">{__('(opens in a new tab)', 'spamlens')}</span>
    </a>
  );
}

function Connection({ payload, draft, setDraft, set }: { payload: SettingsPayload; draft: Draft; setDraft: (update: (d: Draft) => Draft) => void; set: SetValue }) {
  const { values } = draft;
  const provider = payload.providers.find((p) => p.id === values.provider);
  const [testing, setTesting] = useState(false);
  const [result, setResult] = useState<{ ok: true; data: TestResult } | { ok: false; text: string } | null>(null);

  async function test() {
    setTesting(true);
    setResult(null);
    try {
      const data = await api.test({
        provider: values.provider,
        api_key: draft.clearKey ? '' : draft.apiKey.trim(),
        clear_api_key: draft.clearKey,
        model: values.model,
        custom_endpoint: values.custom_endpoint,
        timeout: values.timeout,
      });
      setResult({ ok: true, data });
    } catch (e) {
      setResult({ ok: false, text: errorText(e, __('Request failed', 'spamlens')) });
    } finally {
      setTesting(false);
    }
  }

  return (
    <Section id="spamlens-connection" title={__('Connection', 'spamlens')}
      intro={__('Jev is a decision model by TypeSafe AI. Pick the service that hosts it for you and paste an API key from that service.', 'spamlens')}>
      <Field label={__('Provider', 'spamlens')} htmlFor="spamlens_provider"
        help={provider && (provider.note || provider.keysUrl || provider.freeUrl) ? (
          <span>
            {provider.note}
            {provider.keysUrl && <> <ExternalLink href={provider.keysUrl}>{__('Get a key', 'spamlens')}</ExternalLink></>}
            {provider.freeUrl && <> · <ExternalLink href={provider.freeUrl}>{__('Free-tier model list', 'spamlens')}</ExternalLink></>}
          </span>
        ) : undefined}>
        <Select id="spamlens_provider" value={values.provider} onChange={(e) => set('provider', e.target.value)}>
          {payload.providers.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
        </Select>
      </Field>

      <Field label={__('API key', 'spamlens')} htmlFor="spamlens_api_key"
        help={payload.key.constant
          ? __('Defined by the SPAMLENS_API_KEY constant in wp-config.php.', 'spamlens')
          : payload.key.stored
            ? __('A key is stored. Leave the field empty to keep it, or paste a new one to replace it. You can also define SPAMLENS_API_KEY in wp-config.php.', 'spamlens')
            : __('You can also define SPAMLENS_API_KEY in wp-config.php.', 'spamlens')}>
        {payload.key.constant ? (
          <Input id="spamlens_api_key" type="text" value={payload.key.hint} disabled />
        ) : (
          <div className="flex flex-wrap items-center gap-2">
            <Input id="spamlens_api_key" type="password" autoComplete="new-password" spellCheck={false}
              placeholder={draft.clearKey ? __('The stored key will be removed', 'spamlens') : payload.key.stored ? payload.key.hint : __('Paste your API key', 'spamlens')}
              value={draft.apiKey} disabled={draft.clearKey}
              onChange={(e) => { const apiKey = e.target.value; setDraft((d) => ({ ...d, apiKey })); }} />
            {payload.key.stored && (
              <Button type="button" size="sm" variant={draft.clearKey ? 'default' : 'danger'} onClick={() => setDraft((d) => ({ ...d, clearKey: !d.clearKey, apiKey: '' }))}>
                {draft.clearKey ? __('Keep the key', 'spamlens') : __('Remove key', 'spamlens')}
              </Button>
            )}
          </div>
        )}
      </Field>

      <ModelField provider={provider} models={payload.models[values.provider] ?? []} value={values.model} onChange={(model) => set('model', model)} />

      {values.provider === 'custom' && (
        <Field label={__('Endpoint URL', 'spamlens')} htmlFor="spamlens_custom_endpoint">
          <Input id="spamlens_custom_endpoint" type="url" className="font-mono" spellCheck={false} value={values.custom_endpoint}
            placeholder="https://example.com/v1/systemone" onChange={(e) => set('custom_endpoint', e.target.value)} />
        </Field>
      )}

      <Field label={__('Timeout', 'spamlens')} htmlFor="spamlens_timeout"
        help={__('Checks run while the visitor waits, so keep this short. Typical answers take 1–2 seconds.', 'spamlens')}>
        <div className="flex items-center gap-2">
          <Input id="spamlens_timeout" type="number" min={1} max={30} step={1} className="w-24" value={numberValue(values.timeout)}
            onChange={(e) => set('timeout', toNumber(e.target.value))} />
          <span className="text-ink2">{__('seconds', 'spamlens')}</span>
        </div>
      </Field>

      <Field label={__('Test connection', 'spamlens')}
        help={__('Sends a canned spam comment using the values above (unsaved changes included) and shows the model version, latency and probability.', 'spamlens')}>
        <div className="flex flex-wrap items-center gap-3">
          <Button type="button" onClick={test} disabled={testing} aria-busy={testing}>{testing ? __('Testing…', 'spamlens') : __('Test connection', 'spamlens')}</Button>
          <span aria-live="polite" className={cn('text-[13px]', result?.ok ? 'text-good-ink' : 'text-critical')}>
            {result?.ok && sprintf(
              /** translators: 1: model id, 2: latency in ms, 3: spam probability as a percentage, 4: category */
              __('Connected. Model %1$s answered in %2$d ms and rated the sample %3$s spam (%4$s).', 'spamlens'),
              result.data.model, result.data.latencyMs, pct(result.data.probability), result.data.category)}
            {result && !result.ok && sprintf(
              /** translators: %s: error message */
              __('Failed: %s', 'spamlens'), result.text)}
          </span>
        </div>
      </Field>
    </Section>
  );
}

const OTHER = '__other__';

/**
 * The model to ask: the provider default (an alias that follows new releases), one of the versioned ids that have
 * answered on this site (to pin after calibrating), or any other id typed in.
 */
function ModelField({ provider, models, value, onChange }: { provider: SettingsPayload['providers'][number] | undefined; models: string[]; value: string; onChange: (model: string) => void }) {
  const known = models.filter((m) => m && m !== provider?.model);
  const [other, setOther] = useState(() => value !== '' && !known.includes(value));
  const selected = other ? OTHER : value;
  return (
    <Field label={__('Model', 'spamlens')} htmlFor="spamlens_model"
      help={__('The provider default always follows the newest release. Once your thresholds are calibrated, pin the versioned model shown by "Test connection" (for example jev-1.13.0) so a model upgrade cannot shift your scores. Versions that have answered on this site are listed here.', 'spamlens')}>
      <Select id="spamlens_model" value={selected} onChange={(e) => {
        const next = e.target.value;
        if (next === OTHER) { setOther(true); return; }
        setOther(false);
        onChange(next);
      }}>
        <option value="">{provider?.model ? sprintf(
          /** translators: %s: model id, e.g. jev-latest */
          __('Provider default (%s)', 'spamlens'), provider.model) : __('Provider default', 'spamlens')}</option>
        {known.length > 0 && (
          <optgroup label={__('Pin a version', 'spamlens')}>
            {known.map((m) => <option key={m} value={m}>{m}</option>)}
          </optgroup>
        )}
        <option value={OTHER}>{__('Other model id…', 'spamlens')}</option>
      </Select>
      {other && (
        <Input id="spamlens_model_other" type="text" className="font-mono" spellCheck={false} value={value}
          placeholder="jev-1.13.0" aria-label={__('Model id', 'spamlens')} autoFocus={value === ''}
          onChange={(e) => onChange(e.target.value)} />
      )}
    </Field>
  );
}

function Detection({ payload, values, set }: { payload: SettingsPayload; values: SettingsValues; set: SetValue }) {
  return (
    <Section id="spamlens-detection" title={__('Detection', 'spamlens')}
      intro={__('Each submission gets a spam probability from 0 to 1. Above the spam threshold it goes to the Spam folder; above the hold threshold it waits for moderation; below that WordPress decides as usual. Jev never approves anything on its own.', 'spamlens')}>
      <Field label={__('Spam threshold', 'spamlens')} htmlFor="spamlens_spam_threshold"
        help={__('Probability at or above which a submission is marked as spam. 0.85 is a good default for English sites; raise it for other languages or after a calibration run shows false positives.', 'spamlens')}>
        <Input id="spamlens_spam_threshold" type="number" min={0.5} max={1} step={0.01} className="w-24" value={numberValue(values.spam_threshold)}
          onChange={(e) => set('spam_threshold', toNumber(e.target.value))} />
      </Field>

      <Field label={__('Hold threshold', 'spamlens')} htmlFor="spamlens_hold_threshold"
        help={__('Comments between this and the spam threshold are held for moderation. Comments that look like spam but clearly respond to the page are held too. Contact-form entries have no queue and are delivered.', 'spamlens')}>
        <Input id="spamlens_hold_threshold" type="number" min={0.1} max={1} step={0.01} className="w-24" value={numberValue(values.hold_threshold)}
          onChange={(e) => set('hold_threshold', toNumber(e.target.value))} />
        {values.hold_threshold > values.spam_threshold && (
          <span className="text-[12px] text-warn">{__('The hold threshold is above the spam threshold; it will be saved as the spam threshold.', 'spamlens')}</span>
        )}
      </Field>

      <Field label={__('Abusive comments', 'spamlens')} legend>
        <Choice label={__('Hold comments Jev rates as abusive, hateful, threatening or harassing, even when they are not spam', 'spamlens')}
          checked={values.hold_abusive} onChange={(e) => set('hold_abusive', e.target.checked)} />
      </Field>

      <Field label={__('When the service is unavailable', 'spamlens')} legend>
        <Choice type="radio" name="spamlens_on_error" value="allow" checked={values.on_error === 'allow'} onChange={() => set('on_error', 'allow')}
          label={__('Let WordPress decide as usual (recommended) — the comment is re-checked automatically within 20 minutes and moved to Spam if needed', 'spamlens')} />
        <Choice type="radio" name="spamlens_on_error" value="hold" checked={values.on_error === 'hold'} onChange={() => set('on_error', 'hold')}
          label={__('Hold the comment for moderation', 'spamlens')} />
      </Field>

      <Field label={__('About this site', 'spamlens')} htmlFor="spamlens_site_context"
        help={__('Sent with every check. Describe what the site is about, who comments, and which languages are expected — this helps Jev most on non-English sites.', 'spamlens')}>
        <Textarea id="spamlens_site_context" rows={3} maxLength={2000} value={values.site_context}
          placeholder={__('e.g. A German-language cooking blog for home cooks; readers often share links to their own recipes; product reviews are in English.', 'spamlens')}
          onChange={(e) => set('site_context', e.target.value)} />
        <span className="text-[12px] text-mute">{sprintf(
          /** translators: 1: characters used, 2: maximum */
          __('%1$d of %2$d characters', 'spamlens'), values.site_context.length, 2000)}</span>
      </Field>

      <Field label={__('Page context', 'spamlens')} legend
        help={__('The text of password-protected, private and unpublished pages is never sent at any level; the parent comment of a reply always is. Developers can adjust or blank the page context with the spamlens_post_context filter.', 'spamlens')}>
        {payload.pageContext.map((level) => (
          <Choice key={level.id} type="radio" name="spamlens_page_context" value={level.id} checked={values.page_context === level.id}
            onChange={() => set('page_context', level.id)} label={<strong className="font-medium">{level.label}</strong>} description={level.help} />
        ))}
      </Field>

      <Field label={__('Skip checks for', 'spamlens')} legend
        help={__('Comments matching the Disallowed Comment Keys list and comments already flagged by another spam plugin are never sent.', 'spamlens')}>
        <Choice label={__('Users who can moderate comments', 'spamlens')} checked={values.skip_moderators} onChange={(e) => set('skip_moderators', e.target.checked)} />
        <Choice label={__('Authors with a previously approved comment', 'spamlens')} checked={values.skip_previously_approved} onChange={(e) => set('skip_previously_approved', e.target.checked)} />
        <Choice label={__('Do check pingbacks and trackbacks', 'spamlens')} checked={values.check_pingbacks} onChange={(e) => set('check_pingbacks', e.target.checked)} />
      </Field>
    </Section>
  );
}

function Privacy({ values, set }: { values: SettingsValues; set: SetValue }) {
  return (
    <Section id="spamlens-privacy" title={__('Privacy', 'spamlens')}
      intro={__('Every check sends the submission text, the author name and website, and details of the page it belongs to (title, type, tags and categories and, depending on the Page context setting, an excerpt and headings; for replies, the parent comment) to the selected provider. Nothing else is stored from the response except the probabilities.', 'spamlens')}>
      <Field label={__('Also send', 'spamlens')} legend>
        <Choice label={__('Author email address (helps with repeat offenders)', 'spamlens')} checked={values.send_email} onChange={(e) => set('send_email', e.target.checked)} />
        <Choice label={__('IP address', 'spamlens')} checked={values.send_ip} onChange={(e) => set('send_ip', e.target.checked)} />
        <Choice label={__('Browser user agent and referer (strong bot signal)', 'spamlens')} checked={values.send_user_agent} onChange={(e) => set('send_user_agent', e.target.checked)} />
      </Field>
      <Field label={__('Comment form notice', 'spamlens')} legend
        help={<>{__('Suggested privacy-policy wording is available in the Policy Guide.', 'spamlens')} <a href={adminSettings().urls.privacyGuide}>{__('Open the Policy Guide', 'spamlens')}</a></>}>
        <Choice label={__('Show "This site uses Jev by TypeSafe AI to reduce spam" under the comment form, linking to your privacy policy', 'spamlens')}
          checked={values.privacy_notice} onChange={(e) => set('privacy_notice', e.target.checked)} />
      </Field>
    </Section>
  );
}

function Integrations({ payload, values, set }: { payload: SettingsPayload; values: SettingsValues; set: SetValue }) {
  return (
    <Section id="spamlens-integrations" title={__('Integrations', 'spamlens')}
      intro={__('Choose which submissions Jev checks. A plugin that is not installed keeps its setting for when it comes back.', 'spamlens')}>
      <Field label={__('Check submissions from', 'spamlens')} legend>
        {payload.integrations.map((i) => (
          <Choice key={i.id} checked={!!values.integrations[i.id]} disabled={!i.available}
            onChange={(e) => set('integrations', { ...values.integrations, [i.id]: e.target.checked })}
            label={<>{i.label}{!i.available && <em className="text-mute"> {__('(not installed)', 'spamlens')}</em>}</>} />
        ))}
      </Field>
    </Section>
  );
}
