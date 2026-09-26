import * as React from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * A native select with the input's size and a chevron, so it reads as a menu and not a text box. WordPress's own
 * select arrow (a background image in forms.css) is removed; the chevron sits at the logical end, so RTL works.
 */
function Select({ className, wrapperClassName, ...props }: React.ComponentProps<'select'> & { wrapperClassName?: string }) {
  return (
    <div className={cn('relative w-full max-w-[440px]', wrapperClassName)}>
      <select
        data-slot="select"
        className={cn(
          'flex h-10 w-full max-w-none cursor-pointer appearance-none rounded-lg border border-input bg-surface bg-none ps-[14px] pe-10 text-[13px] leading-normal text-ink shadow-none outline-none hover:border-line focus:border-brand disabled:cursor-not-allowed disabled:opacity-55',
          className,
        )}
        {...props}
      />
      <ChevronDown size={16} aria-hidden="true" className="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-mute" />
    </div>
  );
}

/** A textarea with the input's look. */
function Textarea({ className, ...props }: React.ComponentProps<'textarea'>) {
  return (
    <textarea
      data-slot="textarea"
      className={cn('flex min-h-[84px] w-full max-w-[640px] rounded-lg border border-input bg-surface px-[14px] py-[10px] text-[13px] leading-[1.5] text-ink outline-none placeholder:text-mute focus:border-brand disabled:opacity-55', className)}
      {...props}
    />
  );
}

/**
 * One setting: label column and control column on wide screens, stacked under 960px. `htmlFor` ties the label to a
 * single control; a group of checkboxes or radios passes `legend` instead and is rendered as a fieldset.
 */
function Field({ label, htmlFor, help, children, legend = false }: { label: string; htmlFor?: string; help?: React.ReactNode; children: React.ReactNode; legend?: boolean }) {
  const body = (
    <div className="flex min-w-0 flex-col gap-2">
      {children}
      {help && <div className="max-w-[72ch] text-[12px] leading-[1.5] text-ink2">{help}</div>}
    </div>
  );
  if (legend) {
    return (
      // A legend does not take part in a grid, so it is read by screen readers and a copy is shown in the label column.
      <fieldset className="m-0 min-w-0 border-0 border-t border-border p-0 py-4 first:border-t-0 first:pt-0">
        <legend className="sr-only">{label}</legend>
        <div className="grid grid-cols-[200px_minmax(0,1fr)] gap-x-6 gap-y-2 max-[960px]:grid-cols-1">
          <span aria-hidden="true" className="pt-[2px] text-[13px] font-medium text-ink">{label}</span>
          {body}
        </div>
      </fieldset>
    );
  }
  return (
    <div className="grid grid-cols-[200px_minmax(0,1fr)] gap-x-6 gap-y-2 border-t border-border py-4 first:border-t-0 first:pt-0 max-[960px]:grid-cols-1">
      <label htmlFor={htmlFor} className="pt-[10px] text-[13px] font-medium text-ink max-[960px]:pt-0">{label}</label>
      {body}
    </div>
  );
}

/** A checkbox or radio with its label on the same line; the label wraps under itself, not under the box. */
function Choice({ type = 'checkbox', label, description, className, ...props }: Omit<React.ComponentProps<'input'>, 'type'> & { type?: 'checkbox' | 'radio'; label: React.ReactNode; description?: React.ReactNode }) {
  return (
    <label className={cn('flex cursor-pointer items-start gap-2.5 text-[13px] text-ink has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60', className)}>
      <input type={type} className="mt-[2px] h-4 w-4 shrink-0" {...props} />
      <span className="min-w-0">
        {label}
        {description && <span className="block text-[12px] leading-[1.5] text-ink2">{description}</span>}
      </span>
    </label>
  );
}

export { Select, Textarea, Field, Choice };
