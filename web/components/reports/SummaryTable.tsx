import { Table, Td } from "@/components/ui/Table";
import { minutes, yen } from "@/lib/format";
import type { MonthlyReport } from "@/lib/schemas/report";

// ワーカー別・拠点別の集計表。最後の行に合計を出す（合計は API の total をそのまま使い、web で足さない）

/** 回数・分・金額の形は API のスキーマから導出する（手で書かない） */
type Row = MonthlyReport["total"] & { key: number; name: string };

type SummaryTableProps = {
  /** 1 列目の見出し（「ワーカー」「拠点」） */
  nameLabel: string;
  rows: Row[];
  total: MonthlyReport["total"];
  empty: string;
};

export function SummaryTable({
  nameLabel,
  rows,
  total,
  empty,
}: SummaryTableProps) {
  return (
    <Table head={[nameLabel, "勤務回数", "労働時間", "報酬"]} empty={empty}>
      {rows.length === 0
        ? null
        : [
            ...rows.map((row) => (
              <tr key={row.key}>
                <Td>{row.name}</Td>
                <Td align="right">{row.shifts}回</Td>
                <Td align="right">{minutes(row.worked_minutes)}</Td>
                <Td align="right">{yen(row.total_pay)}</Td>
              </tr>
            )),
            <tr key="total" className="bg-gray-50 font-medium">
              <Td>合計</Td>
              <Td align="right">{total.shifts}回</Td>
              <Td align="right">{minutes(total.worked_minutes)}</Td>
              <Td align="right">{yen(total.total_pay)}</Td>
            </tr>,
          ]}
    </Table>
  );
}
