import * as React from 'react';
import { cn } from '@/lib/utils';

/** The app's card: white surface, 1px border, 12px corners; header and body paddings from globals.css. */
function Card({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card" className={cn('min-w-0 rounded-xl border border-border bg-surface', className)} {...props} />;
}

function CardHeader({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-header" className={cn('flex flex-wrap items-center justify-between gap-3 px-[22px] pt-[18px]', className)} {...props} />;
}

function CardTitle({ className, ...props }: React.ComponentProps<'h2'>) {
  return <h2 data-slot="card-title" className={cn('inline-flex items-center gap-2 text-[15px] font-semibold text-ink', className)} {...props} />;
}

function CardBody({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-body" className={cn('px-[22px] pb-[22px] pt-4', className)} {...props} />;
}

export { Card, CardHeader, CardTitle, CardBody };
