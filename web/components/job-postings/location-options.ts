import "server-only";

import { apiGet } from "@/lib/api";
import { locationListResponse } from "@/lib/schemas/location";

// 募集の登録・編集・絞り込みで使う拠点の選択肢（Server Component から呼ぶ）。
// GET /api/locations はページありなので、上限の 100 件を 1 ページで取る（docs/01 6.1）

/** 拠点の一覧取得で 1 ページに取る件数（API の per_page の上限） */
const LOCATION_OPTIONS_PER_PAGE = 100;

export async function fetchLocationOptions(): Promise<
  { value: number; label: string }[]
> {
  const { data } = await apiGet(
    `/api/locations?per_page=${LOCATION_OPTIONS_PER_PAGE}`,
    locationListResponse,
  );
  return data.map((location) => ({ value: location.id, label: location.name }));
}
