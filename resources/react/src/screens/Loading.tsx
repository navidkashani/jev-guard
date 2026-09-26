import { __ } from '@wordpress/i18n';
import { NoticeBox } from '@/shell/Shell';

/** Shown in a screen's body until the shared data is there (only when the page came without it). */
export function Loading({ error }: { error: string }) {
  if (error) return <NoticeBox notice={{ type: 'error', text: error }} />;
  return (
    <div className="flex items-center gap-2 text-ink2" role="status">
      <span className="spin" aria-hidden="true" />
      {__('Loading…', 'spamlens')}
    </div>
  );
}
