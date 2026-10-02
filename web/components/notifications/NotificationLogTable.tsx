import { Badge } from "@/components/ui/Badge";
import { Table, Td } from "@/components/ui/Table";
import { dateTime } from "@/lib/format";
import type { NotificationLog } from "@/lib/schemas/notification-log";

// 通知履歴の表（F-24）。表示は API の値をそのまま出す（ラベルは API の label、色は lib/status.ts）

type NotificationLogTableProps = {
  logs: NotificationLog[];
  /** 行が 0 件のときに出す文言 */
  empty: string;
};

export function NotificationLogTable({
  logs,
  empty,
}: NotificationLogTableProps) {
  return (
    <Table
      head={[
        "作成日時",
        "種別",
        "宛先",
        "対象",
        "状態",
        "送信日時",
        "失敗日時",
        "エラー",
      ]}
      empty={empty}
    >
      {logs.map((log) => (
        <tr key={log.id} className="align-top">
          <Td>{dateTime(log.created_at)}</Td>
          <Td>
            <Badge kind="notificationKind" status={log.kind} />
          </Td>
          <Td>
            <div>
              <span className="text-xs text-gray-500">
                {log.recipient.type.label}
              </span>{" "}
              {log.recipient.name}
            </div>
            <div className="text-xs text-gray-500">{log.recipient.email}</div>
          </Td>
          <Td>
            <span className="text-xs text-gray-500">
              {log.subject.type.label}
            </span>{" "}
            {log.subject.label}
          </Td>
          <Td>
            <Badge kind="notificationStatus" status={log.status} />
          </Td>
          <Td>{log.sent_at === null ? "—" : dateTime(log.sent_at)}</Td>
          <Td>{log.failed_at === null ? "—" : dateTime(log.failed_at)}</Td>
          {/* エラー列だけ折り返す（docs/01 7.5 の例外。例外メッセージは長くなる）。
              Td は折り返さない共通部品なので、ここだけ td を直接書く。中身は文字列として出す */}
          <td className="px-4 py-2 align-top">
            {/* td の max-width は表の自動レイアウトで効かないので、幅は中の div で決める。
                wrap-anywhere: 空白のない長い語（URL・クラス名など）も途中で折り返す */}
            <div className="max-w-md min-w-64 text-xs wrap-anywhere whitespace-pre-wrap text-red-700">
              {log.error ?? <span className="text-gray-500">—</span>}
            </div>
          </td>
        </tr>
      ))}
    </Table>
  );
}
