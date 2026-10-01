import "server-only";

import { ApiError, toFormState } from "@/lib/api";
import type { FormState } from "@/lib/form-state";

// Server Action（lib/actions/*.ts）の共通部品。
// "use server" のファイルは async 関数しか export できないので、同期の部品はここに置く（このファイルは "use server" にしない）

/** bind で渡された id の形だけを確かめる（正の整数でなければ API を呼ばない。存在・他社は API の 404） */
export function isValidId(id: unknown): id is number {
  return typeof id === "number" && Number.isSafeInteger(id) && id > 0;
}

/** formData の値が文字列でなければ（未送信・ファイル）null にする */
export function text(formData: FormData, name: string): string | null {
  const value = formData.get(name);
  return typeof value === "string" ? value : null;
}

/**
 * 同じページに留まる操作（状態ボタン・勤怠の保存）の catch で使う。
 * 404（別のタブで消えた・他社）は not-found 画面に飛ばさず API の message を Alert に出す。
 * 422 は toFormState で FormState に。それ以外は投げ直す
 */
export function toInPageFormState(e: unknown, formData: FormData): FormState {
  if (e instanceof ApiError && e.status === 404) {
    return { ok: false, message: e.message };
  }
  return toFormState(e, formData);
}
