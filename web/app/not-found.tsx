import Link from "next/link";

// どのルートにも当たらない URL（/foo など）の画面。ルートの layout の中に出る（管理画面のサイドバーは付かない）。
// 管理画面の中で notFound() が呼ばれたときは app/(admin)/not-found.tsx が出る

export default function NotFound() {
  return (
    <main className="flex flex-1 items-center justify-center px-6 py-16">
      <div className="bg-surface ring-line w-full max-w-md space-y-4 rounded-lg px-8 py-10 text-center shadow-xs ring-1">
        <h1 className="text-xl font-semibold whitespace-nowrap">
          見つかりませんでした
        </h1>
        <p className="text-ink-muted text-sm">お探しのページは存在しません。</p>
        <Link
          href="/dashboard"
          className="text-ink focus-visible:ring-sidebar decoration-ink-muted hover:decoration-ink inline-flex items-center rounded-md px-1 text-sm font-medium underline underline-offset-4 focus-visible:ring-2 focus-visible:outline-none"
        >
          ダッシュボードへ戻る
        </Link>
      </div>
    </main>
  );
}
