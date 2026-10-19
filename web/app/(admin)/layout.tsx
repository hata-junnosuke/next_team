import { LogoutButton } from "@/components/ui/LogoutButton";
import { NavLink } from "@/components/ui/NavLink";
import { apiGet } from "@/lib/api";
import { userResponse } from "@/lib/schemas/user";

// 管理画面の共通レイアウト。GET /api/user が 401 なら lib/api.ts が /login へ送る。
// lg 以上: 左のサイドバー（w-60）+ 右の本文。lg 未満: サイドバーを上部に畳む（CSS だけ。開閉の状態は持たない）。
// 畳んだときは 1 行目にブランドと管理者・ログアウト、2 行目にメニュー（この行だけ横スクロール）を並べる

// 全画面分のメニューを先に置く（まだ無い画面は 404 になる）
const navItems = [
  { href: "/dashboard", label: "ダッシュボード" },
  { href: "/locations", label: "拠点" },
  { href: "/workers", label: "ワーカー" },
  { href: "/job-postings", label: "募集" },
  { href: "/reports/monthly", label: "月次集計" },
  { href: "/reports/exports", label: "非同期出力" },
  { href: "/notifications", label: "通知履歴" },
];

// 現在の画面（aria-current="page"）は sidebar-raised の地と accent の印（lg 未満は下線、lg 以上は左の縦線）。
// 濃紺の上では sidebar 色のフォーカスの印が見えないので sidebar-ink にする。
// メニューの行は overflow-x-auto で外側の印が切れるため、印は内側（-outline-offset-2）に出す
const navLinkClass = [
  "relative flex items-center rounded-md px-3 py-2 text-sm whitespace-nowrap text-sidebar-muted transition-colors",
  "hover:bg-sidebar-raised hover:text-sidebar-ink",
  "focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-sidebar-ink",
  "aria-[current=page]:bg-sidebar-raised aria-[current=page]:font-medium aria-[current=page]:text-sidebar-ink",
  "before:absolute before:right-3 before:bottom-0 before:left-3 before:h-0.5 before:rounded-full",
  "lg:before:top-1.5 lg:before:right-auto lg:before:bottom-1.5 lg:before:left-0 lg:before:h-auto lg:before:w-1",
  "aria-[current=page]:before:bg-accent",
].join(" ");

export default async function AdminLayout({ children }: LayoutProps<"/">) {
  const { data: user } = await apiGet("/api/user", userResponse);

  return (
    <div className="flex flex-1 flex-col lg:flex-row">
      <aside className="bg-sidebar text-sidebar-ink flex flex-wrap items-center gap-x-4 lg:sticky lg:top-0 lg:h-dvh lg:w-60 lg:shrink-0 lg:flex-col lg:flex-nowrap lg:items-stretch">
        <div className="order-1 min-w-0 flex-1 px-5 py-4 lg:flex-none lg:py-6">
          <div className="text-lg font-semibold tracking-wide whitespace-nowrap">
            myteam
          </div>
          <div className="text-sidebar-muted truncate text-xs">
            {user.company.name}
          </div>
        </div>
        <nav
          aria-label="メニュー"
          className="order-3 w-full overflow-x-auto px-3 pb-2 lg:order-2 lg:flex-1 lg:overflow-y-auto lg:pb-0"
        >
          <ul className="flex gap-1 lg:flex-col">
            {navItems.map((item) => (
              <li key={item.href} className="shrink-0">
                <NavLink href={item.href} className={navLinkClass}>
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
        {/* LogoutButton は白地向けの色なので、濃紺の上で見えるようにここで差し替える。
            フォーカスの印（sidebar）→ sidebar-ink、失敗時のエラー文字（text-danger。濃紺の上では約 3.5:1）→ sidebar-ink。
            エラー文字は whitespace-nowrap だと lg 未満で 1 行目の幅が広がるので、折り返しを許す */}
        <div className="lg:border-sidebar-raised [&_button:focus-visible]:outline-sidebar-ink [&_.text-danger]:text-sidebar-ink order-2 flex min-w-0 items-center gap-3 px-5 py-3 lg:order-3 lg:justify-between lg:border-t lg:py-4 [&_.text-danger]:whitespace-normal">
          <span className="text-sidebar-ink truncate text-sm">{user.name}</span>
          <LogoutButton />
        </div>
      </aside>
      <main className="bg-canvas min-w-0 flex-1">
        <div className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-8 sm:py-8">
          {children}
        </div>
      </main>
    </div>
  );
}
