import { WorkerForm } from "@/components/workers/WorkerForm";
import { createWorker } from "@/lib/actions/worker";

// ワーカーの登録（F-02）。POST /api/workers → 成功したら一覧へ

export default function NewWorkerPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold whitespace-nowrap">
        ワーカーを登録
      </h1>
      <WorkerForm action={createWorker} submitLabel="登録する" />
    </div>
  );
}
