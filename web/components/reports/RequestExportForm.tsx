"use client";

import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import type { FormState } from "@/lib/form-state";
import { requestReportExport } from "@/lib/actions/report-export";

// 月次集計の画面に置く「CSV を生成（非同期）」ボタン（F-23）。
// 成功（202）すると Server Action が出力一覧へ redirect する。
// 「同じ月の出力が生成中です。」（errors が空の 422）は Alert に出す。
// month の形の 422 は errors.month で返る（このフォームに入力欄はない）ので、フォームの下にまとめて出す（docs/01 7.5 の例外）

type RequestExportFormProps = {
  /** 表示中の対象月（`YYYY-MM`）。hidden で送る */
  month: string;
};

export function RequestExportForm({ month }: RequestExportFormProps) {
  const [state, formAction, pending] = useActionState<FormState, FormData>(
    requestReportExport,
    {},
  );
  const fieldErrors = Object.values(state.errors ?? {}).flat();

  return (
    <form action={formAction} className="space-y-2">
      <input type="hidden" name="month" value={month} />
      <Button variant="secondary" pending={pending} pendingLabel="依頼中…">
        CSV を生成（非同期）
      </Button>
      <div className="max-w-md whitespace-normal">
        <Alert state={state} />
        {fieldErrors.length > 0 && (
          <ul role="alert" className="space-y-0.5 text-sm text-red-600">
            {fieldErrors.map((message, i) => (
              <li key={`${i}-${message}`}>{message}</li>
            ))}
          </ul>
        )}
      </div>
    </form>
  );
}
