import { z } from "zod";
import { enumValueSchema, list, paginated, single } from "./common";

// 募集（docs/01 6.4 の JobPostingResource）。型は z.infer からだけ作る

export const jobPostingSchema = z.object({
  id: z.number().int(),
  location: z.object({ id: z.number().int(), name: z.string() }),
  job_title: z.string(),
  /** `2026-09-20` */
  work_date: z.iso.date(),
  /** `09:00` */
  start_time: z.iso.time({ precision: -1 }),
  end_time: z.iso.time({ precision: -1 }),
  capacity: z.number().int(),
  hourly_wage: z.number().int(),
  transport_allowance: z.number().int(),
  status: enumValueSchema,
  /** 却下・キャンセルを含む全件の応募数 */
  applications_count: z.number().int(),
  /** 確定の数 */
  confirmed_count: z.number().int(),
  /** ボタンの出し分けにだけ使う（判定は API。最終的には 422） */
  can: z.object({
    edit: z.boolean(),
    close: z.boolean(),
    cancel: z.boolean(),
    apply: z.boolean(),
  }),
});
export type JobPosting = z.infer<typeof jobPostingSchema>;

export const jobPostingResponse = single(jobPostingSchema);
export const jobPostingListResponse = paginated(jobPostingSchema);

/** GET /api/job-postings/{id}/applicable-workers（有効で、まだ応募していないワーカー。ページなし） */
export const applicableWorkerSchema = z.object({
  id: z.number().int(),
  name: z.string(),
});
export type ApplicableWorker = z.infer<typeof applicableWorkerSchema>;
export const applicableWorkerListResponse = list(applicableWorkerSchema);
