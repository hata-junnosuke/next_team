import { MonthPicker } from "@/components/reports/MonthPicker";
import { SummaryTable } from "@/components/reports/SummaryTable";
import { Alert } from "@/components/ui/Alert";
import { ApiError, apiGet } from "@/lib/api";
import { monthlyReportResponse } from "@/lib/schemas/report";

// 月次集計（F-17）と CSV ダウンロード（F-18）。GET /api/reports/monthly?month=YYYY-MM をそのまま表示する。
// CSV は同じフォルダの csv/route.ts（Route Handler）が Cookie を転送して中継する（docs/01 12 章）

type Props = {
  searchParams: Promise<{ month?: string | string[] }>;
};

const monthFormat = new Intl.DateTimeFormat("ja-JP", {
  timeZone: "Asia/Tokyo",
  year: "numeric",
  month: "2-digit",
});

/** 日本時間の今月を `YYYY-MM` で返す（?month がないときの既定値） */
function currentMonth(): string {
  const parts = Object.fromEntries(
    monthFormat.formatToParts(new Date()).map((p) => [p.type, p.value]),
  );
  return `${parts.year}-${parts.month}`;
}

/** 月の取得。形が不正（422）なら message を返して画面に出す。それ以外の失敗は投げ直す */
async function fetchReport(month: string) {
  try {
    const { data } = await apiGet(
      `/api/reports/monthly?month=${encodeURIComponent(month)}`,
      monthlyReportResponse,
    );
    return { report: data, error: undefined };
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      return { report: undefined, error: e.message };
    }
    throw e;
  }
}

export default async function MonthlyReportPage({ searchParams }: Props) {
  const { month: raw } = await searchParams;
  // ?month=a&month=b のように配列で来たら先頭を使う。形の妥当性は Laravel に任せる
  const month = (Array.isArray(raw) ? raw[0] : raw) || currentMonth();
  const { report, error } = await fetchReport(month);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <h1 className="text-xl font-semibold whitespace-nowrap">月次集計</h1>
        <MonthPicker month={month} />
      </div>

      {error !== undefined && <Alert state={{ ok: false, message: error }} />}

      {report && (
        <>
          <div className="flex items-center justify-between gap-4">
            <p className="text-sm whitespace-nowrap text-gray-600">
              対象月: {report.month}
            </p>
            {/* ファイルの受け渡しなので Link ではなく a。Route Handler が text/csv を返す */}
            <a
              href={`/reports/monthly/csv?month=${encodeURIComponent(report.month)}`}
              className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium whitespace-nowrap text-white hover:bg-blue-700"
            >
              CSV をダウンロード
            </a>
          </div>

          <section className="space-y-2">
            <h2 className="text-base font-semibold whitespace-nowrap">
              ワーカー別
            </h2>
            <SummaryTable
              nameLabel="ワーカー"
              rows={report.by_worker.map((r) => ({ key: r.worker_id, ...r }))}
              total={report.total}
              empty="この月の勤怠はありません。"
            />
          </section>

          <section className="space-y-2">
            <h2 className="text-base font-semibold whitespace-nowrap">
              拠点別
            </h2>
            <SummaryTable
              nameLabel="拠点"
              rows={report.by_location.map((r) => ({
                key: r.location_id,
                ...r,
              }))}
              total={report.total}
              empty="この月の勤怠はありません。"
            />
          </section>
        </>
      )}
    </div>
  );
}
