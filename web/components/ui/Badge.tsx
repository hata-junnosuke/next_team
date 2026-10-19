import type { EnumValue } from "@/lib/schemas/common";
import { statusClass, type StatusKind } from "@/lib/status";

// 状態の表示（丸い点 + ラベル）。色は lib/status.ts、ラベルは API の label をそのまま出す

type BadgeProps = {
  kind: StatusKind;
  status: EnumValue;
};

export function Badge({ kind, status }: BadgeProps) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap ${statusClass(kind, status.value)}`}
    >
      {/* 丸い点は文字色（currentColor）で塗る。地の塗りはなし */}
      <span
        aria-hidden="true"
        className="size-2 shrink-0 rounded-full bg-current"
      />
      {status.label}
    </span>
  );
}
