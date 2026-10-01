"use client"; // 戻り先の一覧を URL から決めるため usePathname を使う

import Link from "next/link";
import { usePathname } from "next/navigation";

// 管理画面の中で notFound() が呼ばれたときの画面（存在しない・他社のデータ。lib/api.ts が 404 で呼ぶ）。
// not-found.js は props を受け取らないので、戻り先は URL の先頭の区切りから決める

const lists: Record<string, string> = {
  locations: "拠点の一覧へ戻る",
  workers: "ワーカーの一覧へ戻る",
  "job-postings": "募集の一覧へ戻る",
};

export default function AdminNotFound() {
  const segment = usePathname().split("/")[1] ?? "";
  const label = lists[segment];
  const href = label ? `/${segment}` : "/dashboard";

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold whitespace-nowrap">
        見つかりませんでした
      </h1>
      <p className="text-sm text-gray-700">
        お探しのデータは存在しないか、削除された可能性があります。
      </p>
      <Link href={href} className="text-sm text-blue-700 hover:underline">
        {label ?? "ダッシュボードへ戻る"}
      </Link>
    </div>
  );
}
