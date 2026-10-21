import { Children, type ReactNode } from "react";

// 見出しと行の表。セルは折り返さない（折り返すと表が崩れるため）。
// 外枠はカード型、見出し行は sidebar 色の地（デザイン節の配色）

type TableProps = {
  head: ReactNode[];
  children: ReactNode;
  /** 行が 0 件のときに出す文言 */
  empty?: string;
};

export function Table({
  head,
  children,
  empty = "データがありません。",
}: TableProps) {
  const isEmpty = Children.count(children) === 0;
  return (
    <div className="bg-surface ring-line overflow-x-auto rounded-lg ring-1">
      <table className="text-ink min-w-full text-sm">
        <thead className="bg-sidebar">
          <tr>
            {head.map((h, i) => (
              <th
                key={i}
                scope="col"
                className="text-sidebar-ink px-4 py-2.5 text-left text-xs font-medium whitespace-nowrap"
              >
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody
          className={`divide-line divide-y ${isEmpty ? "" : "[&>tr:hover]:bg-surface-muted [&>tr]:transition-colors"}`}
        >
          {isEmpty ? (
            <tr>
              <td
                colSpan={head.length}
                className="text-ink-muted px-4 py-8 text-center whitespace-nowrap"
              >
                {empty}
              </td>
            </tr>
          ) : (
            children
          )}
        </tbody>
      </table>
    </div>
  );
}

type CellProps = {
  children?: ReactNode;
  align?: "left" | "right";
};

/** 表のセル。金額や数値は align="right" */
export function Td({ children, align = "left" }: CellProps) {
  return (
    <td
      className={`px-4 py-2.5 whitespace-nowrap ${align === "right" ? "text-right tabular-nums" : ""}`}
    >
      {children}
    </td>
  );
}
