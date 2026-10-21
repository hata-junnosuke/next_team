import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import { buttonClassName } from "@/components/ui/Button";
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
          <Td>
            <span className="font-num text-base">{exp.month}</span>
          </Td>
          <Td>
            <Badge kind="reportExportStatus" status={exp.status} />
          </Td>
          {/* progress が意味を持つのは生成中だけ（failed は止まった時点の値なので出さない） */}
          <Td align="right">
            <span className="font-num text-base">
              {isInProgressExportStatus(exp.status.value)
                ? `${exp.progress}%`
                : "—"}
            </span>
          </Td>
          <Td align="right">
            <span className="font-num text-base">
              {exp.row_count === null
                ? "—"
                : `${exp.row_count.toLocaleString("ja-JP")}行`}
            </span>
          </Td>
          <Td>
            <span className="font-num text-base">
              {dateTime(exp.requested_at)}
            </span>
          </Td>
          <Td>
            <span className="font-num text-base">
              {exp.completed_at === null ? "—" : dateTime(exp.completed_at)}
            </span>
          </Td>
          <Td>
            <div className="flex items-center gap-2">
              {/* download_url は completed のときだけ API が入れる。署名付き URL をブラウザで直接開く
                  （Cookie を使わないので lib/api.ts を通さない。docs/01 の API 契約の非同期出力にある例外）。
                  新しいタブで開き、開いた先から元の画面を操作させない（noopener）・URL を Referer で渡さない（noreferrer） */}
              {exp.download_url !== null && (
                <a
                  href={exp.download_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={buttonClassName("secondary", "sm")}
                >
                  ダウンロード
                </a>
              )}
              {/* 失敗した月は新しく依頼し直せる（docs/01 の Enum。受け付けるかの判定は API の 422） */}
              {isFailedExportStatus(exp.status.value) && (
                <Link
                  href={`/reports/monthly?month=${encodeURIComponent(exp.month)}`}
                  className={buttonClassName("secondary", "sm")}
                >
                  この月をもう一度依頼
                </Link>
              )}
            </div>
          </Td>
        </tr>
      ))}
    </Table>
  );
}
