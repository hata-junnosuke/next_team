"use client";

import Link from "next/link";
import { useActionState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button, buttonClassName } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Field } from "@/components/ui/Field";
import type { FormState } from "@/lib/form-state";
import type { Location } from "@/lib/schemas/location";

// 拠点の登録・編集フォーム。action は createLocation か updateLocation.bind(null, id)。
// 422 の項目エラーは Field、項目に紐づかないエラーは Alert（振り分けは Alert の中）

type LocationFormProps = {
  action: (prev: FormState, formData: FormData) => Promise<FormState>;
  /** 編集のときの元の値 */
  location?: Location;
  submitLabel: string;
};

export function LocationForm({
  action,
  location,
  submitLabel,
}: LocationFormProps) {
  const [state, formAction, pending] = useActionState(action, {});

  return (
    <Card className="max-w-xl">
      <form action={formAction} className="space-y-4">
        <Alert state={state} />
        {/* 422 のあと React がフォームをリセットするので、送った値（values）で戻す。
          名称に HTML の required を付けないのは、必須のエラーを API の 422 で入力欄の下に出すため（M-2） */}
        <Field
          label="名称"
          name="name"
          errors={state.errors?.name}
          defaultValue={state.values?.name ?? location?.name}
        />
        <Field
          label="住所"
          name="address"
          errors={state.errors?.address}
          defaultValue={state.values?.address ?? location?.address}
        />
        <div className="flex items-center gap-3">
          <Button pending={pending} pendingLabel="保存中…">
            {submitLabel}
          </Button>
          <Link href="/locations" className={buttonClassName("secondary")}>
            一覧に戻る
          </Link>
        </div>
      </form>
    </Card>
  );
}
