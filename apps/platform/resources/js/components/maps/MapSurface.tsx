import { useEffect, useRef, useState } from "react";
import type * as Leaflet from "leaflet";
import "leaflet/dist/leaflet.css";
export type PolygonBoundary = { type: "Polygon"; coordinates: number[][][] };
export interface MapSurfaceProps {
    heightClassName?: string;
    polygonColors?: string[];
    boundary?: PolygonBoundary | null;
    polygons?: PolygonBoundary[];
    points?: { latitude: number; longitude: number; label?: string }[];
    editing?: "polygon" | "point";
    onBoundaryChange?: (boundary: PolygonBoundary | null) => void;
    onPointChange?: (latitude: number, longitude: number) => void;
}
export default function MapSurface(props: MapSurfaceProps) {
    const element = useRef<HTMLDivElement>(null);
    const map = useRef<Leaflet.Map | null>(null);
    const layers = useRef<Leaflet.LayerGroup | null>(null);
    const latest = useRef(props);
    latest.current = props;
    const [draft, setDraft] = useState<number[][]>([]);
    const [ready, setReady] = useState(false);
    const [error, setError] = useState(false);
    const [tileError, setTileError] = useState(false);
    useEffect(() => {
        let destroyed = false;
        import("leaflet")
            .then((L) => {
                if (destroyed || !element.current) return;
                const instance = L.map(element.current).setView(
                    [-33.9249, 18.4241],
                    10,
                );
                map.current = instance;
                const tiles = L.tileLayer(
                    import.meta.env.VITE_MAP_TILE_URL ||
                        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
                    {
                        attribution: "&copy; OpenStreetMap contributors",
                        maxZoom: 19,
                    },
                ).addTo(instance);
                tiles.on("tileerror", () => {
                    if (!destroyed) setTileError(true);
                });
                layers.current = L.layerGroup().addTo(instance);
                instance.on("click", (event) => {
                    if (latest.current.editing === "polygon")
                        setDraft((points) => [
                            ...points,
                            [
                                Number(event.latlng.lng.toFixed(7)),
                                Number(event.latlng.lat.toFixed(7)),
                            ],
                        ]);
                    if (latest.current.editing === "point")
                        latest.current.onPointChange?.(
                            Number(event.latlng.lat.toFixed(7)),
                            Number(event.latlng.lng.toFixed(7)),
                        );
                });
                setReady(true);
            })
            .catch(() => setError(true));
        return () => {
            destroyed = true;
            map.current?.remove();
            map.current = null;
        };
    }, []);
    const content = JSON.stringify([
        props.polygonColors,
        props.boundary,
        props.polygons,
        props.points,
        draft,
    ]);
    useEffect(() => {
        if (!ready || !map.current || !layers.current) return;
        import("leaflet").then((L) => {
            if (!layers.current || !map.current) return;
            const group = layers.current;
            group.clearLayers();
            const polygons = [
                ...(latest.current.polygons ?? []),
                ...(latest.current.boundary ? [latest.current.boundary] : []),
            ];
            for (const [index, polygon] of polygons.entries()) {
                L.geoJSON(polygon as GeoJSON.GeoJsonObject, {
                    style: {
                        color:
                            latest.current.polygonColors?.[index] ?? "#0f766e",
                        weight: 2,
                        fillOpacity: 0.15,
                    },
                }).addTo(group);
            }
            for (const point of latest.current.points ?? []) {
                const marker = L.circleMarker(
                    [point.latitude, point.longitude],
                    { radius: 7, color: "#0f766e" },
                ).addTo(group);
                if (point.label) {
                    const label = document.createElement("span");
                    label.textContent = point.label;
                    marker.bindTooltip(label);
                }
            }
            if (draft.length)
                L.polyline(
                    draft.map(([lng, lat]) => [lat, lng]),
                    { color: "#d97706", dashArray: "4 4" },
                ).addTo(group);
            const bounds = L.featureGroup(group.getLayers()).getBounds();
            if (bounds.isValid() && !draft.length)
                map.current.fitBounds(bounds, {
                    padding: [25, 25],
                    maxZoom: 13,
                });
        });
    }, [content, ready]);
    return (
        <div className="grid gap-3">
            <div
                ref={element}
                className={
                    "relative z-0 w-full rounded-lg border border-slate-300 " +
                    (props.heightClassName ?? "h-80")
                }
                aria-label="Service area and depot map"
            />
            {tileError && (
                <p
                    aria-live="polite"
                    className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                >
                    The base map could not load. Saved boundaries and
                    coordinates remain available. Check your connection or map
                    tile configuration.
                </p>
            )}
            {error && (
                <p role="alert">
                    The map could not load. Use the coordinate or GeoJSON
                    fields.
                </p>
            )}
            {props.editing === "polygon" && (
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <button
                        type="button"
                        className="rounded border px-3 py-2"
                        disabled={draft.length < 3}
                        onClick={() => {
                            props.onBoundaryChange?.({
                                type: "Polygon",
                                coordinates: [[...draft, draft[0]]],
                            });
                            setDraft([]);
                        }}
                    >
                        Close polygon ({draft.length} points)
                    </button>
                    <button
                        type="button"
                        className="rounded border px-3 py-2"
                        onClick={() =>
                            setDraft((points) => points.slice(0, -1))
                        }
                    >
                        Undo point
                    </button>
                    <button
                        type="button"
                        className="rounded border px-3 py-2"
                        onClick={() => {
                            setDraft([]);
                            props.onBoundaryChange?.(null);
                        }}
                    >
                        Clear boundary
                    </button>
                </div>
            )}
        </div>
    );
}
