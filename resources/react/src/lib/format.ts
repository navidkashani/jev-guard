import { adminSettings } from '@/settings';

/** A whole number in the admin's locale (its digits and separators). */
export function fmt(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—';
  try {
    return new Intl.NumberFormat(adminSettings().locale, { maximumFractionDigits: 0 }).format(n);
  } catch {
    return String(n);
  }
}

/** A share as a percentage: 0.873 → "87%" (one decimal when asked). */
export function pct(share: number, decimals = 0): string {
  try {
    return new Intl.NumberFormat(adminSettings().locale, { style: 'percent', maximumFractionDigits: decimals }).format(share);
  } catch {
    return (Math.round(share * 10 ** (decimals + 2)) / 10 ** decimals) + '%';
  }
}

/** A threshold: 0.85 → "0.85". */
export function threshold(n: number): string {
  return n.toFixed(2);
}
