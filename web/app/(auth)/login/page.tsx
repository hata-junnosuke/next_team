"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { login } from "@/lib/browser-api";
import type { FormState } from "@/lib/form-state";

// ログイン。Cookie を受け取る操作なのでブラウザから Laravel を直接叩く（docs/01 5.2）。
// <form action> ではなく onSubmit にしているのは、React 19 が action 完了後にフォームを
// リセットし、422 のときに入力したメールアドレスが消えるのを避けるため

export default function LoginPage() {
  const router = useRouter();
  const [state, setState] = useState<FormState>({});
  const [pending, startTransition] = useTransition();

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    startTransition(async () => {
      const result = await login(
        String(form.get("email") ?? ""),
        String(form.get("password") ?? ""),
      );
      if (result.ok) {
        router.replace("/dashboard");
        router.refresh();
        return;
      }
      // 422 は errors.email に、429 は message に日本語の理由が入る（どちらも API の文言）
      setState(result);
    });
  }

  return (
    <main className="flex flex-1 items-center justify-center p-6">
      <div className="w-full max-w-sm space-y-6 rounded-lg border border-gray-200 bg-white p-8 shadow-sm">
        <h1 className="text-xl font-semibold whitespace-nowrap">
          myteam 管理画面にログイン
        </h1>
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          <Alert state={state} />
          <Field
            label="メールアドレス"
            name="email"
            type="email"
            autoComplete="username"
            required
            errors={state.errors?.email}
          />
          <Field
            label="パスワード"
            name="password"
            type="password"
            autoComplete="current-password"
            required
            errors={state.errors?.password}
          />
          <Button pending={pending} pendingLabel="ログイン中…">
            ログイン
          </Button>
        </form>
      </div>
    </main>
  );
}
