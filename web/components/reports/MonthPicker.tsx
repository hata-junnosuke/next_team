import { Button } from "@/components/ui/Button";

// 対象月の選択。GET のフォームで ?month=YYYY-MM に載せるだけなので Server Action も Client Component も要らない。
// 形の検証は Laravel が正（不正なら 422 の message をページに出す）

type MonthPickerProps = {
  month: string;
};

export function MonthPicker({ month }: MonthPickerProps) {
  return (
    <form
      method="get"
      action="/reports/monthly"
      className="flex items-end gap-3"
    >
      <div className="space-y-1">
        <label
          htmlFor="field-month"
          className="block text-sm font-medium whitespace-nowrap text-gray-700"
        >
          対象月
        </label>
        <input
          id="field-month"
          type="month"
          name="month"
          defaultValue={month}
          className="block rounded-md border border-gray-300 px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-blue-200 focus:outline-none"
        />
      </div>
      <Button variant="secondary">表示する</Button>
    </form>
  );
}
