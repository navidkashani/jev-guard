import apiFetch from '@wordpress/api-fetch';
import type { CalibrationRow, Expected } from '@/lib/calibration';

/** The REST routes under spamlens/v1 (src/Service/Rest/RestController.php). */
const NS = '/spamlens/v1';

export type PageContext = 'title' | 'standard' | 'extended';

/** The saved settings, without the API key (the server never sends it back). */
export type SettingsValues = {
  provider: string;
  model: string;
  custom_endpoint: string;
  spam_threshold: number;
  hold_threshold: number;
  hold_abusive: boolean;
  on_error: 'allow' | 'hold';
  site_context: string;
  page_context: PageContext;
  send_email: boolean;
  send_ip: boolean;
  send_user_agent: boolean;
  skip_moderators: boolean;
  skip_previously_approved: boolean;
  check_pingbacks: boolean;
  privacy_notice: boolean;
  integrations: Record<string, boolean>;
  timeout: number;
};

export type Provider = { id: string; label: string; model: string; endpoint: string; note: string; keysUrl: string; freeUrl: string };

export type SettingsPayload = {
  values: SettingsValues;
  key: { stored: boolean; hint: string; constant: boolean };
  providers: Provider[];
  integrations: { id: string; label: string; available: boolean }[];
  pageContext: { id: PageContext; label: string; help: string }[];
  maxLinks: number;
  /** Versioned model ids that have answered on this site, per provider id, newest first. */
  models: Record<string, string[]>;
  lastError: null | { code: string; label: string; message: string; time: number };
};

export type StatKey = 'checked' | 'spam' | 'held' | 'errors' | 'fp' | 'fn';

export type StatsPayload = {
  since: number;
  sinceLabel: string;
  totals: Record<StatKey, number>;
  integrations: Record<string, Record<StatKey, number>>;
  columns: { id: string; label: string }[];
};

export type TestResult = { model: string; latencyMs: number; probability: number; category: string; decision: string };

export type Halt = { code: string; label: string; message: string; retryAfter: number };

/** What the settings form sends: the values plus, only when typed, a new key (or the request to remove it). */
export type SettingsInput = SettingsValues & { api_key?: string; clear_api_key?: boolean };

export type ConnectionInput = Pick<SettingsInput, 'provider' | 'api_key' | 'clear_api_key' | 'model' | 'custom_endpoint' | 'timeout'>;

export const api = {
  settings: () => apiFetch<SettingsPayload>({ path: `${NS}/settings` }),
  save: (settings: SettingsInput) => apiFetch<SettingsPayload>({ path: `${NS}/settings`, method: 'POST', data: { settings } }),
  test: (connection: ConnectionInput) => apiFetch<TestResult>({ path: `${NS}/test`, method: 'POST', data: connection }),
  sample: (n: number) => apiFetch<{ spam: number[]; ham: number[] }>({ path: `${NS}/calibration/sample`, method: 'POST', data: { n } }),
  batch: (items: { id: number; expected: Expected }[]) => apiFetch<{ results: CalibrationRow[]; halt: Halt | null }>({ path: `${NS}/calibration/batch`, method: 'POST', data: { items } }),
  stats: () => apiFetch<StatsPayload>({ path: `${NS}/stats` }),
  resetStats: () => apiFetch<StatsPayload>({ path: `${NS}/stats/reset`, method: 'POST' }),
};

/** apiFetch rejects with the REST error body: "Label — message" when the server sent a label, else the message. */
export function errorText(e: unknown, fallback: string): string {
  const err = e as { message?: string; data?: { label?: string } } | undefined;
  const message = typeof err?.message === 'string' && err.message ? err.message : fallback;
  const label = err?.data?.label;
  return label && label !== message ? `${label} — ${message}` : message;
}
