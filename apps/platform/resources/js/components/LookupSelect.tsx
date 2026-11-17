import { useEffect, useState } from "react";
export default function LookupSelect({
    base,
    kind,
    value,
    onChange,
    label,
    required,
    disabled,
    emptyLabel,
    selectedLabel,
}: {
    base: string;
    kind: string;
    value: string;
    onChange: (value: string) => void;
    label: string;
    required?: boolean;
    disabled?: boolean;
    emptyLabel?: string;
    selectedLabel?: string;
}) {
    const [search, setSearch] = useState("");
    const [options, setOptions] = useState<{ value: string; label: string }[]>(
        [],
    );
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        if (disabled) return;
        const abort = new AbortController();
        const timer = setTimeout(() => {
            fetch(
                base +
                    "/lookups/" +
                    kind +
                    "?" +
                    new URLSearchParams({ search, selected: value }),
                {
                    signal: abort.signal,
                    headers: { Accept: "application/json" },
                },
            )
                .then(async (response) => {
                    if (!response.ok) throw new Error("Lookup unavailable");
                    return response.json();
                })
                .then((data) => {
                    setOptions(data.options);
                    setFailed(false);
                })
                .catch((error) => {
                    if (error.name !== "AbortError") setFailed(true);
                });
        }, 200);
        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [base, kind, search, value, disabled]);
    return (
        <div className="grid gap-2">
            <label htmlFor={kind + "-value"}>{label}</label>
            {!disabled && (
                <input
                    aria-label={label + " search"}
                    placeholder="Search options…"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
            )}
            <select
                id={kind + "-value"}
                value={value}
                required={required}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">
                    {emptyLabel ??
                        (required
                            ? "Select an option"
                            : kind === "resources"
                              ? "Company calendar"
                              : kind === "members"
                                ? "No login account"
                                : kind === "depots"
                                  ? "Unassigned"
                                  : "No default service")}
                </option>
                {value && !options.some((option) => option.value === value) && (
                    <option value={value}>{selectedLabel ?? value}</option>
                )}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {failed && (
                <p role="alert" className="text-sm text-red-700">
                    Options could not be loaded. Change the search to retry.
                </p>
            )}
        </div>
    );
}
