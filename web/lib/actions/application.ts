"use server";

import { revalidatePath } from "next/cache";
import { apiSend } from "@/lib/api";
import { isValidId, text, toInPageFormState } from "@/lib/actions/helpers";
import type { FormState } from "@/lib/form-state";
import { applicationResponse } from "@/lib/schemas/application";

// 応募の追加（F-09）・承認（F-10、F-11）・却下（F-12）・キャンセル（F-13）（docs/01 6.5、7.3）。
// どれも募集詳細の中の操作なので redirect せず、詳細ページを再検証して同じページに留まる。
// Server Action は公開エンドポイントと同じ扱い。引数（bind した id を含む）は信用しない。
// ここで見るのは id の形だけ。存在しない・他社のデータは Laravel の 404、
// 状態遷移・定員・無効ワーカー・二重応募の判定は Laravel の 422（web では判定しない）

const invalidId: FormState = {
  ok: false,
  message: "応募が見つかりませんでした。",
};

function postingPath(postingId: number): string {
  return `/job-postings/${postingId}`;
}

/**
 * 応募の追加。詳細ページで addApplication.bind(null, posting.id) にして渡す。
 * 送るのは worker_id だけ（募集の id はルートに含まれる。本文には入れない）。
 * 締切・中止の募集は errors が空の 422（Alert）、無効ワーカー・二重応募は errors.worker_id（Field）
 */
export async function addApplication(
  postingId: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(postingId)) {
    return { ok: false, message: "募集が見つかりませんでした。" };
  }
  try {
    await apiSend(
      "POST",
      `/api/job-postings/${postingId}/applications`,
      { worker_id: text(formData, "worker_id") },
      applicationResponse,
    );
  } catch (e) {
    return toInPageFormState(e, formData);
  }
  revalidatePath(postingPath(postingId));
  revalidatePath("/job-postings");
  // 成功するとページ側の key（応募数）が変わってフォームが作り直されるので、成功の文言は出さない（追加した行が表に出る）
  return { ok: true };
}

/** 承認・却下・キャンセルの共通部分。postingId は再検証するパスを決めるためだけに使う */
async function transition(
  applicationId: number,
  postingId: number,
  verb: "confirm" | "reject" | "cancel",
  body: Record<string, string | null> | undefined,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(applicationId) || !isValidId(postingId)) {
    return invalidId;
  }
  try {
    await apiSend(
      "POST",
      `/api/applications/${applicationId}/${verb}`,
      body,
      applicationResponse,
    );
  } catch (e) {
    return toInPageFormState(e, formData);
  }
  revalidatePath(postingPath(postingId));
  revalidatePath("/job-postings");
  // 行の状態が変わるので成功の文言は出さない（Alert は message がなければ何も出さない）
  return { ok: true };
}

/**
 * 承認。詳細ページで confirmApplication.bind(null, application.id, posting.id) にして渡す。
 * 定員超過は「定員に達しているため承認できません。」の 422（定員の判定は API だけ）
 */
export async function confirmApplication(
  applicationId: number,
  postingId: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  return transition(applicationId, postingId, "confirm", undefined, formData);
}

/** 却下。bind の形は承認と同じ */
export async function rejectApplication(
  applicationId: number,
  postingId: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  return transition(applicationId, postingId, "reject", undefined, formData);
}

/**
 * キャンセル。送るのは reason（company / worker）だけ。
 * 未選択・不正な値は errors.reason の 422（必須の判定は Laravel）
 */
export async function cancelApplication(
  applicationId: number,
  postingId: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  return transition(
    applicationId,
    postingId,
    "cancel",
    { reason: text(formData, "reason") },
    formData,
  );
}
