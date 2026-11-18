import { Head, Link, useForm } from "@inertiajs/react";
export default function Invitation({
    business,
    email,
    existingAccount,
    signedIn,
    acceptUrl,
}: {
    business: string;
    email: string;
    existingAccount: boolean;
    signedIn: boolean;
    acceptUrl: string;
}) {
    const form = useForm({ password: "", password_confirmation: "" });
    return (
        <main className="grid min-h-screen place-items-center bg-slate-50 p-5">
            <Head title="Join the driver workspace" />
            <section className="w-full max-w-md rounded-2xl border bg-white p-7 shadow-sm">
                <p className="text-sm font-semibold uppercase tracking-widest text-teal-700">
                    {business}
                </p>
                <h1 className="mt-3 text-2xl font-bold text-slate-900">
                    Join the driver workspace
                </h1>
                <p className="mt-3 text-sm text-slate-500">
                    This private invitation links {email} to the named driver
                    profile.
                </p>
                {existingAccount && !signedIn ? (
                    <>
                        <p className="mt-5 text-sm">
                            An account already exists for this email. Sign in to
                            that account, then accept this invitation.
                        </p>
                        <Link
                            href="/login"
                            className="mt-5 inline-block rounded-lg bg-teal-700 px-5 py-3 font-semibold text-white"
                        >
                            Sign in to accept
                        </Link>
                    </>
                ) : (
                    <form
                        className="mt-6 space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(acceptUrl, {
                                onFinish: () =>
                                    form.reset(
                                        "password",
                                        "password_confirmation",
                                    ),
                            });
                        }}
                    >
                        {!existingAccount && (
                            <>
                                <label className="block text-sm font-medium">
                                    Choose a password
                                    <input
                                        type="password"
                                        autoComplete="new-password"
                                        minLength={12}
                                        maxLength={72}
                                        required
                                        value={form.data.password}
                                        onChange={(e) =>
                                            form.setData(
                                                "password",
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1 block w-full rounded-lg border-slate-300"
                                    />
                                </label>
                                <p className="text-xs text-slate-500">
                                    Use at least 12 characters, including
                                    letters and numbers.
                                </p>
                                <label className="block text-sm font-medium">
                                    Confirm password
                                    <input
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                        value={form.data.password_confirmation}
                                        onChange={(e) =>
                                            form.setData(
                                                "password_confirmation",
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1 block w-full rounded-lg border-slate-300"
                                    />
                                </label>
                            </>
                        )}
                        {Object.values(form.errors).map((e, i) => (
                            <p
                                key={i}
                                role="alert"
                                className="text-sm text-red-800"
                            >
                                {e}
                            </p>
                        ))}
                        <button
                            disabled={form.processing}
                            className="w-full rounded-lg bg-teal-700 px-5 py-3 font-semibold text-white disabled:opacity-40"
                        >
                            Accept driver invitation
                        </button>
                    </form>
                )}
            </section>
        </main>
    );
}
