"use client";

import Link from "next/link";
import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import type { FormState } from "@/lib/form-state";
import type { JobPosting } from "@/lib/schemas/job-posting";

// 募集の登録・編集フォーム（F-05）。action は createJobPosting か updateJobPosting.bind(null, id)。
// 422 の項目エラーは Field、項目に紐づかないエラー（中止した募集の編集など）は Alert（振り分けは Alert の中）。
// 必須項目に HTML の required を付けないのは、必須のエラーを API の 422 で入力欄の下に出すため

type LocationOption = { value: number; label: string };

type JobPostingFormProps = {
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  /** 拠点の選択肢（GET /api/locations の結果） */
  locations: LocationOption[];
  /** 編集のときの元の値 */
  posting?: JobPosting;
  submitLabel: string;
  /** API の can.edit。false なら送信ボタンを非活性にする（最終的な判定は API の 422） */
  editable?: boolean;
};

export function JobPostingForm({
  action,
  locations,
  posting,
  submitLabel,
  editable = true,
}: JobPostingFormProps) {
  const [state, formAction, pending] = useActionState(action, {});
  // 422 のあと React がフォームをリセットするので、送った値（values）で戻す
  const value = (name: string, original?: string | number) =>
    state.values?.[name] ?? original;

  return (
    <form action={formAction} className="max-w-xl space-y-4">
      <Alert state={state} />
      <Field
        label="拠点"
        name="location_id"
        type="select"
        options={locations}
        emptyOption="選択してください"
        errors={state.errors?.location_id}
        defaultValue={value("location_id", posting?.location.id)}
      />
      <Field
        label="職種名"
        name="job_title"
        errors={state.errors?.job_title}
        defaultValue={value("job_title", posting?.job_title)}
      />
      <Field
        label="勤務日"
        name="work_date"
        type="date"
        errors={state.errors?.work_date}
        defaultValue={value("work_date", posting?.work_date)}
      />
      <div className="grid grid-cols-2 gap-4">
        <Field
          label="開始時刻"
          name="start_time"
          type="time"
          errors={state.errors?.start_time}
          defaultValue={value("start_time", posting?.start_time)}
        />
        <Field
          label="終了時刻"
          name="end_time"
          type="time"
          errors={state.errors?.end_time}
          defaultValue={value("end_time", posting?.end_time)}
        />
      </div>
      <Field
        label="募集人数（名）"
        name="capacity"
        type="number"
        errors={state.errors?.capacity}
        defaultValue={value("capacity", posting?.capacity)}
      />
      <div className="grid grid-cols-2 gap-4">
        <Field
          label="時給（円）"
          name="hourly_wage"
          type="number"
          errors={state.errors?.hourly_wage}
          defaultValue={value("hourly_wage", posting?.hourly_wage)}
        />
        <Field
          label="交通費（円）"
          name="transport_allowance"
          type="number"
          placeholder="空欄なら 0円"
          errors={state.errors?.transport_allowance}
          defaultValue={value(
            "transport_allowance",
            posting?.transport_allowance,
          )}
        />
      </div>
      <div className="flex items-center gap-3">
        <Button pending={pending} pendingLabel="保存中…" disabled={!editable}>
          {submitLabel}
        </Button>
        <Link
          href="/job-postings"
          className="text-sm whitespace-nowrap text-gray-600 hover:text-gray-900"
        >
          一覧に戻る
        </Link>
      </div>
    </form>
  );
}
