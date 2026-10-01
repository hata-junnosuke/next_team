import Link from "next/link";
import { LogoutButton } from "@/components/ui/LogoutButton";
import { apiGet } from "@/lib/api";
import { userResponse } from "@/lib/schemas/user";

// 管理画面の共通レイアウト。GET /api/user が 401 なら lib/api.ts が /login へ送る

// P3〜P5 の全画面分を先に置く（まだ無い画面は 404 になる）
const navItems = [
  { href: "/dashboard", label: "ダッシュボード" },
  { href: "/locations", label: "拠点" },
  { href: "/workers", label: "ワーカー" },
  { href: "/job-postings", label: "募集" },
  { href: "/reports/monthly", label: "月次集計" },
  { href: "/reports/exports", label: "非同期出力" },
  { href: "/notifications", label: "通知履歴" },
];

export default async function AdminLayout({ children }: LayoutProps<"/">) {
  const { data: user } = await apiGet("/api/user", userResponse);

  return (
    <div className="flex flex-1 flex-col">
      <header className="border-b border-gray-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-3">
          <div className="flex items-baseline gap-3">
            <span className="font-semibold whitespace-nowrap">myteam</span>
            <span className="text-sm whitespace-nowrap text-gray-600">
              {user.company.name}
            </span>
          </div>
          <div className="flex items-center gap-3">
            <span className="text-sm whitespace-nowrap text-gray-700">
              {user.name}
            </span>
            <LogoutButton />
          </div>
        </div>
        <nav
          aria-label="メニュー"
          className="mx-auto max-w-6xl overflow-x-auto px-6"
        >
          <ul className="flex gap-1">
            {navItems.map((item) => (
              <li key={item.href}>
                <Link
                  href={item.href}
                  className="block px-3 py-2 text-sm whitespace-nowrap text-gray-700 hover:text-blue-700"
                >
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </header>
      <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-6">
        {children}
      </main>
    </div>
  );
}
