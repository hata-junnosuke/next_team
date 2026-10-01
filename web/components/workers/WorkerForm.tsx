"use client";

import Link from "next/link";
import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import type { FormState } from "@/lib/form-state";
import type { Worker } from "@/lib/schemas/worker";

// ワーカーの登録・編集フォーム。action は createWorker か updateWorker.bind(null, id)。
// 422 の項目エラーは Field、項目に紐づかないエラーは Alert（振り分けは Alert の中）。
// 有効/無効はここでは扱わない（切り替えは一覧のボタン = PATCH /active だけ）

type WorkerFormProps = {
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  /** 編集のときの元の値 */
  worker?: Worker;
  submitLabel: string;
};

export function WorkerForm({ action, worker, submitLabel }: WorkerFormProps) {
  const [state, formAction, pending] = useActionState(action, {});

  return (
    // noValidate: type="email" のブラウザ検証で送信が止まると、API の 422（メール形式）が
    // 入力欄の下に出ることを確かめられない。検証は Laravel が正
    <form action={formAction} noValidate className="max-w-xl space-y-4">
      <Alert state={state} />
      {/* 422 のあと React がフォームをリセットするので、送った値（values）で戻す。
          氏名・メールに HTML の required を付けないのは、必須のエラーを API の 422 で入力欄の下に出すため（M-3） */}
      <Field
        label="氏名"
        name="name"
        autoComplete="off"
        errors={state.errors?.name}
        defaultValue={state.values?.name ?? worker?.name}
      />
      <Field
        label="メールアドレス"
        name="email"
        type="email"
        autoComplete="off"
        errors={state.errors?.email}
        defaultValue={state.values?.email ?? worker?.email}
      />
      <Field
        label="電話番号"
        name="phone"
        autoComplete="off"
        errors={state.errors?.phone}
        defaultValue={state.values?.phone ?? worker?.phone}
      />
      <div className="flex items-center gap-3">
        <Button pending={pending} pendingLabel="保存中…">
          {submitLabel}
        </Button>
        <Link
          href="/workers"
          className="text-sm whitespace-nowrap text-gray-600 hover:text-gray-900"
        >
          一覧に戻る
        </Link>
      </div>
    </form>
  );
}
