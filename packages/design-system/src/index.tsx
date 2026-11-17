import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";
import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
const buttonVariants = cva(
  "inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-2 disabled:pointer-events-none disabled:opacity-50",
  {
    variants: {
      variant: {
        default: "bg-teal-800 text-white hover:bg-teal-900",
        outline:
          "border border-slate-300 bg-white text-slate-800 hover:bg-slate-100",
      },
    },
    defaultVariants: { variant: "default" },
  },
);
export function Button({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "button";
  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, className }))}
      {...props}
    />
  );
}
export function Card({ className, ...props }: React.ComponentProps<"section">) {
  return (
    <section
      className={cn(
        "rounded-2xl border border-slate-200 bg-white p-6 shadow-sm",
        className,
      )}
      {...props}
    />
  );
}
