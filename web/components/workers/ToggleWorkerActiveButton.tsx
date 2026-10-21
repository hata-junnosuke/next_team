"use client";

import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import type { FormState } from "@/lib/form-state";

// 一覧の行ごとの有効/無効の切替ボタン（F-03）。切り替えてよいかの判定は API。
// 成功したら Server Action が /workers を再検証するので、行の表示とボタンの文言が入れ替わる

type ToggleWorkerActiveButtonProps = {
  /** 一覧（Server Component）で setWorkerActive.bind(null, worker.id, !worker.is_active) にしたもの */
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  /** いまの状態（API の is_active）。ボタンの文言にだけ使う */
  isActive: boolean;
};

export function ToggleWorkerActiveButton({
  action,
  isActive,
}: ToggleWorkerActiveButtonProps) {
  const [state, formAction, pending] = useActionState(action, {});

  return (
    <form action={formAction} className="space-y-2">
      <Button
        variant="secondary"
        size="sm"
        pending={pending}
        pendingLabel="切替中…"
      >
        {isActive ? "無効にする" : "有効にする"}
      </Button>
      {/* 表のセルは whitespace-nowrap なので、長い理由文はここで折り返す */}
      <div className="max-w-xs whitespace-normal">
        <Alert state={state} />
      </div>
    </form>
  );
}
