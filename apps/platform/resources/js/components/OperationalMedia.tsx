import { useEffect, useState } from "react";
import { useForm } from "@inertiajs/react";
import { Button, Card } from "@logistics/design-system";
import { requestJson } from "../features/operations/http";
export default function OperationalMedia({
    base,
    kind,
    id,
    hasImage = false,
}: {
    base: string;
    kind: "logo" | "vehicle" | "document";
    id: string;
    hasImage?: boolean;
}) {
    const form = useForm<{ file: File | null }>({ file: null });
    const [docs, setDocs] = useState<{
        data: { public_id: string; original_name: string }[];
        prev_page_url: string | null;
        next_page_url: string | null;
    } | null>(null);
    const [imageVersion, setImageVersion] = useState(0);
    const [error, setError] = useState("");
    const load = (url = base + "/staff/" + id + "/documents") =>
        requestJson(url)
            .then((data) => {
                setDocs(data.documents);
                setError("");
            })
            .catch(() => setError("Documents could not be loaded."));
    useEffect(() => {
        if (kind === "document") void load();
    }, [base, id, kind]);
    return (
        <Card>
            <h2 className="mb-4 text-lg font-semibold">
                {kind === "logo"
                    ? "Branding"
                    : kind === "vehicle"
                      ? "Vehicle photograph"
                      : "Private supporting documents"}
            </h2>
            {hasImage && (
                <img
                    className="mb-4 h-24 max-w-xs rounded-lg border border-slate-200 object-contain"
                    src={
                        base +
                        "/media/" +
                        kind +
                        "/" +
                        id +
                        "?v=" +
                        imageVersion
                    }
                    alt={
                        kind === "logo" ? "Company logo" : "Vehicle photograph"
                    }
                />
            )}
            {hasImage && (
                <a
                    className="mb-4 inline-block text-sm text-teal-800 underline"
                    href={base + "/media/" + kind + "/" + id}
                    target="_blank"
                    rel="noreferrer"
                >
                    View current image
                </a>
            )}
            <form
                className="grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(base + "/media/" + kind + "/" + id, {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            setImageVersion((value) => value + 1);
                            if (kind === "document") void load();
                        },
                    });
                }}
            >
                <label>
                    {kind === "document"
                        ? "Upload a driver document"
                        : "Upload image"}
                    <input
                        required
                        type="file"
                        accept={
                            kind === "document"
                                ? ".pdf,.jpg,.jpeg,.png,.webp"
                                : ".jpg,.jpeg,.png,.webp"
                        }
                        onChange={(event) =>
                            form.setData(
                                "file",
                                event.target.files?.[0] ?? null,
                            )
                        }
                    />
                </label>
                <p className="text-xs text-slate-500">
                    Private storage · 5 MB maximum ·{" "}
                    {kind === "document"
                        ? "PDF, JPEG, PNG or WebP. Only company administrators can download these files."
                        : "JPEG, PNG or WebP."}
                </p>
                {form.errors.file && (
                    <p role="alert" className="text-sm text-red-700">
                        {form.errors.file}
                    </p>
                )}
                {form.progress && (
                    <progress max="100" value={form.progress.percentage} />
                )}
                <Button disabled={!form.data.file || form.processing}>
                    Upload
                </Button>
            </form>
            {error && (
                <p role="alert" className="mt-4 text-red-700">
                    {error}
                </p>
            )}
            {docs && (
                <div className="mt-5 grid gap-3 text-sm">
                    {docs.data.map((doc) => (
                        <a
                            key={doc.public_id}
                            className="text-teal-800 underline"
                            href={base + "/media/document/" + doc.public_id}
                        >
                            {doc.original_name}
                        </a>
                    ))}
                    {!docs.data.length && (
                        <p className="text-slate-500">
                            No supporting documents uploaded.
                        </p>
                    )}
                    <div className="flex gap-4">
                        {docs.prev_page_url && (
                            <button
                                type="button"
                                onClick={() => void load(docs.prev_page_url!)}
                            >
                                Previous documents
                            </button>
                        )}
                        {docs.next_page_url && (
                            <button
                                type="button"
                                onClick={() => void load(docs.next_page_url!)}
                            >
                                Next documents
                            </button>
                        )}
                    </div>
                </div>
            )}
        </Card>
    );
}
