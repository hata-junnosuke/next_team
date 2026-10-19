import type { FormState } from "@/lib/form-state";

// FormState の message を表示する。ok なら成功、そうでなければエラー。
// 項目エラーは Field、項目に紐づかないエラー（errors が空の 422・業務ルール違反・429 など）はここ。
// その振り分けはこの 1 箇所で行う（docs/01 の Server Action と FormState）。呼ぶ側で条件を書かない

type AlertProps = {
  state: FormState;
};

export function Alert({ state }: AlertProps) {
  const hasFieldErrors = Object.keys(state.errors ?? {}).length > 0;
  if (!state.message || hasFieldErrors) {
    return null;
  }
  // 左のアクセント線と文字色でトーンを出す（地は surface）
  const className = state.ok
    ? "border-success text-success"
    : "border-danger text-danger";
  return (
    <div
      role={state.ok ? "status" : "alert"}
      className={`bg-surface ring-line rounded-md border-l-4 px-4 py-3 text-sm font-medium ring-1 ${className}`}
    >
      {state.message}
    </div>
  );
}
