import { useEffect, useState } from "react";
import type { QuoteAddress } from "@logistics/types";
import { Button } from "@logistics/design-system";
import { quoteRequest } from "../features/quotes/http";
interface Prediction {
    place_id: string;
    label: string;
}
interface Details {
    available: boolean;
    place_id: string;
    latitude: number;
    longitude: number;
    verification_proof: string;
    address_line: string;
    city: string;
    postal_code: string;
    country_code: string;
}
export default function QuoteAddressFields({
    title,
    address,
    onChange,
    base,
}: {
    title: string;
    address: QuoteAddress;
    onChange: (value: QuoteAddress) => void;
    base: string;
}) {
    const [suggestions, setSuggestions] = useState<Prediction[]>([]);
    const [notice, setNotice] = useState("");
    const [session] = useState(() => crypto.randomUUID());
    const [manualOnly, setManualOnly] = useState(false);
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        if (
            manualOnly ||
            address.verification_proof ||
            address.address_line.length < 3
        ) {
            setSuggestions([]);
            return;
        }
        const abort = new AbortController();
        const timer = setTimeout(() => {
            setLoading(true);
            fetch(
                base +
                    "/addresses/search?" +
                    new URLSearchParams({
                        query: address.address_line,
                        session_token: session,
                    }),
                {
                    signal: abort.signal,
                    headers: { Accept: "application/json" },
                },
            )
                .then(async (response) => {
                    if (!response.ok) throw new Error();
                    return response.json();
                })
                .then(
                    (data: {
                        available: boolean;
                        predictions: Prediction[];
                    }) => {
                        setSuggestions(data.predictions);
                        if (!data.available) {
                            setManualOnly(true);
                            setNotice(
                                "Address search is unavailable. Enter the address manually and our team can confirm it.",
                            );
                        }
                    },
                )
                .catch(() => {
                    if (!abort.signal.aborted)
                        setNotice(
                            "Enter the address manually. Address search can be retried later.",
                        );
                })
                .finally(() => {
                    if (!abort.signal.aborted) setLoading(false);
                });
        }, 400);
        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [
        address.address_line,
        address.verification_proof,
        base,
        session,
        manualOnly,
    ]);
    function update(field: keyof QuoteAddress, value: string) {
        const next = {
            ...address,
            [field]: ["address_line", "city", "country_code"].includes(field)
                ? value
                : value || null,
        };
        if (
            [
                "address_line",
                "city",
                "postal_code",
                "country_code",
                "latitude",
                "longitude",
            ].includes(field)
        ) {
            next.verification_proof = null;
            next.provider_place_id = null;
            if (!["latitude", "longitude"].includes(field)) {
                next.latitude = null;
                next.longitude = null;
            }
        }
        onChange(next as QuoteAddress);
    }
    async function select(prediction: Prediction) {
        setLoading(true);
        try {
            const details = await quoteRequest<Details>(
                base +
                    "/addresses/details?" +
                    new URLSearchParams({
                        place_id: prediction.place_id,
                        session_token: session,
                    }),
            );
            if (!details.available)
                throw new Error("Please enter this address manually.");
            onChange({
                ...address,
                address_line: details.address_line,
                city: details.city,
                postal_code: details.postal_code,
                country_code: details.country_code,
                latitude: details.latitude,
                longitude: details.longitude,
                provider_place_id: details.place_id,
                verification_proof: details.verification_proof,
            });
            setSuggestions([]);
            setNotice(
                "Location found with Google Maps. Confirm the address and add access instructions.",
            );
        } catch (error) {
            setNotice(
                error instanceof Error
                    ? error.message
                    : "Please enter the address manually.",
            );
        } finally {
            setLoading(false);
        }
    }
    return (
        <fieldset className="grid gap-4 rounded-xl border border-slate-200 p-5">
            <legend className="px-2 text-lg font-bold">{title}</legend>
            <label>
                Street address
                <input
                    required
                    maxLength={255}
                    autoComplete="street-address"
                    value={address.address_line}
                    onChange={(event) =>
                        update("address_line", event.target.value)
                    }
                />
            </label>
            {loading && <p role="status">Finding address…</p>}
            {suggestions.length > 0 && (
                <ul
                    aria-label={title + " address suggestions"}
                    className="rounded-lg border border-slate-200 bg-white"
                >
                    {suggestions.map((prediction) => (
                        <li key={prediction.place_id}>
                            <button
                                type="button"
                                className="w-full p-3 text-left hover:bg-teal-50"
                                onClick={() => select(prediction)}
                            >
                                {prediction.label}
                            </button>
                        </li>
                    ))}
                    <li className="px-3 pb-2 text-xs text-slate-500">
                        Powered by Google Maps
                    </li>
                </ul>
            )}
            {notice && <p className="text-sm text-slate-600">{notice}</p>}
            <div className="grid gap-4 sm:grid-cols-2">
                <label>
                    City
                    <input
                        required
                        maxLength={100}
                        value={address.city}
                        onChange={(event) => update("city", event.target.value)}
                    />
                </label>
                <label>
                    Suburb
                    <input
                        maxLength={100}
                        value={address.suburb ?? ""}
                        onChange={(event) =>
                            update("suburb", event.target.value)
                        }
                    />
                </label>
                <label>
                    Postal code
                    <input
                        maxLength={20}
                        value={address.postal_code ?? ""}
                        onChange={(event) =>
                            update("postal_code", event.target.value)
                        }
                    />
                </label>
                <label>
                    Country code
                    <input
                        required
                        pattern="[A-Z]{2}"
                        maxLength={2}
                        value={address.country_code}
                        onChange={(event) =>
                            update(
                                "country_code",
                                event.target.value.toUpperCase(),
                            )
                        }
                    />
                </label>
            </div>
            <label>
                Pickup or delivery instructions
                <textarea
                    className="rounded-lg border border-slate-300 p-3"
                    maxLength={2000}
                    value={address.instructions ?? ""}
                    onChange={(event) =>
                        update("instructions", event.target.value)
                    }
                />
            </label>
            <details>
                <summary className="cursor-pointer text-sm text-teal-800">
                    Optional coordinates for a manually entered address
                </summary>
                <p className="my-3 text-sm text-slate-500">
                    Coordinates you enter still need confirmation.
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label>
                        Latitude
                        <input
                            type="number"
                            step="any"
                            min={-90}
                            max={90}
                            value={address.latitude ?? ""}
                            onChange={(event) =>
                                update("latitude", event.target.value)
                            }
                        />
                    </label>
                    <label>
                        Longitude
                        <input
                            type="number"
                            step="any"
                            min={-180}
                            max={180}
                            value={address.longitude ?? ""}
                            onChange={(event) =>
                                update("longitude", event.target.value)
                            }
                        />
                    </label>
                </div>
            </details>
            {manualOnly && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                        setManualOnly(false);
                        setNotice("");
                    }}
                >
                    Retry address search
                </Button>
            )}
        </fieldset>
    );
}
