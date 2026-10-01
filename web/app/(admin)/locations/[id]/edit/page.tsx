import { LocationForm } from "@/components/locations/LocationForm";
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
  const { data: location } = await apiGet(
    `/api/locations/${encodeURIComponent(id)}`,
    locationResponse,
  );

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold whitespace-nowrap">拠点を編集</h1>
      <LocationForm
        // id は URL ではなく API が返した値を使う
        action={updateLocation.bind(null, location.id)}
        location={location}
        submitLabel="保存する"
      />
    </div>
  );
}
