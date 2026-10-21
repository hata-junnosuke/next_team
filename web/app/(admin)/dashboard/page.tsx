import Link from "next/link";
import { buttonClassName } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { Table, Td } from "@/components/ui/Table";
import { apiGet } from "@/lib/api";
import { date, dateTime } from "@/lib/format";
import { dashboardResponse } from "@/lib/schemas/dashboard";

// ダッシュボード（F-19）。GET /api/dashboard をそのまま表示する。
// 充足率・確定数・未処理の応募数は API が数える（web で計算しない）。
// API 側でキャッシュされるので、generated_at で「いつの値か」を出す

export default async function DashboardPage() {
  const { data } = await apiGet("/api/dashboard", dashboardResponse);

  return (
    <div className="space-y-6">
      <PageHeader
        title="ダッシュボード"
        description={
          <span className="whitespace-nowrap">
            集計時刻: {dateTime(data.generated_at)}
          </span>
        }
      />

      {/* Card の className は幅の追加だけに使うので、並べ方は外側の div で決める */}
      <div className="inline-block">
        <Card className="min-w-56">
          <p className="text-ink-muted text-xs font-medium whitespace-nowrap">
            未処理の応募（応募中）
          </p>
          <p className="text-ink mt-1 whitespace-nowrap">
            <span className="font-num text-4xl leading-none font-semibold">
              {data.pending_applications_count}
            </span>
            <span className="ml-1 text-sm">件</span>
          </p>
        </Card>
      </div>

      <section className="space-y-3">
        <h2 className="text-ink text-base font-semibold whitespace-nowrap">
          今日以降の募集
        </h2>
        <Table
          head={["勤務日", "時間", "職種", "拠点", "確定 / 定員", "充足率", ""]}
          empty="今日以降の募集はありません。"
        >
          {data.upcoming_postings.map((posting) => (
            <tr key={posting.id}>
              <Td>
                <span className="font-num text-base">
                  {date(posting.work_date)}
                </span>
              </Td>
              <Td>
                <span className="font-num text-base">
                  {posting.start_time}〜{posting.end_time}
                </span>
              </Td>
              <Td>{posting.job_title}</Td>
              <Td>{posting.location_name}</Td>
              <Td align="right">
                <span className="font-num text-base">
                  {posting.confirmed_count} / {posting.capacity}
                </span>
              </Td>
              <Td>
                <div className="flex items-center gap-3">
                  {/* 幅は API の fill_rate（整数の％）を使う。値の計算はしない。
                      想定外の値でバーがはみ出さないよう幅だけ 0〜100 に収める（数字は API の値のまま出す） */}
                  <div
                    aria-hidden="true"
                    className="bg-surface-muted ring-line h-1.5 w-24 overflow-hidden rounded-full ring-1"
                  >
                    <div
                      className="bg-sidebar h-full rounded-full"
                      style={{
                        width: `${Math.min(100, Math.max(0, posting.fill_rate))}%`,
                      }}
                    />
                  </div>
                  <span className="font-num w-10 text-right text-base">
                    {posting.fill_rate}%
                  </span>
                </div>
              </Td>
              <Td>
                <Link
                  href={`/job-postings/${posting.id}`}
                  className={buttonClassName("secondary", "sm")}
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
