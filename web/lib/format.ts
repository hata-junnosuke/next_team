// 金額・時間・日付 → 表示文字列（docs/01 の表示の規約）。日付ライブラリは入れない

const numberFormat = new Intl.NumberFormat("ja-JP");

const dateFormat = new Intl.DateTimeFormat("ja-JP", {
  timeZone: "Asia/Tokyo",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  weekday: "short",
});

const timeFormat = new Intl.DateTimeFormat("ja-JP", {
  timeZone: "Asia/Tokyo",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});

/** 9600 → `9,600円` */
export function yen(amount: number): string {
  return `${numberFormat.format(amount)}円`;
}

/** 420 → `7時間00分` */
export function minutes(total: number): string {
  const h = Math.floor(total / 60);
  const m = total % 60;
  return `${h}時間${String(m).padStart(2, "0")}分`;
}

function formatDate(d: Date): string {
  const parts = Object.fromEntries(
    dateFormat.formatToParts(d).map((p) => [p.type, p.value]),
  );
  return `${parts.year}/${parts.month}/${parts.day}（${parts.weekday}）`;
}

/** `2026-09-20` → `2026/09/20（日）` */
export function date(ymd: string): string {
  // 日付だけの文字列は UTC として解釈されるので、日本時間の 0 時として読む
  return formatDate(new Date(`${ymd}T00:00:00+09:00`));
}

/** `2026-10-01T10:00:00+09:00` → `2026/10/01（木） 10:00`（日付と時刻の間は半角空白） */
export function dateTime(iso: string): string {
  const d = new Date(iso);
  return `${formatDate(d)} ${timeFormat.format(d)}`;
}

/** `2026-09-20T09:00:00+09:00` → `09:00`（日本時間・24 時間表記）。勤怠の時刻の表示とフォームの初期値に使う */
export function time(iso: string): string {
  return timeFormat.format(new Date(iso));
}
