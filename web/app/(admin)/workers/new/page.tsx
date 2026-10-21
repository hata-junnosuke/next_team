import { WorkerForm } from "@/components/workers/WorkerForm";
import { PageHeader } from "@/components/ui/PageHeader";
import { createWorker } from "@/lib/actions/worker";

// ワーカーの登録（F-02）。POST /api/workers → 成功したら一覧へ

export default function NewWorkerPage() {
  return (
    <div className="space-y-6">
      <PageHeader title="ワーカーを登録" />
      <WorkerForm action={createWorker} submitLabel="登録する" />
    </div>
  );
}
