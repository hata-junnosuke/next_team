import { z } from "zod";
import { attendanceSchema } from "./attendance";
import { enumValueSchema, list, single } from "./common";

// 応募・確定（docs/01 6.5 の ApplicationResource）。型は z.infer からだけ作る

export const applicationSchema = z.object({
  id: z.number().int(),
  worker: z.object({ id: z.number().int(), name: z.string() }),
  status: enumValueSchema,
  /** 日時（ISO 8601）。確定していなければ null */
  confirmed_at: z.iso.datetime({ offset: true }).nullable(),
  confirmed_hourly_wage: z.number().int().nullable(),
  confirmed_transport_allowance: z.number().int().nullable(),
  cancel_reason: enumValueSchema.nullable(),
  cancelled_at: z.iso.datetime({ offset: true }).nullable(),
  attendance: attendanceSchema.nullable(),
  /** 「状態として可能か」だけ（定員は見ない。定員エラーは 422 の message） */
  can: z.object({
    confirm: z.boolean(),
    reject: z.boolean(),
    cancel: z.boolean(),
    attend: z.boolean(),
  }),
});
export type Application = z.infer<typeof applicationSchema>;

export const applicationResponse = single(applicationSchema);
export const applicationListResponse = list(applicationSchema);
