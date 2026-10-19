"use client";

import { useActionState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import type { FormState } from "@/lib/form-state";

// 一覧の行ごとの削除ボタン。削除してよいかの判定は API（使用中なら 422 の message を Alert に出す）。
// deletable=false のときは非活性で出す（API の値で出し分けるだけ。最終的な判定は API の 422）

type DeleteLocationButtonProps = {
  /** 一覧（Server Component）で deleteLocation.bind(null, location.id) にしたもの */
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  name: string;
  deletable: boolean;
};

export function DeleteLocationButton({
  action,
  name,
  deletable,
}: DeleteLocationButtonProps) {
  const [state, formAction, pending] = useActionState(action, {});

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    // 誤操作の防止だけ。削除の可否はここで判定しない。
    // JS が読み込まれる前（JS なし）の送信では onSubmit が動かないので確認は出ない
    if (!window.confirm(`拠点「${name}」を削除します。よろしいですか？`)) {
      e.preventDefault();
    }
  }

  return (
    <form action={formAction} onSubmit={onSubmit} className="space-y-2">
      <Button
        variant="danger"
        size="sm"
        pending={pending}
        pendingLabel="削除中…"
        disabled={!deletable}
      >
        削除
      </Button>
      {/* 表のセルは whitespace-nowrap なので、長い理由文はここで折り返す */}
      <div className="max-w-xs whitespace-normal">
        <Alert state={state} />
      </div>
    </form>
  );
}
