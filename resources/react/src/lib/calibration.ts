/**
 * The calibration maths, kept free of React and WordPress so it can be tested on its own. It mirrors the plugin's tier
 * policy (Verdict::decide() in PHP) so other thresholds can be tried locally on the probabilities the model returned.
 */

export type Expected = 'spam' | 'ham';
export type Decision = 'spam' | 'hold' | 'allow';

/** One classified comment from POST /calibration/batch. */
export type CalibrationRow = {
  id: number;
  expected: Expected;
  title: string;
  author: string;
  link: string;
  p?: number | null;
  genuine?: number | null;
  abuse?: number | null;
  links?: number;
  category?: string;
  decision?: string;
  model?: string;
  error?: string;
};

export type Policy = { spam: number; hold: number; holdAbusive: boolean; maxLinks: number };

export type Tally = { fp: number; heldHam: number; missed: number; heldSpam: number; caught: number; correct: number };

export type Suggestion = { spam: number; hold: number; fp: number; caught: number };

/** The tier a row would get under a policy. */
export function decide(row: CalibrationRow, policy: Policy): Decision {
  const p = row.p ?? 0;
  if (policy.maxLinks > 0 && (row.links ?? 0) >= policy.maxLinks && p >= 0.5) return 'spam';
  if (p >= policy.spam) return row.genuine != null && row.genuine >= 0.5 ? 'hold' : 'spam';
  if (p >= policy.hold) return 'hold';
  if (policy.holdAbusive && row.abuse != null && row.abuse >= 1.5) return 'hold';
  return 'allow';
}

/** Rows the model scored (no error, a probability present). */
export function scoredRows(rows: CalibrationRow[]): CalibrationRow[] {
  return rows.filter((r) => !r.error && r.p != null);
}

/**
 * Counts against the moderator's decisions. Held comments count as correct either way: a person still decides.
 */
export function tally(rows: CalibrationRow[], policy: Policy): Tally {
  const t: Tally = { fp: 0, heldHam: 0, missed: 0, heldSpam: 0, caught: 0, correct: 0 };
  for (const r of scoredRows(rows)) {
    const d = decide(r, policy);
    if (r.expected === 'ham') {
      if (d === 'spam') t.fp++;
      else if (d === 'hold') { t.heldHam++; t.correct++; }
      else t.correct++;
    } else {
      if (d === 'spam') { t.caught++; t.correct++; }
      else if (d === 'hold') { t.heldSpam++; t.correct++; }
      else t.missed++;
    }
  }
  return t;
}

/**
 * The lowest spam threshold (0.50–0.99) with the fewest false positives, catching the most spam on a tie; then a hold
 * threshold just under the lowest spam score, when that does not add false positives. Null without scored rows.
 */
export function suggest(rows: CalibrationRow[], policy: Policy): Suggestion | null {
  const scored = scoredRows(rows);
  if (!scored.length) return null;
  let best: Suggestion | null = null;
  for (let t = 50; t <= 99; t++) {
    const spam = t / 100;
    const hold = Math.min(policy.hold, spam);
    const r = tally(scored, { ...policy, spam, hold });
    if (!best || r.fp < best.fp || (r.fp === best.fp && r.caught > best.caught)) best = { spam, hold, fp: r.fp, caught: r.caught };
  }
  const spamRows = scored.filter((r) => r.expected === 'spam');
  if (best && spamRows.length) {
    const minSpam = Math.min(...spamRows.map((r) => r.p ?? 0));
    const hold = Math.max(0.2, Math.min(best.spam - 0.05, Math.floor(minSpam * 100) / 100));
    const withHold = tally(scored, { ...policy, spam: best.spam, hold });
    if (withHold.fp === best.fp) best = { ...best, hold: Math.round(hold * 100) / 100, caught: withHold.caught };
  }
  return best;
}

/** Comments where the model, at the saved thresholds, disagrees with the moderator (held spam or anything but allow for approved). */
export function disagreements(rows: CalibrationRow[], policy: Policy): CalibrationRow[] {
  return scoredRows(rows).filter((r) => {
    const d = decide(r, policy);
    return (r.expected === 'spam' && d !== 'spam') || (r.expected === 'ham' && d !== 'allow');
  });
}

/** Exponential backoff for provider rate limits: 5 s doubling to 60 s, never shorter than Retry-After. In milliseconds. */
export function backoffDelay(attempt: number, retryAfter?: number): number {
  const base = Math.min(60000, 5000 * 2 ** attempt);
  if (retryAfter && retryAfter > 0) return Math.min(60000, Math.max(base, retryAfter * 1000));
  return base;
}
