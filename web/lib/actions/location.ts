"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, apiSend, toFormState } from "@/lib/api";
import { isValidId } from "@/lib/actions/helpers";
import type { FormState } from "@/lib/form-state";

// 拠点の登録・編集・削除（docs/01 の API 契約の拠点、Server Action と FormState）。
// Server Action は公開エンドポイントと同じ扱い。引数（bind した id を含む）は信用しない。
// ここで見るのは id の形だけで、存在しない・他社の拠点は Laravel が 404 を返すのに任せる。
// 検証も Laravel が正（名称の必須など）

const invalidId: FormState = {
  ok: false,
  message: "拠点が見つかりませんでした。",
};

/** 送る項目だけを明示して取り出す（Next が付ける $ACTION_ のキーなどは送らない） */
function locationBody(formData: FormData) {
  return {
    name: formData.get("name"),
    address: formData.get("address"),
  };
}

function locationPath(id: number): string {
  return `/api/locations/${id}`;
}

export async function createLocation(
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  try {
    await apiSend("POST", "/api/locations", locationBody(formData));
  } catch (e) {
    return toFormState(e, formData);
  }
  revalidatePath("/locations");
  // redirect は例外を投げるので try の外で呼ぶ
  redirect("/locations");
}

/** 編集ページ（Server Component）で updateLocation.bind(null, location.id) にして渡す */
export async function updateLocation(
  id: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(id)) {
    return invalidId;
  }
  try {
    await apiSend("PUT", locationPath(id), locationBody(formData));
  } catch (e) {
    return toFormState(e, formData);
  }
  revalidatePath("/locations");
  redirect("/locations");
}

/**
 * 一覧（Server Component）で deleteLocation.bind(null, location.id) にして渡す。成功は 204。
 * 募集に使われている拠点は 422 の message（業務ルール違反）を Alert に出す。
 * 一覧に留まるので redirect せず再検証だけする
 */
export async function deleteLocation(
  id: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(id)) {
    return invalidId;
  }
  try {
    await apiSend("DELETE", locationPath(id));
  } catch (e) {
    // 404 は「もう無い」= 消したかった状態になっているので成功と同じ扱い（別のタブで削除済みなど）
    if (!(e instanceof ApiError && e.status === 404)) {
      return toFormState(e, formData);
    }
  }
  revalidatePath("/locations");
  return { ok: true };
}
