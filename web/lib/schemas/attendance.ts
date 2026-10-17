import { z } from "zod";
import { single } from "./common";

// 勤怠・報酬（docs/01 の API 契約の AttendanceResource）。型は z.infer からだけ作る

export const attendanceSchema = z.object({
  /** 日時（ISO 8601） */
  clock_in: z.iso.datetime({ offset: true }),
  clock_out: z.iso.datetime({ offset: true }),
  is_overnight: z.boolean(),
  break_minutes: z.number().int(),
  worked_minutes: z.number().int(),
  wage_pay: z.number().int(),
  transport_allowance: z.number().int(),
  total_pay: z.number().int(),
});
export type Attendance = z.infer<typeof attendanceSchema>;

export const attendanceResponse = single(attendanceSchema);
