"use client";

import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import type { FormState } from "@/lib/form-state";

// 招待を送り直すボタン（F-27）。出すかどうかは呼ぶ側が API の can.invite で決め、送れるかの判定は API。
// 文言（成功・422・404・429）はボタンごとの state に出すので、行ごとに独立する

type ResendInvitationButtonProps = {
  /** 一覧・編集ページ（Server Component）で resendWorkerInvitation.bind(null, worker.id) にしたもの */
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
};

export function ResendInvitationButton({
  action,
}: ResendInvitationButtonProps) {
  const [state, formAction, pending] = useActionState(action, {});

  return (
    <form action={formAction} className="space-y-2">
      <Button
        variant="secondary"
        size="sm"
        pending={pending}
        pendingLabel="送信中…"
      >
        招待を送り直す
      </Button>
      {/* 表のセル（whitespace-nowrap）の中でも長い文言はここで折り返す */}
      <div className="max-w-xs whitespace-normal">
        <Alert state={state} />
      </div>
    </form>
  );
}
