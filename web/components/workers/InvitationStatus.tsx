// ワーカーの招待の状態の表示（丸い点 + 文字色。見た目は ActiveBadge と同じ形）。
// ラベルを web に置くのは docs/01 の 7.5 の例外（issue #38）。出し分けは API の registered_at と
// invitation_expires_at の 2 つの値だけで決め、期限切れかどうかなどの判定は書かない

import type { Worker } from "@/lib/schemas/worker";
import { dateTime } from "@/lib/format";
import { type Tone, toneClasses } from "@/lib/status";

type InvitationStatusProps = {
  worker: Pick<Worker, "registered_at" | "invitation_expires_at">;
};

function describe({
  registered_at,
  invitation_expires_at,
}: InvitationStatusProps["worker"]): {
  label: string;
  tone: Tone;
  detail: string | null;
} {
  if (registered_at !== null) {
    return { label: "登録済み", tone: "green", detail: dateTime(registered_at) };
  }
  if (invitation_expires_at !== null) {
    return {
      label: "未登録",
      tone: "amber",
      detail: `期限 ${dateTime(invitation_expires_at)}`,
    };
  }
  return { label: "未登録（使える招待なし）", tone: "gray", detail: null };
}

export function InvitationStatus({ worker }: InvitationStatusProps) {
  const { label, tone, detail } = describe(worker);
  return (
    <span className="inline-flex items-center gap-2 whitespace-nowrap">
      <span
        className={`inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap ${toneClasses[tone]}`}
      >
        {/* 丸い点は文字色（currentColor）で塗る。地の塗りはなし */}
        <span
          aria-hidden="true"
          className="size-2 shrink-0 rounded-full bg-current"
        />
        {label}
      </span>
      {detail !== null && (
        <span className="text-ink-muted text-xs whitespace-nowrap">
          {detail}
        </span>
      )}
    </span>
  );
}
