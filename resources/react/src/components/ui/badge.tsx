import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

/** Small labelled items: badge (20px, square corners) and pill (22px, round) from the VeronaLabs admin apps. */
const badgeVariants = cva('inline-flex shrink-0 items-center whitespace-nowrap font-medium', {
  variants: {
    variant: {
      badge: 'h-5 gap-[5px] rounded-sm bg-surface2 px-[7px] text-[11px] text-ink2',
      warn: 'h-5 gap-[5px] rounded-sm bg-surface2 px-[7px] text-[11px] text-warn',
      pill: 'h-[22px] gap-[6px] rounded-full bg-surface2 ps-[7px] pe-2 text-[12px] text-ink',
    },
  },
  defaultVariants: { variant: 'badge' },
});

function Badge({ className, variant, ...props }: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants>) {
  return <span data-slot="badge" className={cn(badgeVariants({ variant }), className)} {...props} />;
}

/** The 8px status dot inside a pill. */
function Dot({ tone = 'muted' }: { tone?: 'good' | 'warn' | 'critical' | 'muted' }) {
  const color = { good: 'bg-good', warn: 'bg-warn', critical: 'bg-critical', muted: 'bg-mute' }[tone];
  return <span className={cn('inline-block h-2 w-2 shrink-0 rounded-full', color)} />;
}

export { Badge, Dot, badgeVariants };
