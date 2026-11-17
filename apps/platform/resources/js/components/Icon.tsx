import type { SVGProps } from "react";
const paths = {
    dashboard: "M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z",
    truck: "M3 6h11v11H3z M14 10h4l3 4v3h-7 M7 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4 M18 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4",
    map: "m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2z M9 3v16 M15 5v16",
    users: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75",
    calendar:
        "M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2",
    box: "m12 3 9 5v9l-9 5-9-5V8z m-9 5 9 5 9-5 M12 13v9 M7.5 5.5l9 5",
    file: "M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 13h8 M8 17h5",
    settings: "M4 7h16 M4 17h16 M8 4v6 M16 14v6",
    depot: "m3 10 9-7 9 7v11H3z M8 21V11h8v10",
    check: "m5 12 4 4L19 6",
    plus: "M12 5v14 M5 12h14",
    arrow: "M5 12h14 m-6-6 6 6-6 6",
    search: "m21 21-5-5 M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16",
    menu: "M4 6h16 M4 12h16 M4 18h16",
    close: "m6 6 12 12 M6 18 18 6",
    logout: "M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9",
    chevron: "m9 5 7 7-7 7",
    clock: "M12 8v4l3 2 M22 12a10 10 0 1 0-20 0 10 10 0 0 0 20 0",
    eye: "M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7 M15 12a3 3 0 1 0-6 0 3 3 0 0 0 6 0",
};
export type IconName = keyof typeof paths;
export default function Icon({
    name,
    ...props
}: SVGProps<SVGSVGElement> & { name: IconName }) {
    return (
        <svg
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            <path d={paths[name]} />
        </svg>
    );
}
