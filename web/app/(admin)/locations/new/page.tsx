import { LocationForm } from "@/components/locations/LocationForm";
import { PageHeader } from "@/components/ui/PageHeader";
import { createLocation } from "@/lib/actions/location";

// 拠点の登録（F-01）。POST /api/locations → 成功したら一覧へ

export default function NewLocationPage() {
  return (
    <div className="space-y-6">
      <PageHeader title="拠点を登録" />
      <LocationForm action={createLocation} submitLabel="登録する" />
    </div>
  );
}
