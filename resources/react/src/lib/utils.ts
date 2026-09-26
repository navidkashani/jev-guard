import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Join class names, letting the later one win where two set the same property (shadcn's helper). */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
