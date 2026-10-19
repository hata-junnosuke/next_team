import type { ReactNode } from "react";

// pending の間は無効にして文言を切り替える（二重送信を防ぐ）。
// 見た目は buttonClassName にまとめ、Link や a でも同じ見た目にできるようにする

type ButtonVariant = "primary" | "secondary" | "danger";
type ButtonSize = "sm" | "md";

type ButtonProps = {
  children: ReactNode;
  type?: "submit" | "button";
  variant?: ButtonVariant;
  /** 表の行内など狭い場所は "sm"。既定は "md"（h-9） */
  size?: ButtonSize;
  pending?: boolean;
  /** pending のときの文言 */
  pendingLabel?: string;
  disabled?: boolean;
  onClick?: () => void;
  name?: string;
  value?: string;
};

// フォーカスの印は sidebar 色（accent は主ボタンなど 3 か所だけに使う）
const baseClass =
  "inline-flex items-center justify-center rounded-md font-medium whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sidebar disabled:cursor-not-allowed disabled:opacity-50";

const variantClasses: Record<ButtonVariant, string> = {
  primary:
    "bg-accent text-accent-ink hover:bg-accent-hover disabled:hover:bg-accent",
  secondary:
    "bg-surface text-ink ring-1 ring-line ring-inset hover:bg-surface-muted disabled:hover:bg-surface",
  danger:
    "bg-danger text-surface hover:brightness-95 disabled:hover:brightness-100",
};

const sizeClasses: Record<ButtonSize, string> = {
  sm: "h-8 px-3 text-xs",
  md: "h-9 px-4 text-sm",
};

/** Button と同じ見た目のクラス。Link や a に付けて使う */
export function buttonClassName(
  variant: ButtonVariant = "primary",
  size: ButtonSize = "md",
): string {
  return `${baseClass} ${sizeClasses[size]} ${variantClasses[variant]}`;
}

export function Button({
  children,
  type = "submit",
  variant = "primary",
  size = "md",
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
      className={buttonClassName(variant, size)}
    >
      {pending ? pendingLabel : children}
    </button>
  );
}
