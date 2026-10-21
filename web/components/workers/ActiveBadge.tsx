// ワーカーの有効/無効の表示（丸い点 + 文字色。見た目は components/ui/Badge と同じ形）。
// 値は API の is_active をそのまま使う。lib/status.ts に kind は足さず、どちらの色にするかだけをここで決める

import { toneClasses } from "@/lib/status";

type ActiveBadgeProps = {
  isActive: boolean;
};

export function ActiveBadge({ isActive }: ActiveBadgeProps) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap ${toneClasses[isActive ? "green" : "gray"]}`}
    >
      {/* 丸い点は文字色（currentColor）で塗る。地の塗りはなし */}
      <span
        aria-hidden="true"
        className="size-2 shrink-0 rounded-full bg-current"
      />
      {isActive ? "有効" : "無効"}
    </span>
  );
}
