"use client"; // エラー境界は Client Component でなければならない（Next 16 の global-error.js）

import "./globals.css";
import { Button } from "@/components/ui/Button";
import { fontVariables } from "./fonts";

// ルートの layout と管理画面の共通レイアウト（app/(admin)/layout.tsx。GET /api/user）で起きた例外の画面。
// app/(admin)/error.tsx は同じ階層の layout を包まないので、ここで受ける（API が止まっているときなど）。
// 有効な間はルートの layout の代わりになるので、html と body とスタイルを自分で持つ。
// フォントは app/fonts.ts をルートの layout と共有し、ここでも <html> に variable を付ける。
// エラーの中身（message・stack）は画面に出さない。retry() は取り直して描画し直す

type Props = {
  retry: () => void;
};

export default function GlobalError({ retry }: Props) {
  return (
    <html lang="ja" className={`${fontVariables} h-full antialiased`}>
      <body className="bg-canvas text-ink flex min-h-full flex-col font-sans tabular-nums">
        <title>問題が発生しました | myteam 管理画面</title>
        <main className="flex flex-1 items-center justify-center px-6 py-16">
          <div className="bg-surface ring-line w-full max-w-md space-y-4 rounded-lg px-8 py-10 text-center shadow-xs ring-1">
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
        </main>
      </body>
    </html>
  );
}
