import { LocationForm } from "@/components/locations/LocationForm";
import { createLocation } from "@/lib/actions/location";

// 拠点の登録（F-01）。POST /api/locations → 成功したら一覧へ

export default function NewLocationPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold whitespace-nowrap">拠点を登録</h1>
      <LocationForm action={createLocation} submitLabel="登録する" />
    </div>
  );
}
