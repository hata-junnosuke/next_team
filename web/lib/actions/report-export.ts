"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { apiSend, toFormState } from "@/lib/api";
import { text } from "@/lib/actions/helpers";
import type { FormState } from "@/lib/form-state";
import { reportExportResponse } from "@/lib/schemas/report-export";

// 月次集計の CSV を非同期で生成する依頼（F-23。docs/01 の API 契約の非同期出力、Server Action と FormState）。
// API は受付だけして 202 を返す（生成はキューのジョブ）。状態は出力一覧で確かめる。
// 「同じ月の出力が生成中です。」（422）や month の形の検証は Laravel が正。web で同じ判定をしない

/** 202 の応答を parse して形を確かめてから一覧へ移る（status は queued とは限らないので見ない）。?requested= は表示にだけ使う */
export async function requestReportExport(
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  let month: string;
  try {
    const { data } = await apiSend(
      "POST",
      "/api/report-exports",
      { month: text(formData, "month") },
      reportExportResponse,
    );
    // 送った値ではなく API が受け付けた月を一覧の「受け付けました」に出す
    month = data.month;
  } catch (e) {
    return toFormState(e, formData);
  }
  revalidatePath("/reports/exports");
  // redirect は例外を投げるので try の外で呼ぶ
  redirect(`/reports/exports?requested=${encodeURIComponent(month)}`);
}
