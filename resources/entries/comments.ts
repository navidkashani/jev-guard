/**
 * The comments list and the comment edit screen: "Re-check with SpamLens" (row action and meta-box button) and "Check for
 * Spam" on the Pending view. Markup comes from src/Service/Admin/CommentsScreen.php; requests go to the REST routes in
 * src/Service/Rest/RestController.php. WordPress provides wp.i18n and wp.apiFetch (with the REST nonce).
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { sleep } from '../react/src/lib/async';
import { backoffDelay } from '../react/src/lib/calibration';
import { errorText } from '../react/src/lib/api';

const NS = '/spamlens/v1';

type RecheckResult = { html: string; decision: string; label: string; moved: boolean; status: string };
type Halt = { code: string; message: string; retryAfter?: number; fatal: boolean };
type BatchResult = { processed: number; spam: number; errors: number; lastId: number; total: number; done: boolean; halt: Halt | null };
type RestError = { code?: string; message?: string; data?: { label?: string; html?: string } };

/** Status text next to a button: busy (grey italic), ok (green) or error (red), read out by screen readers. */
function setStatus(el: Element | null, text: string, tone: '' | 'busy' | 'ok' | 'error' = '') {
  if (!el) return;
  el.textContent = text;
  el.classList.remove('is-busy', 'is-ok', 'is-error');
  if (tone) el.classList.add('is-' + tone);
}

/** A status element after the row action (the list has none of its own), created on first use. */
function inlineStatus(link: HTMLElement): HTMLElement {
  const holder = link.parentElement ?? link;
  let el = holder.querySelector<HTMLElement>('.spamlens-inline-result');
  if (!el) {
    el = document.createElement('span');
    el.className = 'spamlens-inline-result';
    el.setAttribute('aria-live', 'polite');
    holder.appendChild(el);
  }
  return el;
}

async function recheck(link: HTMLElement) {
  if (link.dataset.busy) return;
  const id = parseInt(link.dataset.commentId ?? '', 10);
  if (!id) return;
  const row = link.closest('tr');
  const cell = row?.querySelector<HTMLElement>('.spamlens-cell') ?? null;
  const status = inlineStatus(link);
  const original = link.textContent ?? '';

  link.dataset.busy = '1';
  link.textContent = __('Re-checking…', 'spamlens');
  link.setAttribute('aria-busy', 'true');
  cell?.classList.add('is-busy');
  setStatus(status, '');

  try {
    const data = await apiFetch<RecheckResult>({ path: `${NS}/comments/${id}/recheck`, method: 'POST' });
    if (link.dataset.reload) {
      window.location.reload();
      return;
    }
    if (cell) cell.outerHTML = data.html;
    if (data.moved && row) {
      row.classList.add('spamlens-row-moved');
      setStatus(status, __('Moved to Spam', 'spamlens'), 'error');
      window.setTimeout(() => {
        row.style.transition = 'opacity .6s';
        row.style.opacity = '0.3';
      }, 1500);
    }
  } catch (e) {
    const html = (e as RestError | undefined)?.data?.html;
    if (html && cell) cell.outerHTML = html;
    else cell?.classList.remove('is-busy');
    setStatus(status, sprintf(
      /** translators: %s: error message */
      __('Error: %s', 'spamlens'), errorText(e, __('Request failed', 'spamlens'))), 'error');
  } finally {
    delete link.dataset.busy;
    link.textContent = original;
    link.removeAttribute('aria-busy');
  }
}

async function checkPending(button: HTMLButtonElement) {
  const status = document.getElementById('spamlens-bulk-status');
  const token = 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  let after = 0;
  let total = 0;
  let checked = 0;
  let spam = 0;
  let attempt = 0;

  button.disabled = true;
  setStatus(status, __('Checking pending comments…', 'spamlens'), 'busy');
  try {
    for (;;) {
      const data = await apiFetch<BatchResult>({ path: `${NS}/comments/check-pending`, method: 'POST', data: { after, token } });
      if (after === 0) total = data.total || 0;
      checked += data.processed || 0;
      spam += data.spam || 0;
      after = data.lastId || after;

      if (data.halt) {
        if (data.halt.fatal) {
          setStatus(status, sprintf(
            /** translators: %s: error message */
            __('Error: %s', 'spamlens'), data.halt.message), 'error');
          return;
        }
        const wait = backoffDelay(attempt++, data.halt.retryAfter);
        setStatus(status, sprintf(
          /** translators: %d: seconds */
          __('Provider rate limit — pausing for %d s…', 'spamlens'), Math.round(wait / 1000)), 'busy');
        await sleep(wait);
        continue;
      }
      attempt = 0;

      if (data.done) {
        if (total === 0 && checked === 0) {
          setStatus(status, __('No pending comments to check.', 'spamlens'), 'ok');
        } else {
          setStatus(status, sprintf(
            /** translators: 1: number checked, 2: number moved to spam */
            __('Done: %1$d checked, %2$d moved to Spam. Reload to see the changes.', 'spamlens'), checked, spam), 'ok');
        }
        return;
      }
      setStatus(status, sprintf(
        /** translators: 1: number checked, 2: total, 3: number moved to spam */
        __('%1$d of %2$d checked, %3$d moved to Spam', 'spamlens'), checked, total, spam), 'busy');
    }
  } catch (e) {
    setStatus(status, sprintf(
      /** translators: %s: error message */
      __('Error: %s', 'spamlens'), errorText(e, __('Request failed', 'spamlens'))), 'error');
  } finally {
    button.disabled = false;
  }
}

document.addEventListener('click', (event) => {
  const target = event.target as HTMLElement | null;
  const link = target?.closest<HTMLElement>('.spamlens-recheck');
  if (link) {
    event.preventDefault();
    void recheck(link);
    return;
  }
  const bulk = target?.closest<HTMLButtonElement>('#spamlens-check-all');
  if (bulk && !bulk.disabled) {
    event.preventDefault();
    void checkPending(bulk);
  }
});
