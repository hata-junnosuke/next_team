import Link from "next/link";
import { notFound } from "next/navigation";
import { ActionButton } from "@/components/job-postings/ActionButton";
import {
  APPLICATION_HEAD,
  ApplicationRow,
} from "@/components/job-postings/ApplicationRow";
import { ApplyWorkerForm } from "@/components/job-postings/ApplyWorkerForm";
import { Badge } from "@/components/ui/Badge";
import { buttonClassName } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { Table } from "@/components/ui/Table";
import { addApplication } from "@/lib/actions/application";
import { cancelJobPosting, closeJobPosting } from "@/lib/actions/job-posting";
import { apiGet } from "@/lib/api";
import { date, yen } from "@/lib/format";
import { applicationListResponse } from "@/lib/schemas/application";
import {
  applicableWorkerListResponse,
  jobPostingResponse,
} from "@/lib/schemas/job-posting";

// 募集詳細（F-07、F-09〜F-16。docs/01 の画面と使う API で中心の画面）。
// 募集・応募一覧・応募できるワーカーを並行して取る。存在しない・他社の募集は API の 404 → not-found。
// ボタンを押せるかは API の can.* だけで決める（定員・状態遷移の判定は API の 422）。
// 状態ボタンと勤怠の保存は redirect せず、Server Action がこのページを再検証する

type Props = {
  // Next 16 では params は Promise
  params: Promise<{ id: string }>;
};

export default async function JobPostingDetailPage({ params }: Props) {
  const { id } = await params;
  // 数字以外の id は API を呼ばずに not-found にする（存在しない・他社は API の 404 → lib/api.ts）
  if (!/^\d+$/.test(id)) {
    notFound();
  }
  const [{ data: posting }, { data: applications }, { data: workers }] =
    await Promise.all([
      apiGet(`/api/job-postings/${id}`, jobPostingResponse),
      apiGet(`/api/job-postings/${id}/applications`, applicationListResponse),
      apiGet(
        `/api/job-postings/${id}/applicable-workers`,
        applicableWorkerListResponse,
      ),
    ]);

  // [ラベル, 値, 数字の字形（font-num）にするか]
  const info: [string, string, boolean][] = [
    ["勤務日", date(posting.work_date), false],
    ["時間", `${posting.start_time}〜${posting.end_time}`, true],
    ["拠点", posting.location.name, false],
    ["職種名", posting.job_title, false],
    ["定員", `${posting.capacity}名`, true],
    ["時給", yen(posting.hourly_wage), true],
    ["交通費", yen(posting.transport_allowance), true],
    ["応募数", `${posting.applications_count}件`, true],
    ["確定 / 定員", `${posting.confirmed_count} / ${posting.capacity}`, true],
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title={
          <span className="inline-flex items-center gap-3">
            {posting.job_title}
            {/* 読み上げで見出しと状態の間に区切りを入れる */}
            <span className="sr-only">、</span>
            <Badge kind="jobPosting" status={posting.status} />
          </span>
        }
        actions={
          // 状態ボタンの下に 422 の理由が出るので上端でそろえる
          <div className="flex flex-wrap items-start gap-2">
            <Link
              href={`/job-postings/${posting.id}/edit`}
              className={buttonClassName("secondary")}
            >
              {/* 中止した募集は編集できないが複製はできる（編集画面に複製のフォームがある） */}
              {posting.can.edit ? "編集・複製" : "複製"}
            </Link>
            <Link href="/job-postings" className={buttonClassName("secondary")}>
              一覧へ
            </Link>
            {/* key: 状態が変わったら（再検証で）作り直し、古い 422 の表示を消す */}
            <ActionButton
              key={`close-${posting.status.value}`}
              action={closeJobPosting.bind(null, posting.id)}
              label="締め切る"
              pendingLabel="締切中…"
              disabled={!posting.can.close}
              confirmMessage={`募集「${posting.job_title}」を締め切ります。新しい応募は受け付けなくなります（応募中の応募は承認できます）。よろしいですか？`}
              align="end"
            />
            <ActionButton
              key={`cancel-${posting.status.value}`}
              action={cancelJobPosting.bind(null, posting.id)}
              label="中止する"
              pendingLabel="中止中…"
              variant="danger"
              disabled={!posting.can.cancel}
              confirmMessage={`募集「${posting.job_title}」を中止します。確定した応募は企業都合のキャンセルに、応募中の応募は却下になります。よろしいですか？`}
              align="end"
            />
          </div>
        }
      />

      <Card>
        <dl className="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-3">
          {info.map(([label, value, num]) => (
            <div key={label} className="space-y-1">
              <dt className="text-ink-muted text-xs font-medium whitespace-nowrap">
                {label}
              </dt>
              <dd
                className={`text-ink whitespace-nowrap ${num ? "font-num text-lg leading-tight font-medium" : "text-sm font-medium"}`}
              >
                {value}
              </dd>
            </div>
          ))}
        </dl>
      </Card>

      <Card title="応募の追加">
        <div className="space-y-3">
          {!posting.can.apply && (
            <p className="text-ink-muted text-sm">
              この募集は「{posting.status.label}」のため応募を追加できません。
            </p>
          )}
          <ApplyWorkerForm
            key={`apply-${posting.status.value}-${posting.applications_count}`}
            action={addApplication.bind(null, posting.id)}
            workers={workers.map((w) => ({ value: w.id, label: w.name }))}
            canApply={posting.can.apply}
          />
        </div>
      </Card>

      {/* Table は自前の枠を持つので Card で包まず、見出しだけ外に置く */}
      <section className="space-y-3">
        <h2 className="text-ink text-sm font-semibold whitespace-nowrap">
          応募一覧・勤怠と報酬
        </h2>
        <Table head={APPLICATION_HEAD} empty="まだ応募はありません。">
          {applications.map((application) => (
            <ApplicationRow
              key={application.id}
              application={application}
              postingId={posting.id}
              confirmedCount={posting.confirmed_count}
            />
          ))}
        </Table>
      </section>
    </div>
  );
}
