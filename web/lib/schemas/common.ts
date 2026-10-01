import { z } from "zod";

// docs/01 6.1 の共通の形。機能ごとのスキーマはここの部品を組み合わせて作る

/** 状態などの Enum。ラベルは API が返したものをそのまま表示する */
export const enumValueSchema = z.object({
  value: z.string(),
  label: z.string(),
});
export type EnumValue = z.infer<typeof enumValueSchema>;

/** ページありの一覧の meta（Laravel の他のキーは捨てる） */
export const paginationMetaSchema = z.object({
  current_page: z.number(),
  last_page: z.number(),
  per_page: z.number(),
  total: z.number(),
});
export type PaginationMeta = z.infer<typeof paginationMetaSchema>;

/** `{"data": {...}}` */
export function single<T extends z.ZodType>(item: T) {
  return z.object({ data: item });
}

/** `{"data": [...]}`（ページなし） */
export function list<T extends z.ZodType>(item: T) {
  return z.object({ data: z.array(item) });
}

/** `{"data": [...], "meta": {...}, "links": {...}}`（ページあり。links は使わないので捨てる） */
export function paginated<T extends z.ZodType>(item: T) {
  return z.object({ data: z.array(item), meta: paginationMetaSchema });
}

/** エラー `{"message": "...", "errors": {"項目名": ["..."]}}`。errors は空オブジェクトのことがある */
export const apiErrorSchema = z.object({
  message: z.string(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
});

// API の message をそのまま画面に出してよい状態コード。
// 422（入力・業務ルール）/ 429（試行回数）/ 404（「見つかりませんでした。」）だけ。
// 500 系などは内部の文言（例外メッセージや英語）が混ざるので固定文言にする
const DISPLAYABLE_STATUSES = [404, 422, 429];

/** エラー応答の本文 → 画面に出す {message, errors}。lib/api.ts と lib/browser-api.ts の共通 */
export function toErrorDisplay(
  status: number,
  json: unknown,
): { message: string; errors: Record<string, string[]> } {
  const fallback = { message: `通信に失敗しました（${status}）。`, errors: {} };
  if (!DISPLAYABLE_STATUSES.includes(status)) {
    return fallback;
  }
  const parsed = apiErrorSchema.safeParse(json);
  if (!parsed.success) {
    return fallback;
  }
  return { message: parsed.data.message, errors: parsed.data.errors ?? {} };
}
