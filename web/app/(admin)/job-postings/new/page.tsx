import { fetchLocationOptions } from "@/components/job-postings/location-options";
import { JobPostingForm } from "@/components/job-postings/JobPostingForm";
import { PageHeader } from "@/components/ui/PageHeader";
import { createJobPosting } from "@/lib/actions/job-posting";

// 募集の登録（F-05）。POST /api/job-postings → 成功したら作った募集の詳細へ。
// 拠点は GET /api/locations から選ぶ

export default async function NewJobPostingPage() {
  const locations = await fetchLocationOptions();

  return (
    <div className="space-y-6">
      <PageHeader title="募集を登録" />
      <JobPostingForm
        action={createJobPosting}
        locations={locations}
        submitLabel="登録する"
      />
    </div>
  );
}
