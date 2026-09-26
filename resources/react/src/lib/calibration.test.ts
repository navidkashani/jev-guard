import { describe, expect, it } from 'vitest';
import { backoffDelay, decide, disagreements, suggest, tally, type CalibrationRow, type Policy } from './calibration';

const policy: Policy = { spam: 0.85, hold: 0.5, holdAbusive: true, maxLinks: 2 };

let next = 1;
function row(expected: 'spam' | 'ham', p: number, extra: Partial<CalibrationRow> = {}): CalibrationRow {
  return { id: next++, expected, title: '', author: '', link: '', p, genuine: 0.1, abuse: 0, links: 0, ...extra };
}

describe('decide', () => {
  it('follows the thresholds', () => {
    expect(decide(row('spam', 0.9), policy)).toBe('spam');
    expect(decide(row('spam', 0.6), policy)).toBe('hold');
    expect(decide(row('ham', 0.1), policy)).toBe('allow');
  });

  it('holds on-topic spam instead of spamming it', () => {
    expect(decide(row('spam', 0.95, { genuine: 0.7 }), policy)).toBe('hold');
  });

  it('spams link-heavy comments from 0.5 up when a link limit is set', () => {
    expect(decide(row('spam', 0.55, { links: 3 }), policy)).toBe('spam');
    expect(decide(row('spam', 0.55, { links: 3 }), { ...policy, maxLinks: 0 })).toBe('hold');
    expect(decide(row('spam', 0.4, { links: 3 }), policy)).toBe('allow');
  });

  it('holds abusive comments only when the option is on', () => {
    expect(decide(row('ham', 0.1, { abuse: 1.8 }), policy)).toBe('hold');
    expect(decide(row('ham', 0.1, { abuse: 1.8 }), { ...policy, holdAbusive: false })).toBe('allow');
  });

  it('treats a missing probability as zero', () => {
    expect(decide(row('ham', 0, { p: null }), policy)).toBe('allow');
  });
});

describe('tally', () => {
  it('counts every outcome and ignores errors', () => {
    const rows = [
      row('ham', 0.9), // false positive
      row('ham', 0.6), // held ham
      row('ham', 0.1), // correct
      row('spam', 0.95), // caught
      row('spam', 0.6), // held spam
      row('spam', 0.1), // missed
      row('spam', 0.99, { error: 'timeout' }),
    ];
    expect(tally(rows, policy)).toEqual({ fp: 1, heldHam: 1, missed: 1, heldSpam: 1, caught: 1, correct: 4 });
  });
});

describe('suggest', () => {
  it('returns null without scored rows', () => {
    expect(suggest([], policy)).toBeNull();
    expect(suggest([row('spam', 0.9, { error: 'x' })], policy)).toBeNull();
  });

  it('finds the lowest spam threshold without false positives', () => {
    const rows = [row('ham', 0.7), row('ham', 0.2), row('spam', 0.75), row('spam', 0.9)];
    const s = suggest(rows, policy)!;
    expect(s.fp).toBe(0);
    expect(s.spam).toBe(0.71);
    expect(s.caught).toBe(2);
  });

  it('keeps the fewest false positives when none is possible', () => {
    const rows = [row('ham', 0.99), row('spam', 0.98)];
    const s = suggest(rows, policy)!;
    expect(s.fp).toBe(1);
  });

  it('lowers the hold threshold under the lowest spam score when that is free', () => {
    const rows = [row('ham', 0.1), row('spam', 0.35), row('spam', 0.9)];
    const s = suggest(rows, policy)!;
    expect(s.hold).toBe(0.35);
    expect(s.fp).toBe(0);
  });
});

describe('disagreements', () => {
  it('lists missed or held spam and anything but allow for approved comments', () => {
    const rows = [row('ham', 0.1), row('ham', 0.6), row('spam', 0.95), row('spam', 0.2)];
    expect(disagreements(rows, policy).map((r) => r.p)).toEqual([0.6, 0.2]);
  });
});

describe('backoffDelay', () => {
  it('doubles from 5 s and caps at 60 s', () => {
    expect(backoffDelay(0)).toBe(5000);
    expect(backoffDelay(1)).toBe(10000);
    expect(backoffDelay(10)).toBe(60000);
  });

  it('honours Retry-After within the cap', () => {
    expect(backoffDelay(0, 20)).toBe(20000);
    expect(backoffDelay(0, 600)).toBe(60000);
    expect(backoffDelay(2, 1)).toBe(20000);
  });
});
