import type { ReactNode } from "react";

// 白い箱（surface + 罫線の ring + 角丸 10px）。絞り込みフォーム・登録フォーム・集計の区画に使う

type CardProps = {
  /** 箱の見出し（h2） */
  title?: ReactNode;
  children: ReactNode;
  /** 幅などの追加にだけ使う（例: "max-w-xl"）。余白や角丸の上書きには使わない */
  className?: string;
};

export function Card({ title, children, className = "" }: CardProps) {
  return (
    <section
      className={`bg-surface ring-line rounded-lg p-5 ring-1 ${className}`}
    >
      {title && (
        <h2 className="text-ink mb-4 text-sm font-semibold whitespace-nowrap">
          {title}
        </h2>
      )}
      {children}
    </section>
  );
}
