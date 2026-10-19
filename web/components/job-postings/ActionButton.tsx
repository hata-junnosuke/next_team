"use client";

import { useActionState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import type { FormState } from "@/lib/form-state";

// 募集詳細の状態ボタン（募集の締切・中止、応募の承認・却下）。入力欄のない小さなフォーム。
// 押せるかは API の can.* で非活性にするだけ（非表示にしない）。最終的な判定は API の 422 で、
// その message（例:「定員に達しているため承認できません。」）をボタンの下の Alert に出す

type ActionButtonProps = {
  /** Server Component で bind したもの（例: confirmApplication.bind(null, application.id, posting.id)） */
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  label: string;
  pendingLabel: string;
  variant?: "primary" | "secondary" | "danger";
  /** 表の行内は "sm" */
  size?: "sm" | "md";
  /** API の can.* が false のとき true */
  disabled: boolean;
  /** 指定すると送信前に確認ダイアログを出す（誤操作の防止だけ。可否の判定はしない） */
  confirmMessage?: string;
  /** ボタンと 422 の理由をどちら側にそろえるか。画面の右端に置くときは "end"（理由を左へ伸ばし、はみ出さない） */
  align?: "start" | "end";
};

export function ActionButton({
  action,
  label,
  pendingLabel,
  variant = "secondary",
  size = "md",
  disabled,
  confirmMessage,
  align = "start",
}: ActionButtonProps) {
  const [state, formAction, pending] = useActionState(action, {});

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    // JS が読み込まれる前の送信では onSubmit が動かないので確認は出ない
    if (confirmMessage && !window.confirm(confirmMessage)) {
      e.preventDefault();
    }
  }

  return (
    <form
      action={formAction}
      onSubmit={onSubmit}
      className={
        align === "end"
          ? "relative flex flex-col items-end"
          : "flex flex-col items-start gap-2"
      }
    >
      <Button
        variant={variant}
        size={size}
        pending={pending}
        pendingLabel={pendingLabel}
        disabled={disabled}
      >
        {label}
      </Button>
      {/* 表のセルは whitespace-nowrap なので、長い理由文はここで折り返す。
          w-max でボタンの幅に押し込まれず、文の長さ（最大 max-w-xs）まで広がる。
          "end"（見出しの右）では absolute でボタンの下に重ね、理由が出ても見出しの行の高さを変えない */}
      <div
        className={`w-max max-w-xs whitespace-normal ${align === "end" ? "absolute top-full right-0 z-10 mt-2" : ""}`}
      >
        <Alert state={state} />
      </div>
    </form>
  );
}
