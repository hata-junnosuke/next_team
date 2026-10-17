import { z } from "zod";
import { paginated, single } from "./common";

// 拠点（docs/01 の API 契約の LocationResource）。型は z.infer からだけ作る

export const locationSchema = z.object({
  id: z.number(),
  name: z.string(),
  address: z.string().nullable(),
  job_postings_count: z.number(),
  /** 募集に使われていなければ true。削除ボタンの出し分けにだけ使う（判定は API） */
  deletable: z.boolean(),
});
export type Location = z.infer<typeof locationSchema>;

export const locationResponse = single(locationSchema);
export const locationListResponse = paginated(locationSchema);
