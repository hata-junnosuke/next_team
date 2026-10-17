import { Children, type ReactNode } from "react";

// 見出しと行の表。セルは折り返さない（折り返すと表が崩れるため）

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
    <div className="overflow-x-auto rounded-md border border-gray-200 bg-white">
      <table className="min-w-full divide-y divide-gray-200 text-sm">
        <thead className="bg-gray-50">
          <tr>
            {head.map((h, i) => (
              <th
                key={i}
                scope="col"
                className="px-4 py-2 text-left font-medium whitespace-nowrap text-gray-600"
              >
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100">
          {isEmpty ? (
            <tr>
              <td
                colSpan={head.length}
                className="px-4 py-6 text-center whitespace-nowrap text-gray-500"
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
      className={`px-4 py-2 whitespace-nowrap ${align === "right" ? "text-right tabular-nums" : ""}`}
    >
      {children}
    </td>
  );
}
