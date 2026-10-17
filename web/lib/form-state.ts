// Server Action の戻り値（docs/01 の Server Action と FormState）。useActionState(action, {}) の state になる。
// 型だけのファイルなので Client Component からも import してよい。
// 422 → FormState の変換（toFormState）は lib/api.ts（server-only）にある
export type FormState = {
  ok?: boolean;
  message?: string;
  errors?: Record<string, string[]>;
  /** 422 のときに送った値（password は入らない）。入力欄の defaultValue に使う */
  values?: Record<string, string>;
};
