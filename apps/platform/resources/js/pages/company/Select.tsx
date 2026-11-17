import { Head, Link } from "@inertiajs/react";
import CompanyLayout from "../../layouts/CompanyLayout";
import { Card } from "@logistics/design-system";
import type { Company } from "@logistics/types";
export default function Select({
    companies,
}: {
    companies: {
        data: Company[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}) {
    return (
        <CompanyLayout>
            <Head title="Your companies" />
            <h1 className="mb-2 text-3xl font-bold">Your workspaces</h1>
            <p className="mb-8 text-slate-500">Choose a company to continue.</p>
            <div className="grid gap-4 sm:grid-cols-2">
                {companies.data.map((company) => (
                    <Card key={company.public_id}>
                        <Link
                            className="font-semibold text-teal-800"
                            href={"/c/" + company.slug}
                        >
                            {company.name} →
                        </Link>
                    </Card>
                ))}
            </div>
            {!companies.data.length && (
                <Card>
                    Your account has no active company memberships. Contact your
                    company administrator.
                </Card>
            )}
            <nav aria-label="Pagination" className="mt-6 flex gap-6">
                {companies.prev_page_url && (
                    <Link href={companies.prev_page_url}>Previous</Link>
                )}
                {companies.next_page_url && (
                    <Link href={companies.next_page_url}>Next</Link>
                )}
            </nav>
        </CompanyLayout>
    );
}
