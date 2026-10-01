import Link from "next/link";
import { Table, Td } from "@/components/ui/Table";
import { apiGet } from "@/lib/api";
import { date, dateTime } from "@/lib/format";
import { dashboardResponse } from "@/lib/schemas/dashboard";

// ダッシュボード（F-19）。GET /api/dashboard をそのまま表示する。
// 充足率・確定数・未処理の応募数は API が数える（web で計算しない）。
// P4 から API 側でキャッシュされるので、generated_at で「いつの値か」を出す

const linkClass = "whitespace-nowrap text-blue-700 hover:underline";

export default async function DashboardPage() {
  const { data } = await apiGet("/api/dashboard", dashboardResponse);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-baseline justify-between gap-4">
        <h1 className="text-xl font-semibold whitespace-nowrap">
          ダッシュボード
        </h1>
        <p className="text-sm whitespace-nowrap text-gray-600">
          集計時刻: {dateTime(data.generated_at)}
        </p>
      </div>

      <section className="inline-block rounded-md border border-gray-200 bg-white px-6 py-4">
        <p className="text-sm whitespace-nowrap text-gray-600">
          未処理の応募（応募中）
        </p>
        <p className="text-2xl font-semibold whitespace-nowrap tabular-nums">
          {data.pending_applications_count}件
        </p>
      </section>

      <section className="space-y-2">
        <h2 className="text-base font-semibold whitespace-nowrap">
          今日以降の募集
        </h2>
        <Table
          head={["勤務日", "時間", "職種", "拠点", "確定 / 定員", "充足率", ""]}
          empty="今日以降の募集はありません。"
        >
          {data.upcoming_postings.map((posting) => (
            <tr key={posting.id}>
              <Td>{date(posting.work_date)}</Td>
              <Td>
                {posting.start_time}〜{posting.end_time}
              </Td>
              <Td>{posting.job_title}</Td>
              <Td>{posting.location_name}</Td>
              <Td align="right">
                {posting.confirmed_count} / {posting.capacity}
              </Td>
              <Td align="right">{posting.fill_rate}%</Td>
              <Td>
                <Link
                  href={`/job-postings/${posting.id}`}
                  className={linkClass}
                >
                  詳細
                </Link>
              </Td>
            </tr>
          ))}
        </Table>
      </section>
    </div>
  );
}
