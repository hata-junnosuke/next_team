import Link from "next/link";
import type { PaginationMeta } from "@/lib/schemas/common";

// 一覧の meta を受けて前後のリンクを出す。検索条件（query）は引き継ぐ

type PaginationProps = {
  meta: PaginationMeta;
  /** 例: "/workers" */
  basePath: string;
  /** page 以外に URL に残したい条件（例: { q: "山田" }） */
  query?: Record<string, string | undefined>;
};

function href(
  basePath: string,
  page: number,
  query: PaginationProps["query"],
): string {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(query ?? {})) {
    if (v !== undefined && v !== "" && k !== "page") {
      params.set(k, v);
    }
  }
  params.set("page", String(page));
  return `${basePath}?${params.toString()}`;
}

const linkClass =
  "rounded-md border border-gray-300 bg-white px-3 py-1.5 whitespace-nowrap text-gray-700 hover:bg-gray-50";
const disabledClass =
  "rounded-md border border-gray-200 px-3 py-1.5 whitespace-nowrap text-gray-300";

export function Pagination({ meta, basePath, query }: PaginationProps) {
  const { current_page: current, last_page: last, total } = meta;
  return (
    <nav
      aria-label="ページ送り"
      className="flex items-center justify-between gap-4 text-sm"
    >
      <p className="whitespace-nowrap text-gray-600">
        全 {total} 件（{current} / {Math.max(last, 1)} ページ）
      </p>
      <div className="flex gap-2">
        {current > 1 ? (
          <Link href={href(basePath, current - 1, query)} className={linkClass}>
            前へ
          </Link>
        ) : (
          <span className={disabledClass}>前へ</span>
        )}
        {current < last ? (
          <Link href={href(basePath, current + 1, query)} className={linkClass}>
            次へ
          </Link>
        ) : (
          <span className={disabledClass}>次へ</span>
        )}
      </div>
    </nav>
  );
}
