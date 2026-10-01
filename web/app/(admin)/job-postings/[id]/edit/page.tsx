import Link from "next/link";
import { DuplicateJobPostingForm } from "@/components/job-postings/DuplicateJobPostingForm";
import { fetchLocationOptions } from "@/components/job-postings/location-options";
import { JobPostingForm } from "@/components/job-postings/JobPostingForm";
import { Badge } from "@/components/ui/Badge";
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
    <div className="space-y-8">
      <section className="space-y-4">
        <div className="flex items-center gap-3">
          <h1 className="text-xl font-semibold whitespace-nowrap">
            募集を編集
          </h1>
          <Badge kind="jobPosting" status={posting.status} />
          <Link
            href={`/job-postings/${posting.id}`}
            className="text-sm whitespace-nowrap text-blue-700 hover:underline"
          >
            詳細へ
          </Link>
        </div>
        {!posting.can.edit && (
          <p
            role="status"
            className="max-w-xl rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800"
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
      </section>

      <section
        id="duplicate"
        className="space-y-4 border-t border-gray-200 pt-6"
      >
        <div className="space-y-1">
          <h2 className="text-lg font-semibold whitespace-nowrap">複製</h2>
          <p className="text-sm text-gray-600">
            {date(posting.work_date)}の「{posting.job_title}
            」と同じ内容（拠点・時間・募集人数・時給・交通費）で、別の勤務日の募集を作ります。応募は引き継ぎません。
          </p>
        </div>
        <DuplicateJobPostingForm
          action={duplicateJobPosting.bind(null, posting.id)}
        />
      </section>
    </div>
  );
}
