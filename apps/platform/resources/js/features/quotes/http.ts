import { requestJson } from "../operations/http";
export async function quoteRequest<T>(url: string, data?: unknown): Promise<T> {
    return requestJson(url, data) as Promise<T>;
}
export function csrfToken() {
    return decodeURIComponent(
        document.cookie
            .split("; ")
            .find((value) => value.startsWith("XSRF-TOKEN="))
            ?.slice(11) ?? "",
    );
}
export async function uploadPhoto(
    url: string,
    itemKey: string,
    file: File,
): Promise<void> {
    const data = new FormData();
    data.set("item_key", itemKey);
    data.set("file", file);
    const response = await fetch(url, {
        method: "POST",
        headers: { Accept: "application/json", "X-XSRF-TOKEN": csrfToken() },
        body: data,
    });
    if (!response.ok) {
        const result = await response.json();
        throw new Error(
            result.errors
                ? Object.values(result.errors).flat().join(" ")
                : "The photo could not be uploaded.",
        );
    }
}
export async function downloadQuotePdf(url: string): Promise<string | null> {
    const response = await fetch(url, {
        headers: { Accept: "application/pdf, application/json" },
    });
    if (response.status === 202) return (await response.json()).message;
    if (!response.ok)
        throw new Error("The PDF could not be downloaded. Please retry.");
    const objectUrl = URL.createObjectURL(await response.blob());
    const link = document.createElement("a");
    link.href = objectUrl;
    link.download = "quotation.pdf";
    link.click();
    setTimeout(() => URL.revokeObjectURL(objectUrl), 30000);
    return null;
}
