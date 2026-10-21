import type { ReactNode } from "react";

// 画面の見出し。左に見出しと補足、右に操作（登録ボタンなど）を置く

type PageHeaderProps = {
  title: ReactNode;
  /** 見出しの下の補足（集計時刻など） */
  description?: ReactNode;
  /** 右側の操作（例: buttonClassName("primary") の Link） */
  actions?: ReactNode;
};

export function PageHeader({ title, description, actions }: PageHeaderProps) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-4">
      <div className="space-y-1">
        <h1 className="text-ink text-xl font-semibold whitespace-nowrap">
          {title}
        </h1>
        {description && <p className="text-ink-muted text-sm">{description}</p>}
      </div>
      {actions && (
        <div className="flex flex-wrap items-center gap-2">{actions}</div>
      )}
    </div>
  );
}
