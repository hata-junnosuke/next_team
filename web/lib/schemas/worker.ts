import { z } from "zod";
import { paginated, single } from "./common";

// ワーカー（docs/01 6.3 の WorkerResource）。型は z.infer からだけ作る

export const workerSchema = z.object({
  id: z.number(),
  name: z.string(),
  email: z.string(),
  phone: z.string().nullable(),
  /** 有効 / 無効。切り替えは PATCH /api/workers/{id}/active だけ */
  is_active: z.boolean(),
});
export type Worker = z.infer<typeof workerSchema>;

export const workerResponse = single(workerSchema);
export const workerListResponse = paginated(workerSchema);
