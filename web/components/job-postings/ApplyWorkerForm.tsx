"use client";

import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import type { FormState } from "@/lib/form-state";

// 応募の追加（F-09）。選択肢は API の applicable-workers（有効で、まだ応募していないワーカー）だけ。
// 無効ワーカー・二重応募は errors.worker_id（Field）、締切・中止の募集は errors が空の 422（Alert）。
// 応募できるかは API の can.apply で非活性にするだけ（判定は API）

type ApplyWorkerFormProps = {
  /** Server Component で addApplication.bind(null, posting.id) にしたもの */
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  workers: { value: number; label: string }[];
  /** API の can.apply */
  canApply: boolean;
};

export function ApplyWorkerForm({
  action,
  workers,
  canApply,
}: ApplyWorkerFormProps) {
  const [state, formAction, pending] = useActionState(action, {});

  return (
    <form action={formAction} className="max-w-xl space-y-3">
      <Alert state={state} />
      {/* 応募できないときはセレクトとボタンをまとめて非活性にする（判定は API の can.apply。最終的には 422） */}
      <fieldset
        disabled={!canApply}
        className="flex items-end gap-3 disabled:opacity-60"
      >
        <div className="w-72">
          <Field
            label="ワーカー"
            name="worker_id"
            id="field-application-worker_id"
            type="select"
            options={workers}
            emptyOption={
              workers.length === 0
                ? "応募できるワーカーがいません"
                : "選択してください"
            }
            errors={state.errors?.worker_id}
            defaultValue={state.values?.worker_id}
          />
        </div>
        <Button pending={pending} pendingLabel="追加中…" disabled={!canApply}>
          応募を追加
        </Button>
      </fieldset>
    </form>
  );
}
