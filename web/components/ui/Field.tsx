// ラベル + 入力 + その項目のエラー（422 の errors[name]）。
// 検証は Laravel が正。ここでは required 程度しか付けない

type Option = { value: string | number; label: string };

type FieldProps = {
  label: string;
  name: string;
  /**
   * 入力欄の id（エラーの id は `${id}-error`）。既定は `field-${name}`。
   * 同じ name の Field が 1 ページに複数並ぶとき（例: 応募ごとの勤怠フォーム）は
   * `field-attendance-${application.id}-clock_in` のように重ならない id を渡す
   */
  id?: string;
  type?:
    | "text"
    | "email"
    | "password"
    | "number"
    | "date"
    | "time"
    | "select"
    | "textarea";
  defaultValue?: string | number | null;
  errors?: string[];
  required?: boolean;
  placeholder?: string;
  autoComplete?: string;
  /** type="select" のときの選択肢 */
  options?: Option[];
  /** type="select" で未選択を許すときの文言 */
  emptyOption?: string;
};

// 枠線は ring で描く（border と違って太さを変えても寸法が動かない）。フォーカスは sidebar 色、エラーは danger
const inputClass =
  "block w-full rounded-md bg-surface px-3 py-2 text-sm text-ink ring-1 ring-inset transition-shadow placeholder:text-ink-muted focus:ring-2 focus:outline-none";

export function Field({
  label,
  name,
  id: idProp,
  type = "text",
  defaultValue,
  errors,
  required,
  placeholder,
  autoComplete,
  options = [],
  emptyOption,
}: FieldProps) {
  const id = idProp ?? `field-${name}`;
  const hasError = (errors?.length ?? 0) > 0;
  const stateClass = hasError
    ? "ring-danger focus:ring-danger"
    : "ring-line focus:ring-sidebar";
  const common = {
    id,
    name,
    required,
    defaultValue: defaultValue ?? undefined,
    "aria-invalid": hasError || undefined,
    "aria-describedby": hasError ? `${id}-error` : undefined,
    className: `${inputClass} ${stateClass}`,
  };

  return (
    <div className="space-y-1">
      <label
        htmlFor={id}
        className="text-ink block text-xs font-medium whitespace-nowrap"
      >
        {label}
        {required && <span className="text-danger ml-1">*</span>}
      </label>
      {type === "select" ? (
        <select {...common}>
          {emptyOption !== undefined && <option value="">{emptyOption}</option>}
          {options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      ) : type === "textarea" ? (
        <textarea {...common} rows={3} placeholder={placeholder} />
      ) : (
        <input
          {...common}
          type={type}
          placeholder={placeholder}
          autoComplete={autoComplete}
        />
      )}
      {hasError && (
        <ul id={`${id}-error`} className="text-danger space-y-0.5 text-xs">
          {errors?.map((e) => (
            <li key={e}>{e}</li>
          ))}
        </ul>
      )}
    </div>
  );
}
