// 勤怠の日時（ISO 8601）→ `HH:MM`（日本時間）。勤怠フォームの初期値と応募一覧の表示に使う。
// lib/format.ts（基盤の所有）に時刻だけの関数がないので、募集詳細の中だけで使う形でここに置く

const clockFormat = new Intl.DateTimeFormat("ja-JP", {
  timeZone: "Asia/Tokyo",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});

/** `2026-09-20T09:00:00+09:00` → `09:00` */
export function clock(iso: string): string {
  return clockFormat.format(new Date(iso));
}
