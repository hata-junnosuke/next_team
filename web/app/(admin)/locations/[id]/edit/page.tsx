import { notFound } from "next/navigation";
import { LocationForm } from "@/components/locations/LocationForm";
import { PageHeader } from "@/components/ui/PageHeader";
import { updateLocation } from "@/lib/actions/location";
import { apiGet } from "@/lib/api";
import { locationResponse } from "@/lib/schemas/location";

// 拠点の編集（F-01）。存在しない・他社の拠点は API が 404 → lib/api.ts が not-found 画面へ

type Props = {
  // Next 16 では params は Promise
  params: Promise<{ id: string }>;
};

export default async function EditLocationPage({ params }: Props) {
  const { id } = await params;
  // 形の確認だけ（数字以外は API を呼ばずに not-found）。存在・他社の判定は API の 404
  if (!/^\d+$/.test(id)) {
    notFound();
  }
  const { data: location } = await apiGet(
    `/api/locations/${encodeURIComponent(id)}`,
    locationResponse,
  );

  return (
    <div className="space-y-6">
      <PageHeader title="拠点を編集" />
      <LocationForm
        // id は URL ではなく API が返した値を使う
        action={updateLocation.bind(null, location.id)}
        location={location}
        submitLabel="保存する"
      />
    </div>
  );
}
