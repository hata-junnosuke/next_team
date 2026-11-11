import { notFound } from "next/navigation";
import { InvitationStatus } from "@/components/workers/InvitationStatus";
import { ResendInvitationButton } from "@/components/workers/ResendInvitationButton";
import { WorkerForm } from "@/components/workers/WorkerForm";
import { PageHeader } from "@/components/ui/PageHeader";
import { resendWorkerInvitation, updateWorker } from "@/lib/actions/worker";
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
      {/* 送り直しの文言がボタンの下に出ても、見出しと状態はボタン（h-8）の高さにそろえたままにする */}
      <div className="flex flex-wrap items-start gap-3">
        <span className="text-ink inline-flex h-8 items-center text-sm font-semibold whitespace-nowrap">
          招待
        </span>
        <span className="inline-flex h-8 items-center">
          <InvitationStatus worker={worker} />
        </span>
        {/* 出すかどうかは API の can.invite だけで決める。id は API が返した値を bind する */}
        {worker.can.invite && (
          <ResendInvitationButton
            action={resendWorkerInvitation.bind(null, worker.id)}
          />
        )}
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
