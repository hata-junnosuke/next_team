import { z } from "zod";
import { enumValueSchema, paginated } from "./common";

// 通知履歴（docs/01 6.10 の NotificationLogResource）。型は z.infer からだけ作る。
// null になりうるのは sent_at・failed_at・error の 3 つだけ（P5-01 で決定）

export const notificationLogSchema = z.object({
  id: z.number().int(),
  /** application_confirmed / posting_cancelled / shift_reminder / export_ready（docs/01 4.1 の NotificationKind） */
  kind: enumValueSchema,
  recipient: z.object({
    /** value は morph の短い名前、label は「ワーカー」「管理者」 */
    type: enumValueSchema,
    /** 今の名前（宛先が消えていたら記録時のアドレス） */
    name: z.string(),
    /** 記録時の宛先アドレス（実際に送った先） */
    email: z.string(),
  }),
  subject: z.object({
    /** value は morph の短い名前、label は「応募」「募集」「集計出力」 */
    type: enumValueSchema,
    id: z.number().int(),
    /** 表示用の文字列（例: 「倉庫内作業 2026/10/10（土） / 山田 太郎」「削除された応募」） */
    label: z.string(),
  }),
  /** pending / sent / failed（docs/01 4.1 の NotificationStatus） */
  status: enumValueSchema,
  /** 送信済みのときだけ入る */
  sent_at: z.iso.datetime({ offset: true }).nullable(),
  /** 失敗のときだけ入る */
  failed_at: z.iso.datetime({ offset: true }).nullable(),
  error: z.string().nullable(),
  created_at: z.iso.datetime({ offset: true }),
});
export type NotificationLog = z.infer<typeof notificationLogSchema>;

/** GET /api/notification-logs?kind=&status=&page=&per_page=（新しい順。ページあり） */
export const notificationLogListResponse = paginated(notificationLogSchema);
