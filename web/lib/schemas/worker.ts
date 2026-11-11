import { z } from "zod";
import { paginated, single } from "./common";

// ワーカー（docs/01 の API 契約の WorkerResource）。型は z.infer からだけ作る

export const workerSchema = z.object({
  id: z.number(),
  name: z.string(),
  email: z.string(),
  phone: z.string().nullable(),
  /** 有効 / 無効。切り替えは PATCH /api/workers/{id}/active だけ */
  is_active: z.boolean(),
  /** 初回登録の日時。null なら未登録 */
  registered_at: z.iso.datetime({ offset: true }).nullable(),
  /** 使える招待のうち最新の期限。使える招待がなければ null */
  invitation_expires_at: z.iso.datetime({ offset: true }).nullable(),
  /** 招待を送り直せるか（判定は API。web はボタンの出し分けにだけ使う） */
  can: z.object({ invite: z.boolean() }),
});
export type Worker = z.infer<typeof workerSchema>;

export const workerResponse = single(workerSchema);
export const workerListResponse = paginated(workerSchema);
