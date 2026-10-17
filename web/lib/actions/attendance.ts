"use server";

import { revalidatePath } from "next/cache";
import { apiSend } from "@/lib/api";
import { isValidId, text, toInPageFormState } from "@/lib/actions/helpers";
import type { FormState } from "@/lib/form-state";
import { attendanceResponse } from "@/lib/schemas/attendance";

// 勤怠の入力（F-15）。PUT /api/applications/{id}/attendance（作成でも更新でも 200）（docs/01 の API 契約の勤怠・報酬、Server Action と FormState）。
// 報酬（F-16）は API が計算して返す。web では計算しない。
// 形式・同時刻（errors.clock_out）・休憩超過（errors.break_minutes）・確定済みでない応募（errors が空）は
// すべて Laravel の 422。深夜跨ぎ（退勤 <= 出勤なら翌日）の判定も API

/** 送る項目だけを明示する。値は `HH:MM` と分の文字列のまま送る（変換・検証は Laravel） */
function attendanceBody(formData: FormData) {
  return {
    clock_in: text(formData, "clock_in"),
    clock_out: text(formData, "clock_out"),
    break_minutes: text(formData, "break_minutes"),
  };
}

/**
 * 詳細ページで saveAttendance.bind(null, application.id, posting.id) にして渡す。
 * postingId は再検証するパスを決めるためだけに使う。保存後は同じページに留まる
 */
export async function saveAttendance(
  applicationId: number,
  postingId: number,
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  if (!isValidId(applicationId) || !isValidId(postingId)) {
    return { ok: false, message: "応募が見つかりませんでした。" };
  }
  try {
    await apiSend(
      "PUT",
      `/api/applications/${applicationId}/attendance`,
      attendanceBody(formData),
      attendanceResponse,
    );
  } catch (e) {
    return toInPageFormState(e, formData);
  }
  revalidatePath(`/job-postings/${postingId}`);
  return { ok: true, message: "勤怠を保存しました。" };
}
