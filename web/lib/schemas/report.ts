import { z } from "zod";
import { single } from "./common";

// 月次集計（docs/01 6.7 の GET /api/reports/monthly）。型は z.infer からだけ作る

/** 回数・労働時間（分）・報酬（円）。ワーカー別・拠点別・合計で共通 */
const totalsShape = {
  shifts: z.number().int(),
  worked_minutes: z.number().int(),
  total_pay: z.number().int(),
};

export const workerRowSchema = z.object({
  worker_id: z.number().int(),
  name: z.string(),
  ...totalsShape,
});

export const locationRowSchema = z.object({
  location_id: z.number().int(),
  name: z.string(),
  ...totalsShape,
});

export const monthlyReportSchema = z.object({
  month: z.string(),
  by_worker: z.array(workerRowSchema),
  by_location: z.array(locationRowSchema),
  total: z.object(totalsShape),
});
export type MonthlyReport = z.infer<typeof monthlyReportSchema>;

export const monthlyReportResponse = single(monthlyReportSchema);
