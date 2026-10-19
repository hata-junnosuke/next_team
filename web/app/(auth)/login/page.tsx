import { LoginForm } from "@/components/auth/LoginForm";

// ログイン画面。左はブランド面（飾り）、右はフォーム（Client の LoginForm）。
// このページ自体は Server Component で、データは取らない

// 発車標風のボードの見本。飾りなので固定の値で、API は呼ばない（人名も出さない）。
// 色は埋まり具合だけを表す（充足 = sidebar-ink、不足 = tone-amber）。状態のバッジ（lib/status.ts）の色とは別の意味なので、
// 緑・赤・青は使わない。accent は「深夜跨ぎの文字」として深夜跨ぎの行の終了時刻にだけ使う
const boardRows = [
  {
    start: "07:00",
    end: "12:00",
    overnight: false,
    place: "川崎倉庫・検品",
    filled: 3,
    capacity: 3,
  },
  {
    start: "09:00",
    end: "18:00",
    overnight: false,
    place: "渋谷店・品出し",
    filled: 2,
    capacity: 3,
  },
  {
    start: "13:00",
    end: "17:00",
    overnight: false,
    place: "新宿センター・梱包",
    filled: 1,
    capacity: 4,
  },
  {
    start: "22:00",
    end: "06:00",
    overnight: true,
    place: "横浜物流・仕分け",
    filled: 0,
    capacity: 2,
  },
] as const;

function ShiftBoard() {
  return (
    <div
      aria-hidden="true"
      className="bg-sidebar-raised ring-sidebar-muted/20 hidden rounded-lg p-4 ring-1 md:block"
    >
      <div className="text-sidebar-muted border-b border-dashed border-current/30 pb-2 text-xs whitespace-nowrap">
        本日の勤務枠
      </div>
      <ul className="divide-sidebar-muted/20 divide-y">
        {boardRows.map((row) => {
          const remaining = row.capacity - row.filled;
          const colorClass =
            remaining === 0 ? "bg-sidebar-ink" : "bg-tone-amber";
          return (
            <li
              key={row.start}
              className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-4 py-3"
            >
              <div className="font-num w-16 leading-none">
                <div className="text-sidebar-ink text-2xl font-semibold">
                  {row.start}
                </div>
                <div
                  className={`mt-1 text-base font-medium whitespace-nowrap ${
                    row.overnight ? "text-accent" : "text-sidebar-muted"
                  }`}
                >
                  {row.overnight && (
                    <span className="font-sans text-xs">翌</span>
                  )}
                  {row.end}
                </div>
              </div>
              <div className="min-w-0">
                <div className="text-sidebar-ink truncate text-sm">
                  {row.place}
                </div>
                <div className="mt-2 flex items-center gap-2">
                  <div className="bg-sidebar h-1.5 flex-1 overflow-hidden rounded-full">
                    <div
                      className={`h-full rounded-full ${colorClass}`}
                      style={{
                        width: `${(row.filled / row.capacity) * 100}%`,
                      }}
                    />
                  </div>
                  <span className="font-num text-sidebar-ink text-sm whitespace-nowrap">
                    {row.filled}/{row.capacity}
                  </span>
                </div>
              </div>
              <div className="text-sidebar-ink flex items-center gap-1.5 text-xs whitespace-nowrap">
                <span className={`size-2 rounded-full ${colorClass}`} />
                {remaining === 0 ? "充足" : `あと ${remaining} 名`}
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

export default function LoginPage() {
  return (
    <main className="grid flex-1 md:grid-cols-2">
      <section className="bg-sidebar flex flex-col justify-center gap-6 px-6 py-8 md:px-10 md:py-12 lg:px-16">
        <p className="text-sidebar-muted text-sm font-semibold tracking-wider">
          myteam
        </p>
        <div className="space-y-3">
          <p className="text-sidebar-ink text-2xl leading-snug font-bold md:text-3xl">
            人が足りない枠を、すぐ埋める。
          </p>
          <p className="text-sidebar-muted text-sm leading-relaxed">
            拠点・ワーカー・募集・応募・勤怠・報酬を、ひとつの管理画面で。
          </p>
        </div>
        <div className="w-full max-w-md">
          <ShiftBoard />
        </div>
      </section>
      <div className="flex items-center justify-center px-4 py-10 md:px-6">
        <LoginForm />
      </div>
    </main>
  );
}
