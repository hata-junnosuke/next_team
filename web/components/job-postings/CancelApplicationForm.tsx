"use client";

import { useActionState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import type { FormState } from "@/lib/form-state";
import type { statusTones } from "@/lib/status";

// 確定した応募のキャンセル（F-13）。理由区分（企業都合 / ワーカー都合）を選んで送る。
// 未選択は Laravel の 422（errors.reason）を Field に出す（必須の判定は web で書かない）。
// キャンセルできるかは API の can.cancel で非活性にするだけ

type ReasonValue = keyof (typeof statusTones)["cancelReason"];

// 理由区分の選択肢。API に Enum の一覧を返す口がないため、docs/01 の Enum の CancelReason の
// value → label をここに置く（docs/01 の表示の規約の例外と同じ扱い。表の表示は API の label をそのまま出す）
const reasonOptions: { value: ReasonValue; label: string }[] = [
  { value: "company", label: "企業都合" },
  { value: "worker", label: "ワーカー都合" },
];

type CancelApplicationFormProps = {
  /** Server Component で cancelApplication.bind(null, application.id, posting.id) にしたもの */
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  applicationId: number;
  /** 確認ダイアログに出すワーカー名 */
  workerName: string;
  /** API の can.cancel */
  canCancel: boolean;
};

export function CancelApplicationForm({
  action,
  applicationId,
  workerName,
  canCancel,
}: CancelApplicationFormProps) {
  const [state, formAction, pending] = useActionState(action, {});

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    // 誤操作の防止だけ（可否と理由の必須は API が判定する）。JS なしの送信では確認は出ない
    if (
      !window.confirm(
        `${workerName}さんの確定をキャンセルします。よろしいですか？`,
      )
    ) {
      e.preventDefault();
    }
  }

  return (
    // 表のセルは whitespace-nowrap なので、項目エラーと理由文はこの中で折り返す
    <form
      action={formAction}
      onSubmit={onSubmit}
      className="max-w-xs space-y-2 whitespace-normal"
    >
      {/* キャンセルできないときは理由のセレクトとボタンをまとめて非活性にする（判定は API の can.cancel） */}
      <fieldset
        disabled={!canCancel}
        className="flex items-start gap-2 disabled:opacity-60"
      >
        <div className="w-40">
          <Field
            label="キャンセル理由"
            name="reason"
            // 応募ごとに同じフォームが並ぶので id を分ける
            id={`field-cancel-${applicationId}-reason`}
            type="select"
            options={reasonOptions}
            emptyOption="選択してください"
            errors={state.errors?.reason}
            defaultValue={state.values?.reason}
          />
        </div>
        {/* ラベルの高さ（text-xs の 16px + 間隔 4px）ぶん下げ、入力欄と同じ h-9 の中で小さいボタンを縦中央にそろえる */}
        <div className="mt-5 flex h-9 items-center">
          <Button
            variant="danger"
            size="sm"
            pending={pending}
            pendingLabel="キャンセル中…"
            disabled={!canCancel}
          >
            キャンセル
          </Button>
        </div>
      </fieldset>
      <Alert state={state} />
    </form>
  );
}
