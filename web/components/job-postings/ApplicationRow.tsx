import { Fragment } from "react";
import { ActionButton } from "@/components/job-postings/ActionButton";
import { AttendanceForm } from "@/components/job-postings/AttendanceForm";
import { CancelApplicationForm } from "@/components/job-postings/CancelApplicationForm";
import { Badge } from "@/components/ui/Badge";
import { Td } from "@/components/ui/Table";
import {
  cancelApplication,
  confirmApplication,
  rejectApplication,
} from "@/lib/actions/application";
import { saveAttendance } from "@/lib/actions/attendance";
import { dateTime, minutes, time, yen } from "@/lib/format";
import type { Application } from "@/lib/schemas/application";
import type { Attendance } from "@/lib/schemas/attendance";
import { toneClasses } from "@/lib/status";

// 募集詳細の応募一覧の 1 行（Server Component）。応募の行と、勤怠を入力できる応募（can.attend）なら勤怠フォームの行。
// bind はここ（Server 側）で作る。ボタンを押せるかは API の can.* だけで決める（定員・遷移の判定は API の 422）

/** 表の見出し。勤怠フォームの行を横いっぱいに広げるのに列数も使う */
export const APPLICATION_HEAD = [
  "ワーカー",
  "状態",
  "確定日時",
  "確定時の時給",
  "確定時の交通費",
  "キャンセル理由",
  "出勤〜退勤",
  "休憩",
  "労働時間",
  "賃金",
  "交通費",
  "報酬合計",
  "操作",
];

/** 表の空欄 */
const BLANK = "—";

type ApplicationRowProps = {
  application: Application;
  postingId: number;
  /** 募集の確定数。承認・却下・キャンセルの key に入れ、どの行の操作でも再検証後に古い 422 を消す */
  confirmedCount: number;
};

/** 出勤〜退勤。深夜跨ぎ（API の is_overnight）は退勤に「翌」を付ける */
function workedRange(attendance: Attendance): string {
  const prefix = attendance.is_overnight ? "翌" : "";
  return `${time(attendance.clock_in)}〜${prefix}${time(attendance.clock_out)}`;
}

function attendanceInitial(attendance: Attendance | null) {
  return attendance
    ? {
        clock_in: time(attendance.clock_in),
        clock_out: time(attendance.clock_out),
        break_minutes: attendance.break_minutes,
      }
    : null;
}

export function ApplicationRow({
  application,
  postingId,
  confirmedCount,
}: ApplicationRowProps) {
  const a = application.attendance;
  // 勤怠の後にキャンセルされた応募は、勤怠の行が残るが月次集計に含めない（docs/01 の API 契約の勤怠・報酬）。
  // 判定は API の状態 value だけで行い、金額は API の値をそのまま出す
  const excluded = a !== null && application.status.value !== "confirmed";
  // サーバーの状態が変わったら（再検証で）フォームを作り直し、古い 422 の表示を消す
  const stateKey = `${application.status.value}-${confirmedCount}`;
  const name = application.worker.name;

  return (
    <Fragment>
      <tr>
        <Td>{name}</Td>
        <Td>
          <Badge kind="application" status={application.status} />
        </Td>
        <Td>
          {application.confirmed_at
            ? dateTime(application.confirmed_at)
            : BLANK}
        </Td>
        <Td align="right">
          {application.confirmed_hourly_wage === null
            ? BLANK
            : yen(application.confirmed_hourly_wage)}
        </Td>
        <Td align="right">
          {application.confirmed_transport_allowance === null
            ? BLANK
            : yen(application.confirmed_transport_allowance)}
        </Td>
        <Td>
          {application.cancel_reason ? (
            <Badge kind="cancelReason" status={application.cancel_reason} />
          ) : (
            BLANK
          )}
        </Td>
        <Td>
          {a ? (
            <span className="inline-flex items-center gap-2">
              {workedRange(a)}
              {a.is_overnight && (
                // Badge と同じ形（丸い点 + 文字）。accent の文字は白地では読みにくいので、
                // デザイン節の「深夜跨ぎの印の地」の sidebar 色の上に置く
                <span className="bg-sidebar text-accent inline-flex items-center gap-1.5 rounded-sm px-1.5 py-0.5 text-xs font-medium whitespace-nowrap">
                  <span
                    aria-hidden="true"
                    className="size-2 shrink-0 rounded-full bg-current"
                  />
                  深夜跨ぎ
                </span>
              )}
            </span>
          ) : (
            BLANK
          )}
        </Td>
        <Td align="right">{a ? `${a.break_minutes}分` : BLANK}</Td>
        <Td align="right">{a ? minutes(a.worked_minutes) : BLANK}</Td>
        <Td align="right">{a ? yen(a.wage_pay) : BLANK}</Td>
        <Td align="right">{a ? yen(a.transport_allowance) : BLANK}</Td>
        <Td align="right">
          {a ? (
            <span className="inline-flex items-center gap-2">
              {excluded && (
                // Badge と同じ形（丸い点 + 文字色）。灰色のトーン
                <span
                  className={`inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap ${toneClasses.gray}`}
                >
                  <span
                    aria-hidden="true"
                    className="size-2 shrink-0 rounded-full bg-current"
                  />
                  集計対象外
                </span>
              )}
              <span
                className={
                  excluded ? "text-ink-muted line-through" : "font-semibold"
                }
              >
                {yen(a.total_pay)}
              </span>
            </span>
          ) : (
            BLANK
          )}
        </Td>
        <Td>
          <div className="flex items-start gap-2">
            <ActionButton
              key={`confirm-${stateKey}`}
              action={confirmApplication.bind(null, application.id, postingId)}
              label="承認"
              pendingLabel="承認中…"
              variant="primary"
              size="sm"
              disabled={!application.can.confirm}
            />
            <ActionButton
              key={`reject-${stateKey}`}
              action={rejectApplication.bind(null, application.id, postingId)}
              label="却下"
              pendingLabel="却下中…"
              size="sm"
              disabled={!application.can.reject}
              confirmMessage={`${name}さんの応募を却下します。よろしいですか？`}
            />
            <CancelApplicationForm
              key={`cancel-${stateKey}`}
              action={cancelApplication.bind(null, application.id, postingId)}
              applicationId={application.id}
              workerName={name}
              canCancel={application.can.cancel}
            />
          </div>
        </Td>
      </tr>
      {application.can.attend && (
        // 勤怠の入力行。応募の行と区別するため、surface-muted の地と左の線（sidebar 色）を付ける
        <tr className="bg-surface-muted">
          <td
            colSpan={APPLICATION_HEAD.length}
            className="border-sidebar border-l-4 px-4 py-3"
          >
            <p className="text-ink mb-2 text-xs font-semibold whitespace-nowrap">
              勤怠の入力（{name}）
            </p>
            <AttendanceForm
              // 状態が変わったら作り直す。保存した値は再検証後の defaultValue とフォームのリセットで入力欄に入る
              key={`attendance-${application.status.value}`}
              action={saveAttendance.bind(null, application.id, postingId)}
              applicationId={application.id}
              initial={attendanceInitial(a)}
            />
          </td>
        </tr>
      )}
    </Fragment>
  );
}
