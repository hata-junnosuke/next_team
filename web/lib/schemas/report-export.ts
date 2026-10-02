import { z } from "zod";
import { enumValueSchema, list, single } from "./common";

// 月次集計の非同期出力（docs/01 6.9 の ReportExportResource）。型は z.infer からだけ作る

export const reportExportSchema = z.object({
  id: z.number().int(),
  /** `2026-09` */
  month: z.string(),
  /** queued / processing / completed / failed（docs/01 4.1 の ReportExportStatus） */
  status: enumValueSchema,
  /** 0〜100。意味を持つのは queued / processing のときだけ（表示の判断は status で行う） */
  progress: z.number().int(),
  /** 完了するまでは null */
  row_count: z.number().int().nullable(),
  /** 依頼日時 */
  requested_at: z.iso.datetime({ offset: true }),
  completed_at: z.iso.datetime({ offset: true }).nullable(),
  /**
   * completed のときだけ入る。取得のたびに作り直される 30 分有効の署名付き URL。
   * <a href> にそのまま入れるので http / https 以外（javascript: など）は parse で弾く
   */
  download_url: z.url({ protocol: /^https?$/ }).nullable(),
});
export type ReportExport = z.infer<typeof reportExportSchema>;

export const reportExportResponse = single(reportExportSchema);
/** GET /api/report-exports（新しい順、最大 20 件。ページなし） */
export const reportExportListResponse = list(reportExportSchema);
