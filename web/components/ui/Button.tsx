import type { ReactNode } from "react";

// pending の間は無効にして文言を切り替える（二重送信を防ぐ）

type ButtonProps = {
  children: ReactNode;
  type?: "submit" | "button";
  variant?: "primary" | "secondary" | "danger";
  pending?: boolean;
  /** pending のときの文言 */
  pendingLabel?: string;
  disabled?: boolean;
  onClick?: () => void;
  name?: string;
  value?: string;
};

const variantClasses = {
  primary: "bg-blue-600 text-white hover:bg-blue-700 disabled:bg-blue-300",
  secondary:
    "border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 disabled:text-gray-400",
  danger: "bg-red-600 text-white hover:bg-red-700 disabled:bg-red-300",
};

export function Button({
  children,
  type = "submit",
  variant = "primary",
  pending = false,
  pendingLabel = "処理中…",
  disabled = false,
  onClick,
  name,
  value,
}: ButtonProps) {
  return (
    <button
      type={type}
      name={name}
      value={value}
      onClick={onClick}
      disabled={pending || disabled}
      aria-busy={pending || undefined}
      className={`inline-flex items-center rounded-md px-4 py-2 text-sm font-medium whitespace-nowrap shadow-xs disabled:cursor-not-allowed ${variantClasses[variant]}`}
    >
      {pending ? pendingLabel : children}
    </button>
  );
}
