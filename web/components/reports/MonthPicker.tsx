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
          className="text-ink block text-xs font-medium whitespace-nowrap"
        >
          対象月
        </label>
        <input
          id="field-month"
          type="month"
          name="month"
          defaultValue={month}
          className="bg-surface text-ink ring-line focus:ring-sidebar block h-9 rounded-md px-3 text-sm ring-1 transition-shadow ring-inset focus:ring-2 focus:outline-none"
        />
      </div>
      <Button variant="secondary">表示する</Button>
    </form>
  );
}
