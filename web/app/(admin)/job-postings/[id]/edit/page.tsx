import Link from "next/link";
import { DuplicateJobPostingForm } from "@/components/job-postings/DuplicateJobPostingForm";
import { fetchLocationOptions } from "@/components/job-postings/location-options";
import { JobPostingForm } from "@/components/job-postings/JobPostingForm";
import { Badge } from "@/components/ui/Badge";
import { buttonClassName } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import {
  duplicateJobPosting,
  updateJobPosting,
} from "@/lib/actions/job-posting";
import { notFound } from "next/navigation";
import { apiGet } from "@/lib/api";
import { date } from "@/lib/format";
import { jobPostingResponse } from "@/lib/schemas/job-posting";

// 募集の編集（F-05）と複製（F-08）。存在しない・他社の募集は API が 404 → lib/api.ts が not-found 画面へ。
// 編集できるかは API の can.edit（中止以外）で出し分ける。複製は中止した募集でもできる

type Props = {
  // Next 16 では params は Promise
  params: Promise<{ id: string }>;
};

export default async function EditJobPostingPage({ params }: Props) {
  const { id } = await params;
  // 数字以外の id は API を呼ばずに not-found にする（存在しない・他社は API の 404 → lib/api.ts）
  if (!/^\d+$/.test(id)) {
    notFound();
  }
  const [{ data: posting }, locations] = await Promise.all([
    apiGet(`/api/job-postings/${id}`, jobPostingResponse),
    fetchLocationOptions(),
  ]);

  return (
    <div className="space-y-6">
      <PageHeader
        title={
          <span className="inline-flex items-center gap-3">
            募集を編集
            {/* 読み上げで見出しと状態の間に区切りを入れる */}
            <span className="sr-only">、</span>
            <Badge kind="jobPosting" status={posting.status} />
          </span>
        }
        actions={
          <Link
            href={`/job-postings/${posting.id}`}
            className={buttonClassName("secondary")}
          >
            詳細へ
          </Link>
        }
      />
      {!posting.can.edit && (
        // Alert と同じ見た目（左のアクセント線 + 文字色）。業務上の注意なので warning
        <p
          role="status"
          className="bg-surface ring-line border-warning text-warning max-w-xl rounded-md border-l-4 px-4 py-3 text-sm font-medium ring-1"
        >
          この募集は「{posting.status.label}
          」のため編集できません。下の複製から別の日の募集を作れます。
        </p>
      )}
      <JobPostingForm
        // id は URL ではなく API が返した値を使う
        action={updateJobPosting.bind(null, posting.id)}
        locations={locations}
        posting={posting}
        submitLabel="保存する"
        editable={posting.can.edit}
      />

      <div id="duplicate">
        <Card title="複製" className="max-w-xl">
          <div className="space-y-4">
            <p className="text-ink-muted text-sm">
              {date(posting.work_date)}の「{posting.job_title}
              」と同じ内容（拠点・時間・募集人数・時給・交通費）で、別の勤務日の募集を作ります。応募は引き継ぎません。
            </p>
            <DuplicateJobPostingForm
              action={duplicateJobPosting.bind(null, posting.id)}
            />
          </div>
        </Card>
      </div>
    </div>
  );
}
