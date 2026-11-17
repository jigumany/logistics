export type Field = {
    name: string;
    label: string;
    type?:
        | "text"
        | "email"
        | "url"
        | "tel"
        | "number"
        | "percentage"
        | "textarea"
        | "checkbox"
        | "select"
        | "lookup"
        | "date"
        | "time"
        | "datetime"
        | "polygon"
        | "classes";
    options?: string[];
    lookup?: string;
    required?: boolean;
    help?: string;
    group?: string;
};
export type ModuleConfig = {
    title: string;
    singular: string;
    fields: Field[];
    columns: string[];
    defaults: Record<string, string | number | boolean | string[] | null>;
    statuses?: string[];
    archive?: boolean;
    removable?: boolean;
    creatable?: boolean;
};
const f = (
    name: string,
    label: string,
    type: Field["type"] = "text",
    extra: Partial<Field> = {},
): Field => ({ name, label, type, ...extra });
const depot = f("depot_public_id", "Base depot", "lookup", {
    lookup: "depots",
});
const resource = f("resource_public_id", "Resource", "lookup", {
    lookup: "resources",
    help: "Leave blank for the company calendar.",
});
const times = [
    f("opens_at", "Opening time", "time", { required: true }),
    f("closes_at", "Closing time", "time", { required: true }),
    f("ends_next_day", "Closes on the following day", "checkbox"),
    f("timezone", "IANA time zone", "text", {
        required: true,
        help: "Local wall-clock times use this zone. DST gaps are closed; repeated hours use the first opening and last closing instant.",
    }),
];
export const modules: Record<string, ModuleConfig> = {
    settings: {
        title: "Company settings",
        singular: "Company profile",
        creatable: false,
        columns: [],
        defaults: {
            display_name: "",
            contact_email: "",
            contact_phone: "",
            registered_name: "",
            registration_number: "",
            vat_number: "",
            primary_contact_name: "",
            website: "",
            description: "",
            operating_address: "",
            billing_address: "",
            operational_email: "",
            operational_phone: "",
            operational_status: "active",
            measurement_units: "metric",
            timezone: "Africa/Johannesburg",
            booking_notice_minutes: 1440,
            job_buffer_minutes: 60,
            public_contact_visible: false,
            booking_enabled: false,
            default_service_public_id: "",
            payment_terms: "deposit",
            deposit_basis_points: 2500,
        },
        fields: [
            f("registered_name", "Registered business name", "text", {
                group: "Company information",
            }),
            f("display_name", "Trading name", "text", {
                required: true,
                group: "Company information",
            }),
            f("registration_number", "Company registration number", "text", {
                group: "Company information",
            }),
            f("vat_number", "VAT registration number", "text", {
                group: "Company information",
            }),
            f("website", "Website", "url", { group: "Company information" }),
            f("description", "Business description", "textarea", {
                group: "Company information",
            }),
            f("operational_status", "Operational status", "select", {
                options: ["active", "paused", "closed"],
                group: "Company information",
            }),
            f("primary_contact_name", "Primary contact name", "text", {
                group: "Contact information",
            }),
            f("contact_email", "Business email", "email", {
                required: true,
                group: "Contact information",
            }),
            f("contact_phone", "Contact telephone", "tel", {
                group: "Contact information",
            }),
            f("operating_address", "Primary operating address", "textarea", {
                group: "Contact information",
            }),
            f("billing_address", "Billing address", "textarea", {
                group: "Contact information",
            }),
            f("operational_email", "Operational email", "email", {
                group: "Contact information",
            }),
            f("operational_phone", "Operational telephone", "tel", {
                group: "Contact information",
            }),
            f(
                "public_contact_visible",
                "Publish business contact details",
                "checkbox",
                { group: "Contact information" },
            ),
            f("measurement_units", "Preferred measurement units", "select", {
                options: ["metric", "imperial"],
                help: "Vehicle dimensions are always stored in metres, kilograms and cubic metres.",
                group: "Business preferences",
            }),
            f("default_service_public_id", "Default service", "lookup", {
                lookup: "services",
                group: "Business preferences",
            }),
            f("timezone", "IANA time zone", "text", {
                required: true,
                group: "Business preferences",
            }),
            f(
                "booking_notice_minutes",
                "Minimum booking notice (minutes)",
                "number",
                { group: "Business preferences" },
            ),
            f(
                "job_buffer_minutes",
                "Default turnaround buffer (minutes)",
                "number",
                { group: "Business preferences" },
            ),
            f(
                "booking_enabled",
                "Enable booking availability configuration",
                "checkbox",
                {
                    help: "Customer booking is not available in this phase.",
                    group: "Business preferences",
                },
            ),
            f("payment_terms", "Payment terms", "select", {
                options: ["deposit", "full", "invoice"],
                group: "Business preferences",
            }),
            f("deposit_basis_points", "Deposit (%)", "percentage", {
                help: "Percentage payable when deposit terms apply.",
                group: "Business preferences",
            }),
        ],
    },
    services: {
        title: "Services",
        singular: "Service configuration",
        columns: [
            "name",
            "system_key",
            "enabled",
            "notice_minutes",
            "manual_approval",
        ],
        defaults: {
            service_type_key: "household_moving",
            name: "",
            enabled: true,
            description: "",
            notice_minutes: 1440,
            restrictions: "",
            manual_approval: false,
            publicly_bookable: false,
            active: true,
        },
        fields: [
            f("service_type_key", "System definition", "lookup", {
                lookup: "service-definitions",
                required: true,
                help: "System definitions are shared and cannot be edited by companies.",
            }),
            f("name", "Public service name", "text", { required: true }),
            f("description", "Public description", "textarea"),
            f("notice_minutes", "Booking notice (minutes)", "number", {
                required: true,
            }),
            f("restrictions", "Operational restrictions", "textarea"),
            f("enabled", "Enabled", "checkbox"),
            f("active", "Active", "checkbox"),
            f("manual_approval", "Manual approval required", "checkbox"),
            f("publicly_bookable", "Publicly bookable", "checkbox"),
        ],
    },
    depots: {
        title: "Depots",
        singular: "Depot",
        columns: ["name", "city", "active", "is_primary"],
        statuses: ["active", "inactive"],
        defaults: {
            name: "",
            address_line: "",
            suburb: "",
            city: "",
            postal_code: "",
            country_code: "ZA",
            latitude: "",
            longitude: "",
            coordinate_source: "manual",
            coordinates_verified: false,
            contact_name: "",
            contact_email: "",
            contact_phone: "",
            is_primary: false,
            active: true,
            timezone: "Africa/Johannesburg",
        },
        fields: [
            f("name", "Depot name", "text", { required: true }),
            f("address_line", "Street address", "text", { required: true }),
            f("suburb", "Suburb"),
            f("city", "City", "text", { required: true }),
            f("postal_code", "Postal code"),
            f("country_code", "Country code", "text", { required: true }),
            f("latitude", "Latitude", "number", { required: true }),
            f("longitude", "Longitude", "number", { required: true }),
            f("coordinate_source", "Coordinate source"),
            f(
                "coordinates_verified",
                "Coordinates verified by an operator",
                "checkbox",
            ),
            f("timezone", "IANA time zone", "text", { required: true }),
            f("contact_name", "Contact name"),
            f("contact_email", "Contact email", "email"),
            f("contact_phone", "Contact telephone", "tel"),
            f("is_primary", "Default depot", "checkbox"),
            f("active", "Active", "checkbox"),
        ],
    },
    fleet: {
        title: "Fleet",
        singular: "Vehicle",
        archive: true,
        statuses: ["available", "inactive", "maintenance", "out_of_service"],
        columns: [
            "name",
            "registration_number",
            "status",
            "depot_name",
            "usable_volume_m3",
            "payload_kg",
        ],
        defaults: {
            name: "",
            registration_number: "",
            vehicle_type_key: "sample_van",
            make: "",
            model: "",
            manufacture_year: "",
            status: "inactive",
            depot_public_id: "",
            usable_volume_m3: "",
            payload_kg: "",
            cargo_length_m: "",
            cargo_width_m: "",
            cargo_height_m: "",
            max_item_length_m: "",
            max_item_width_m: "",
            max_item_height_m: "",
            capacity_margin_basis_points: 1000,
            operating_restrictions: "",
            notes: "",
            capacity_verified: false,
            capacity_verification_note: "",
        },
        fields: [
            f("name", "Vehicle name", "text", { required: true }),
            f("registration_number", "Registration number", "text", {
                required: true,
                help: "Spaces and hyphens are removed; uppercase registrations remain reserved within this company after archival.",
            }),
            f("vehicle_type_key", "Vehicle type", "lookup", {
                lookup: "vehicle-types",
                required: true,
            }),
            f("make", "Make"),
            f("model", "Model"),
            f("manufacture_year", "Manufacture year", "number"),
            f("status", "Operational status", "select", {
                options: [
                    "available",
                    "inactive",
                    "maintenance",
                    "out_of_service",
                ],
                help: "This is an operating status, not real-time booking availability.",
            }),
            depot,
            ...[
                ["usable_volume_m3", "Usable volume (m³)"],
                ["payload_kg", "Maximum payload (kg)"],
                ["cargo_length_m", "Cargo length (m)"],
                ["cargo_width_m", "Cargo width (m)"],
                ["cargo_height_m", "Cargo height (m)"],
                ["max_item_length_m", "Maximum item length (m)"],
                ["max_item_width_m", "Maximum item width (m)"],
                ["max_item_height_m", "Maximum item height (m)"],
            ].map(([name, label]) =>
                f(name, label, "number", {
                    help: "Blank uses catalogue defaults for cargo measurements. Verify actual measurements.",
                }),
            ),
            f(
                "capacity_margin_basis_points",
                "Capacity safety margin (%)",
                "percentage",
                {
                    required: true,
                    help: "Percentage of volume and payload held in reserve.",
                },
            ),
            f(
                "capacity_verified",
                "Actual vehicle capacity verified",
                "checkbox",
                {
                    help: "Confirm actual truck specifications, not illustrative vehicle catalogue defaults.",
                },
            ),
            f(
                "capacity_verification_note",
                "Capacity verification evidence",
                "textarea",
                {
                    help: "Record the source, measurements and date. Reconfirm after capacity changes.",
                },
            ),
            f("operating_restrictions", "Operating restrictions", "textarea"),
            f("notes", "Notes", "textarea"),
        ],
    },
    staff: {
        title: "Staff and drivers",
        singular: "Staff member",
        archive: true,
        statuses: ["driver", "mover", "dispatcher", "operations_manager"],
        columns: [
            "full_name",
            "role",
            "engagement_status",
            "active",
            "depot_name",
        ],
        defaults: {
            full_name: "",
            email: "",
            phone: "",
            role: "mover",
            engagement_status: "employed",
            active: true,
            depot_public_id: "",
            user_public_id: "",
            licence_category: "",
            licence_expires_on: "",
            qualifications: "",
            eligible_vehicle_classes: [],
            notes: "",
        },
        fields: [
            f("full_name", "Full name", "text", { required: true }),
            f("email", "Email", "email"),
            f("phone", "Telephone", "tel"),
            f("role", "Operational role", "select", {
                options: [
                    "driver",
                    "mover",
                    "dispatcher",
                    "operations_manager",
                ],
                help: "Operational classifications never grant application permissions.",
            }),
            f("engagement_status", "Engagement status", "select", {
                options: ["employed", "contractor", "suspended", "ended"],
            }),
            depot,
            f("user_public_id", "Optional company login account", "lookup", {
                lookup: "members",
                help: "Leave blank for staff without a login. The selected account must already belong to this company.",
            }),
            f("active", "Active", "checkbox"),
            f("licence_category", "Licence category"),
            f("licence_expires_on", "Licence expiration", "date"),
            f("qualifications", "Qualifications and endorsements", "textarea"),
            f(
                "eligible_vehicle_classes",
                "Eligible vehicle classes",
                "classes",
            ),
            f("notes", "Private notes", "textarea"),
        ],
    },
    zones: {
        title: "Service areas",
        singular: "Service area",
        statuses: ["active", "inactive"],
        columns: [
            "name",
            "zone_type",
            "active",
            "priority",
            "manual_review",
            "example_only",
        ],
        defaults: {
            name: "",
            description: "",
            zone_type: "both",
            boundary: null,
            active: false,
            priority: 0,
            restrictions: "",
            manual_review: false,
            example_only: false,
        },
        fields: [
            f("name", "Zone name", "text", { required: true }),
            f("description", "Description", "textarea"),
            f("zone_type", "Coverage purpose", "select", {
                options: ["pickup", "delivery", "both"],
            }),
            f("boundary", "Coverage boundary", "polygon", {
                required: true,
                help: "Click at least three points, then close the polygon. A zone boundary does not verify address-coordinate accuracy.",
            }),
            f("priority", "Priority", "number", {
                help: "Higher priority wins overlaps. Equal priorities use the oldest zone.",
            }),
            f("restrictions", "Operating restrictions", "textarea"),
            f("manual_review", "Manual review required", "checkbox"),
            f("example_only", "Development example only", "checkbox", {
                help: "Example zones never qualify as operating coverage.",
            }),
            f("active", "Active", "checkbox"),
        ],
    },
    "calendar/weekly": {
        title: "Operating calendar",
        singular: "Weekly schedule",
        removable: true,
        columns: [
            "resource_name",
            "weekday",
            "opens_at",
            "closes_at",
            "timezone",
            "active",
        ],
        defaults: {
            resource_public_id: "",
            weekday: 1,
            opens_at: "08:00",
            closes_at: "17:00",
            ends_next_day: false,
            timezone: "Africa/Johannesburg",
            active: true,
        },
        fields: [
            resource,
            f("weekday", "Working day", "select", {
                options: ["0", "1", "2", "3", "4", "5", "6"],
                help: "Hours repeat on this day in the selected time zone.",
            }),
            ...times,
            f("active", "Active", "checkbox"),
        ],
    },
    "calendar/overrides": {
        title: "Special operating days",
        singular: "Date override",
        removable: true,
        columns: [
            "resource_name",
            "date",
            "closed",
            "opens_at",
            "closes_at",
            "reason",
        ],
        defaults: {
            resource_public_id: "",
            date: "",
            closed: true,
            opens_at: "08:00",
            closes_at: "17:00",
            ends_next_day: false,
            timezone: "Africa/Johannesburg",
            reason: "",
        },
        fields: [
            resource,
            f("date", "Date", "date", { required: true }),
            f("closed", "Closed for this date", "checkbox"),
            ...times,
            f("reason", "Reason"),
        ],
    },
    "calendar/blocks": {
        title: "Resource blackout periods",
        singular: "Resource block",
        removable: true,
        columns: ["resource_name", "starts_at", "ends_at", "reason"],
        defaults: {
            resource_public_id: "",
            starts_at: "",
            ends_at: "",
            reason: "administrative",
            notes: "",
        },
        fields: [
            {
                ...resource,
                required: true,
                help: "Select the affected vehicle, driver or mover.",
            },
            f("starts_at", "Starts at (UTC)", "datetime", { required: true }),
            f("ends_at", "Ends at (UTC)", "datetime", { required: true }),
            f("reason", "Reason", "select", {
                options: ["maintenance", "leave", "administrative", "blackout"],
            }),
            f("notes", "Notes", "textarea"),
        ],
    },
};
export function humanize(value: string) {
    return value.replaceAll("_", " ").replace(/^./, (s) => s.toUpperCase());
}

export function weekdayName(value: string | number) {
    return (
        [
            "Sunday",
            "Monday",
            "Tuesday",
            "Wednesday",
            "Thursday",
            "Friday",
            "Saturday",
        ][Number(value)] ?? ""
    );
}
