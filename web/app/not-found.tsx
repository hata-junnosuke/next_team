import Link from "next/link";

// どのルートにも当たらない URL（/foo など）の画面。ルートの layout の中に出る（管理画面のヘッダーは付かない）。
// 管理画面の中で notFound() が呼ばれたときは app/(admin)/not-found.tsx が出る

export default function NotFound() {
  return (
    <main className="mx-auto w-full max-w-6xl space-y-4 px-6 py-6">
      <h1 className="text-xl font-semibold whitespace-nowrap">
        見つかりませんでした
      </h1>
      <p className="text-sm text-gray-700">お探しのページは存在しません。</p>
      <Link href="/dashboard" className="text-sm text-blue-700 hover:underline">
        ダッシュボードへ戻る
      </Link>
    </main>
  );
}
