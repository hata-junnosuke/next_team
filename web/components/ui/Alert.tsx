import type { FormState } from "@/lib/form-state";

// FormState の message を表示する。ok なら成功、そうでなければエラー。
// 項目エラーは Field、項目に紐づかないエラー（errors が空の 422・業務ルール違反・429 など）はここ。
// その振り分けはこの 1 箇所で行う（docs/01 7.3）。呼ぶ側で条件を書かない

type AlertProps = {
  state: FormState;
};

export function Alert({ state }: AlertProps) {
  const hasFieldErrors = Object.keys(state.errors ?? {}).length > 0;
  if (!state.message || hasFieldErrors) {
    return null;
  }
  const className = state.ok
    ? "border-green-300 bg-green-50 text-green-800"
    : "border-red-300 bg-red-50 text-red-800";
  return (
    <div
      role={state.ok ? "status" : "alert"}
      className={`rounded-md border px-4 py-3 text-sm ${className}`}
    >
      {state.message}
    </div>
  );
}
