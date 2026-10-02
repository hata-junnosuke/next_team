import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import { Table, Td } from "@/components/ui/Table";
import { dateTime } from "@/lib/format";
import type { ReportExport } from "@/lib/schemas/report-export";
import { isFailedExportStatus, isInProgressExportStatus } from "@/lib/status";

// 非同期出力の一覧の表（F-23）。表示は API の値をそのまま出す（ラベルは API の label、色は lib/status.ts）

type ExportTableProps = {
  exports: ReportExport[];
};

export function ExportTable({ exports }: ExportTableProps) {
  return (
    <Table
      head={["対象月", "状態", "進捗", "行数", "依頼日時", "完了日時", ""]}
      empty="出力の依頼はまだありません。"
    >
      {exports.map((exp) => (
        <tr key={exp.id}>
          <Td>{exp.month}</Td>
          <Td>
            <Badge kind="reportExportStatus" status={exp.status} />
          </Td>
          {/* progress が意味を持つのは生成中だけ（failed は止まった時点の値なので出さない） */}
          <Td align="right">
            {isInProgressExportStatus(exp.status.value)
              ? `${exp.progress}%`
              : "—"}
          </Td>
          <Td align="right">
            {exp.row_count === null
              ? "—"
              : `${exp.row_count.toLocaleString("ja-JP")}行`}
          </Td>
          <Td>{dateTime(exp.requested_at)}</Td>
          <Td>
            {exp.completed_at === null ? "—" : dateTime(exp.completed_at)}
          </Td>
          <Td>
            {/* download_url は completed のときだけ API が入れる。署名付き URL をブラウザで直接開く
                （Cookie を使わないので lib/api.ts を通さない。docs/01 6.9 の例外）。
                新しいタブで開き、開いた先から元の画面を操作させない（noopener）・URL を Referer で渡さない（noreferrer） */}
            {exp.download_url !== null && (
              <a
                href={exp.download_url}
                target="_blank"
                rel="noopener noreferrer"
                className="font-medium whitespace-nowrap text-blue-600 hover:underline"
              >
                ダウンロード
              </a>
            )}
            {/* 失敗した月は新しく依頼し直せる（docs/01 4.1。受け付けるかの判定は API の 422） */}
            {isFailedExportStatus(exp.status.value) && (
              <Link
                href={`/reports/monthly?month=${encodeURIComponent(exp.month)}`}
                className="font-medium whitespace-nowrap text-blue-600 hover:underline"
              >
                この月をもう一度依頼
              </Link>
            )}
          </Td>
        </tr>
      ))}
    </Table>
  );
}
