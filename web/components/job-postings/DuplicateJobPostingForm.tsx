"use client";

import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import type { FormState } from "@/lib/form-state";

// 募集の複製（F-08）。勤務日だけを入れて送り、成功すると新しい募集の詳細へ移る。
// 「元の募集と同じ日」「最低賃金未満」などの判定は API の 422。
// 最低賃金未満は errors.hourly_wage で返る（このフォームに時給の入力欄はない）ので、
// work_date 以外の項目エラーをまとめて出す（docs/01 の表示の規約の例外）

type DuplicateJobPostingFormProps = {
  /** Server Component で duplicateJobPosting.bind(null, posting.id) にしたもの */
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
};

export function DuplicateJobPostingForm({
  action,
}: DuplicateJobPostingFormProps) {
  const [state, formAction, pending] = useActionState(action, {});
  const otherErrors = Object.entries(state.errors ?? {})
    .filter(([name]) => name !== "work_date")
    .flatMap(([, messages]) => messages);

  return (
    <form action={formAction} className="space-y-4">
      <Alert state={state} />
      <Field
        label="新しい勤務日"
        name="work_date"
        // 同じページの編集フォームにも work_date があるので id を分ける
        id="field-duplicate-work_date"
        type="date"
        errors={state.errors?.work_date}
        defaultValue={state.values?.work_date}
      />
      {otherErrors.length > 0 && (
        <ul role="alert" className="text-danger space-y-0.5 text-sm">
          {otherErrors.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      )}
      <Button variant="secondary" pending={pending} pendingLabel="複製中…">
        この内容で複製する
      </Button>
    </form>
  );
}
