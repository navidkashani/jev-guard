import * as React from 'react';
import { cn } from '@/lib/utils';

/** shadcn's Input at the app's 40px height. */
function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        'flex h-10 w-full max-w-[440px] rounded-lg border border-input bg-surface px-[14px] text-[13px] text-ink outline-none placeholder:text-mute focus:border-brand disabled:opacity-55',
        className,
      )}
      {...props}
    />
  );
}

export { Input };
