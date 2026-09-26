import * as React from 'react';
import { Slot } from 'radix-ui';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

/** shadcn's Button with the app's variants: default (bordered), primary (ink), accent (blue), ghost. */
const buttonVariants = cva(
  'inline-flex items-center justify-center gap-[7px] whitespace-nowrap rounded-md border text-[13px] font-medium leading-none transition-[background-color,border-color,color,opacity] duration-100 disabled:pointer-events-none disabled:opacity-55 [&_svg]:pointer-events-none [&_svg]:shrink-0 cursor-pointer',
  {
    variants: {
      variant: {
        default: 'border-border bg-surface text-ink hover:bg-surface2 hover:border-line',
        primary: 'border-primary bg-primary text-primary-foreground hover:opacity-85',
        accent: 'border-brand bg-brand text-white hover:opacity-85',
        ghost: 'border-transparent bg-transparent text-ink2 hover:bg-surface3 hover:text-ink',
        danger: 'border-critical/50 bg-surface text-critical hover:border-critical hover:bg-critical hover:text-white',
        dangerSolid: 'border-critical bg-critical text-white hover:opacity-85',
      },
      size: {
        default: 'h-[34px] px-3',
        sm: 'h-7 px-[10px] text-[12px]',
        icon: 'h-8 w-8 px-0',
      },
    },
    defaultVariants: { variant: 'default', size: 'default' },
  },
);

/**
 * A button that is working (aria-busy) shows the app's small spinner in front of its label and ignores clicks; the label
 * stays, so the button keeps its width.
 */
function Button({ className, variant, size, asChild = false, children, ...props }: React.ComponentProps<'button'> & VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
  const busy = props['aria-busy'] === true || props['aria-busy'] === 'true';
  const classes = cn(buttonVariants({ variant, size, className }), busy && 'pointer-events-none opacity-85');
  if (asChild) {
    // A link styled as a button: Slot needs exactly one child, so no spinner here.
    return <Slot.Root data-slot="button" className={classes} {...props}>{children}</Slot.Root>;
  }
  return (
    <button data-slot="button" className={classes} {...props}>
      {busy && <span className="spin" aria-hidden="true" />}
      {children}
    </button>
  );
}

export { Button, buttonVariants };
