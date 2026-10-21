"use client"; // エラー境界は Client Component でなければならない（Next 16 の error.js）

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";

// 管理画面の中で想定外の例外（500 系・zod の parse 失敗など）が起きたときの画面。
// エラーの中身（message・stack）は画面に出さない。本番では Server Component の message は伏せられるが、
// 開発中は元の message が届くので、ここでは error を受け取っても表示しない。
// retry() はこの境界の中を取り直して描画し直す（Next 16。以前の reset() は取り直さない）。
// 共通レイアウト（app/(admin)/layout.tsx）で起きた例外はこの境界では受けられない（同じ階層の layout は包まない）

type Props = {
  retry: () => void;
};

export default function AdminError({ retry }: Props) {
  return (
    <div className="flex justify-center py-12">
      <Card className="w-full max-w-md">
        <div className="space-y-4 px-3 py-5 text-center">
          <h1 className="text-xl font-semibold whitespace-nowrap">
            問題が発生しました
          </h1>
          <p className="text-ink-muted text-sm">
            画面を表示できませんでした。時間をおいてから再試行してください。
          </p>
          <Button type="button" onClick={() => retry()}>
            再試行する
          </Button>
        </div>
      </Card>
    </div>
  );
}
