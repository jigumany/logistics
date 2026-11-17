import { useState } from "react";
import { Button, Card } from "@logistics/design-system";
import { requestJson } from "../features/operations/http";
export default function VehicleCapacityCheck({ url }: { url: string }) {
    const [volume, setVolume] = useState("");
    const [weight, setWeight] = useState("");
    const [dimensions, setDimensions] = useState(["", "", ""]);
    const [ack, setAck] = useState(false);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState("");
    return (
        <Card>
            <h2 className="mb-4 text-lg font-semibold">
                Load capacity screening
            </h2>
            <form
                className="grid gap-4"
                onSubmit={async (event) => {
                    event.preventDefault();
                    setBusy(true);
                    try {
                        const data = await requestJson(url, {
                            volume_m3: Number(volume),
                            weight_kg: Number(weight),
                            ...(dimensions.some(Boolean)
                                ? { largest_item: dimensions.map(Number) }
                                : {}),
                            restrictions_acknowledged: ack,
                        });
                        setResult(
                            (data.qualifies
                                ? "Passes screening. "
                                : data.reasons.join(" ")) + data.limitation,
                        );
                    } catch (error) {
                        setResult(
                            error instanceof Error
                                ? error.message
                                : "Unable to screen this load.",
                        );
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <label>
                    Total estimated volume (m³)
                    <input
                        required
                        type="number"
                        step="any"
                        min="0"
                        value={volume}
                        onChange={(event) => setVolume(event.target.value)}
                    />
                </label>
                <label>
                    Total estimated weight (kg)
                    <input
                        required
                        type="number"
                        step="any"
                        min="0"
                        value={weight}
                        onChange={(event) => setWeight(event.target.value)}
                    />
                </label>
                <div className="grid gap-3 sm:grid-cols-3">
                    {["Length", "Width", "Height"].map((name, index) => (
                        <label key={name}>
                            Largest item {name.toLowerCase()} (m)
                            <input
                                type="number"
                                step="any"
                                min="0.001"
                                value={dimensions[index]}
                                onChange={(event) =>
                                    setDimensions((values) =>
                                        values.map((value, i) =>
                                            i === index
                                                ? event.target.value
                                                : value,
                                        ),
                                    )
                                }
                            />
                        </label>
                    ))}
                </div>
                <label className="flex items-center gap-2">
                    <input
                        className="w-auto"
                        type="checkbox"
                        checked={ack}
                        onChange={(event) => setAck(event.target.checked)}
                    />
                    Operating restrictions reviewed
                </label>
                <Button disabled={busy}>Screen proposed load</Button>
            </form>
            {result && (
                <p role="status" className="mt-4 text-sm">
                    {result}
                </p>
            )}
        </Card>
    );
}
