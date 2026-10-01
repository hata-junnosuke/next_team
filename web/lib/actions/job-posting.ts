"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { apiSend, toFormState } from "@/lib/api";
import type { FormState } from "@/lib/form-state";
import { jobPostingResponse } from "@/lib/schemas/job-posting";

// 募集の登録・編集（F-05）・複製（F-08）（docs/01 6.4、7.3）。
// Server Action は公開エンドポイントと同じ扱い。引数（bind した id を含む）は信用しない。
// ここで見るのは id の形だけで、存在しない・他社の募集は Laravel が 404 を返すのに任せる。
// 検証（必須・終了 > 開始・最低賃金・上限・自社の拠点・中止済みは編集不可）も Laravel が正

const invalidId: FormState = {
  ok: false,
  message: "募集が見つかりませんでした。",
};

/** bind で渡された id の形だけを確かめる（正の整数でなければ API を呼ばない） */
function isValidId(id: unknown): id is number {
  return typeof id === "number" && Number.isSafeInteger(id) && id > 0;
}

/** formData の値が文字列でなければ（未送信・ファイル）null にする */
function text(formData: FormData, name: string): string | null {
  const value = formData.get(name);
  return typeof value === "string" ? value : null;
}

/**
 * 送る項目だけを明示して取り出す（Next が付ける $ACTION_ のキーや status などは送らない）。
 * 数値は文字列のまま送る（Laravel の integer 検証が数値文字列を受け付ける）。
 * 交通費の空欄は Laravel が 0 にする
 */
function jobPostingBody(formData: FormData) {
  return {
    location_id: text(formData, "location_id"),
    job_title: text(formData, "job_title"),
    work_date: text(formData, "work_date"),
    start_time: text(formData, "start_time"),
    end_time: text(formData, "end_time"),
    capacity: text(formData, "capacity"),
    hourly_wage: text(formData, "hourly_wage"),
    transport_allowance: text(formData, "transport_allowance"),
  };
}

function jobPostingPath(id: number): string {
  return `/api/job-postings/${id}`;
}

/** 201 の data.id（作った募集）の詳細へ移る。応募の追加など次の操作は詳細で行うため（docs/01 7.4） */
export async function createJobPosting(
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  let newId: number;
  try {
    const { data } = await apiSend(
      "POST",
      "/api/job-postings",
      jobPostingBody(formData),
      jobPostingResponse,
    );
    newId = data.id;
  } catch (e) {
    return toFormState(e, formData);
  }
  revalidatePath("/job-postings");
  // redirect は例外を投げるので try の外で呼ぶ
  redirect(`/job-postings/${newId}`);
}

/**
 * 編集ページ（Server Component）で updateJobPosting.bind(null, posting.id) にして渡す。
 * 成功したらその募集の詳細へ移る。中止した募集は API が 422（「中止した募集は編集できません。」）を返し、Alert に出る
 */
export async function updateJobPosting(
  id: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(id)) {
    return invalidId;
  }
  try {
    await apiSend(
      "PUT",
      jobPostingPath(id),
      jobPostingBody(formData),
      jobPostingResponse,
    );
  } catch (e) {
    return toFormState(e, formData);
  }
  revalidatePath("/job-postings");
  revalidatePath(`/job-postings/${id}`);
  redirect(`/job-postings/${id}`);
}

/**
 * 複製（F-08）。Server Component で duplicateJobPosting.bind(null, posting.id) にして渡す。
 * 送るのは勤務日だけ。201 の data.id（新しい募集）の詳細へ移る。
 * 同じ勤務日・最低賃金未満などは Laravel の 422
 */
export async function duplicateJobPosting(
  id: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(id)) {
    return invalidId;
  }
  let newId: number;
  try {
    const { data } = await apiSend(
      "POST",
      `${jobPostingPath(id)}/duplicate`,
      { work_date: text(formData, "work_date") },
      jobPostingResponse,
    );
    newId = data.id;
  } catch (e) {
    return toFormState(e, formData);
  }
  revalidatePath("/job-postings");
  redirect(`/job-postings/${newId}`);
}
