"use client";

import { useState, useTransition } from "react";
import { logout } from "@/lib/browser-api";
import { Button } from "./Button";

// ヘッダのログアウト。セッション Cookie を消す操作なのでブラウザから Laravel を直接叩く（lib/browser-api.ts）

export function LogoutButton() {
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string>();

  function onClick() {
    startTransition(async () => {
      const result = await logout();
      if (result.ok) {
        // 画面全体を読み直して Next のクライアント側キャッシュも捨てる。
        // replace なので「戻る」でログアウト直前の画面に戻らない（M-13）
        window.location.replace("/login");
        return;
      }
      setError(result.message);
    });
  }

  return (
    <div className="flex items-center gap-2">
      {error && (
        <span className="text-danger text-sm whitespace-nowrap">{error}</span>
      )}
      <Button
        type="button"
        variant="secondary"
        size="sm"
        pending={pending}
        onClick={onClick}
      >
        ログアウト
      </Button>
    </div>
  );
}
