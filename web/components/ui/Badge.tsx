import type { EnumValue } from "@/lib/schemas/common";
import { statusClass, type StatusKind } from "@/lib/status";

// 状態の表示。色は lib/status.ts、ラベルは API の label をそのまま出す

type BadgeProps = {
  kind: StatusKind;
  status: EnumValue;
};

export function Badge({ kind, status }: BadgeProps) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset ${statusClass(kind, status.value)}`}
    >
      {status.label}
    </span>
  );
}
