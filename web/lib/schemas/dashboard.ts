import { z } from "zod";
import { single } from "./common";

// ダッシュボード（docs/01 の API 契約の GET /api/dashboard）。
// fill_rate（充足率）と pending_applications_count（未処理の応募数）は API が数える。web で計算しない

export const upcomingPostingSchema = z.object({
  id: z.number().int(),
  work_date: z.iso.date(),
  /** `09:00`（秒なし） */
  start_time: z.iso.time({ precision: -1 }),
  end_time: z.iso.time({ precision: -1 }),
  job_title: z.string(),
  location_name: z.string(),
  capacity: z.number().int(),
  confirmed_count: z.number().int(),
  /** 整数の％。満員でなければ 99 が上限（API が決める） */
  fill_rate: z.number().int(),
});
export type UpcomingPosting = z.infer<typeof upcomingPostingSchema>;

export const dashboardSchema = z.object({
  upcoming_postings: z.array(upcomingPostingSchema),
  pending_applications_count: z.number().int(),
  /** 集計した時刻（キャッシュされていればキャッシュした時刻）。「いつの値か」を画面に出す。offset を許さないと +09:00 付きが弾かれる */
  generated_at: z.iso.datetime({ offset: true }),
});

export const dashboardResponse = single(dashboardSchema);
