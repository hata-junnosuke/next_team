"use client";

import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import type { FormState } from "@/lib/form-state";

// 勤怠の入力（F-15）。出勤・退勤は `HH:MM`、休憩は分。既存の勤怠があれば初期値にする。
// 深夜跨ぎ（退勤 <= 出勤なら翌日）・同時刻・休憩超過の判定と報酬の計算は API（web では書かない）。
// 422 の項目エラーは各 Field、確定済みでない応募などの業務ルール違反は Alert

type AttendanceFormProps = {
  /** Server Component で saveAttendance.bind(null, application.id, posting.id) にしたもの */
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  applicationId: number;
  /** 既存の勤怠（`HH:MM` と分）。未入力なら null */
  initial: {
    clock_in: string;
    clock_out: string;
    break_minutes: number;
  } | null;
};

export function AttendanceForm({
  action,
  applicationId,
  initial,
}: AttendanceFormProps) {
  const [state, formAction, pending] = useActionState(action, {});
  // 同じページに応募ごとのフォームが並ぶので、Field の id は応募の id で分ける
  const fieldId = (name: string) => `field-attendance-${applicationId}-${name}`;

  return (
    // 項目エラーが長くても横に伸びないよう、この中で折り返す
    <form action={formAction} className="max-w-3xl space-y-2 whitespace-normal">
      <div className="flex flex-wrap items-start gap-3">
        <div className="w-40">
          <Field
            label="出勤"
            name="clock_in"
            id={fieldId("clock_in")}
            type="time"
            errors={state.errors?.clock_in}
            defaultValue={state.values?.clock_in ?? initial?.clock_in}
          />
        </div>
        <div className="w-40">
          <Field
            label="退勤"
            name="clock_out"
            id={fieldId("clock_out")}
            type="time"
            errors={state.errors?.clock_out}
            defaultValue={state.values?.clock_out ?? initial?.clock_out}
          />
        </div>
        <div className="w-28">
          <Field
            label="休憩（分）"
            name="break_minutes"
            id={fieldId("break_minutes")}
            type="number"
            errors={state.errors?.break_minutes}
            defaultValue={state.values?.break_minutes ?? initial?.break_minutes}
          />
        </div>
        {/* ラベルの高さぶん下げてボタンを入力欄の行にそろえる */}
        <div className="pt-6">
          <Button pending={pending} pendingLabel="保存中…">
            {initial ? "勤怠を更新" : "勤怠を保存"}
          </Button>
        </div>
      </div>
      <Alert state={state} />
    </form>
  );
}
