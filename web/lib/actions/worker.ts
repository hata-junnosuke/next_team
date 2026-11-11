"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, apiSend, toFormState } from "@/lib/api";
import { isValidId, toInPageFormState } from "@/lib/actions/helpers";
import type { FormState } from "@/lib/form-state";
import { type Worker, workerResponse } from "@/lib/schemas/worker";

// ワーカーの登録・編集・有効/無効の切替・招待の送り直し（docs/01 の API 契約のワーカー、Server Action と FormState）。
// Server Action は公開エンドポイントと同じ扱い。引数（bind した id を含む）は信用しない。
// ここで見るのは引数の形だけで、存在しない・他社のワーカーは Laravel が 404 を返すのに任せる。
// 検証も Laravel が正（氏名・メールの必須、メール形式など）

const invalidTarget: FormState = {
  ok: false,
  message: "ワーカーが見つかりませんでした。",
};

/**
 * 送る項目だけを明示して取り出す（Next が付ける $ACTION_ のキーなどは送らない）。
 * is_active は登録・更新では受け付けない契約（切り替えは PATCH だけ）なので送らない
 */
function workerBody(formData: FormData) {
  return {
    name: formData.get("name"),
    email: formData.get("email"),
    phone: formData.get("phone"),
  };
}

function workerPath(id: number): string {
  return `/api/workers/${id}`;
}

export async function createWorker(
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  try {
    await apiSend("POST", "/api/workers", workerBody(formData));
  } catch (e) {
    return toFormState(e, formData);
  }
  revalidatePath("/workers");
  // redirect は例外を投げるので try の外で呼ぶ
  redirect("/workers");
}

/** 編集ページ（Server Component）で updateWorker.bind(null, worker.id) にして渡す */
export async function updateWorker(
  id: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(id)) {
    return invalidTarget;
  }
  try {
    await apiSend("PUT", workerPath(id), workerBody(formData));
  } catch (e) {
    // 404（別のタブで消えた・他社）はフォームの Alert に出す。切替と扱いをそろえる
    if (e instanceof ApiError && e.status === 404) {
      return {
        ok: false,
        message:
          "ワーカーが見つかりませんでした。（他の画面で変更された可能性があります）",
      };
    }
    return toFormState(e, formData);
  }
  revalidatePath("/workers");
  redirect("/workers");
}

/**
 * 一覧（Server Component）で setWorkerActive.bind(null, worker.id, !worker.is_active) にして渡す。
 * 一覧に留まるので redirect せず再検証だけする（状態ボタン。docs/01 の Server Action と FormState）
 */
export async function setWorkerActive(
  id: number,
  isActive: boolean,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(id)) {
    return invalidTarget;
  }
  if (typeof isActive !== "boolean") {
    return { ok: false, message: "操作に失敗しました。" };
  }
  try {
    await apiSend("PATCH", `${workerPath(id)}/active`, {
      is_active: isActive,
    });
  } catch (e) {
    // 404（別のタブで消えた・他社）は一覧の行の中で API の message を出す。画面全体をエラーにしない
    return toInPageFormState(e, formData);
  }
  revalidatePath("/workers");
  return { ok: true };
}

/**
 * 一覧と編集ページ（Server Component）で resendWorkerInvitation.bind(null, worker.id) にして渡す（F-27）。
 * ボタンは API の can.invite が true のときだけ出すが、送れるかの最終的な判定は API（無効・登録済みは 422）。
 * 同じページに留まるので redirect せず再検証して成功の文言を返す（issue #38 の Q4）
 */
export async function resendWorkerInvitation(
  id: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(id)) {
    return invalidTarget;
  }
  let worker: Worker;
  try {
    ({ data: worker } = await apiSend(
      "POST",
      `${workerPath(id)}/invitation`,
      undefined,
      workerResponse,
    ));
  } catch (e) {
    // 429（同じワーカーに 1 分 1 回）は行の Alert に API の message を出す。
    // 共通の toFormState は 429 を投げ直すので、ここで受ける（画面全体をエラーにしない）
    if (e instanceof ApiError && e.status === 429) {
      return { ok: false, message: e.message };
    }
    // 404（別のタブで消えた・他社）と 422（無効・登録済み）は行の Alert。それ以外は投げ直す
    return toInPageFormState(e, formData);
  }
  revalidatePath("/workers");
  // 再検証するパスは引数ではなく API が返した id で決める
  revalidatePath(`/workers/${worker.id}/edit`);
  return { ok: true, message: "招待を送り直しました。" };
}
