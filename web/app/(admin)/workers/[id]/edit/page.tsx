import { notFound } from "next/navigation";
import { InvitationStatus } from "@/components/workers/InvitationStatus";
import { WorkerForm } from "@/components/workers/WorkerForm";
import { PageHeader } from "@/components/ui/PageHeader";
import { updateWorker } from "@/lib/actions/worker";
import { apiGet } from "@/lib/api";
import { workerResponse } from "@/lib/schemas/worker";

// ワーカーの編集（F-02）。存在しない・他社のワーカーは API が 404 → lib/api.ts が not-found 画面へ

type Props = {
  // Next 16 では params は Promise
  params: Promise<{ id: string }>;
};

export default async function EditWorkerPage({ params }: Props) {
  const { id } = await params;
  // 形の確認だけ（数字以外は API を呼ばずに not-found）。存在・他社の判定は API の 404
  if (!/^\d+$/.test(id)) {
    notFound();
  }
  const { data: worker } = await apiGet(
    `/api/workers/${encodeURIComponent(id)}`,
    workerResponse,
  );

  return (
    <div className="space-y-6">
      <PageHeader title="ワーカーを編集" />
      {/* 招待の状態。表示は API の registered_at / invitation_expires_at だけで決める */}
      <div className="flex items-center gap-3">
        <span className="text-ink text-sm font-semibold whitespace-nowrap">
          招待
        </span>
        <InvitationStatus worker={worker} />
      </div>
      <WorkerForm
        // id は URL ではなく API が返した値を使う
        action={updateWorker.bind(null, worker.id)}
        worker={worker}
        submitLabel="保存する"
      />
    </div>
  );
}
