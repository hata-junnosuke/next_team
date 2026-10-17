import Link from "next/link";
import { ExportTable } from "@/components/reports/ExportTable";
import { Alert } from "@/components/ui/Alert";
import { apiGet } from "@/lib/api";
import { reportExportListResponse } from "@/lib/schemas/report-export";
import { isInProgressExportStatus } from "@/lib/status";

// 月次集計の非同期出力の一覧（F-23）。GET /api/report-exports（新しい順・最大 20 件）をそのまま表示する。
// 依頼は月次集計の画面の「CSV を生成（非同期）」から。成功すると ?requested={month} 付きでここへ来る。
// 進み具合は開き直すと最新になる（自動で切り替えるのは F-25）。
// download_url は取得のたびに 30 分有効で作り直される

type Props = {
  searchParams: Promise<{ requested?: string | string[] }>;
};

/** ?requested= は「受け付けました」の表示にだけ使う。月の形（YYYY-MM）でなければ出さない */
function requestedMonth(raw: string | string[] | undefined): string | null {
  const value = Array.isArray(raw) ? raw[0] : raw;
  return value !== undefined && /^\d{4}-\d{2}$/.test(value) ? value : null;
}

export default async function ReportExportsPage({ searchParams }: Props) {
  const { requested } = await searchParams;
  const month = requestedMonth(requested);
  const { data } = await apiGet(
    "/api/report-exports",
    reportExportListResponse,
  );
  const hasInProgress = data.some((exp) =>
    isInProgressExportStatus(exp.status.value),
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <h1 className="text-xl font-semibold whitespace-nowrap">非同期出力</h1>
        <Link
          href="/reports/monthly"
          className="text-sm font-medium whitespace-nowrap text-blue-600 hover:underline"
        >
          月次集計へ
        </Link>
      </div>

      {month !== null && (
        <Alert
          state={{
            ok: true,
            message: `${month} の出力を受け付けました。生成が終わるとダウンロードできます。`,
          }}
        />
      )}

      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        {/* 同じページを開き直して Server Component を取り直す（受付の表示は消える） */}
        <Link
          href="/reports/exports"
          className="text-sm font-medium whitespace-nowrap text-blue-600 hover:underline"
        >
          最新の状態に更新
        </Link>
        {hasInProgress && (
          <p className="text-sm text-gray-600">
            生成中の出力があります。進み具合は更新すると最新になります。
          </p>
        )}
      </div>

      <p className="text-sm text-gray-600">
        新しい順に最大 20 件を表示します。ダウンロードのリンクは表示してから 30
        分有効です。期限が切れたら更新してください。
      </p>

      <ExportTable exports={data} />
    </div>
  );
}
