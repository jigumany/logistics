import { Head, useForm } from "@inertiajs/react";
import { Button, Card } from "@logistics/design-system";
export default function Login() {
    const form = useForm({ email: "", password: "" });
    return (
        <main className="grid min-h-screen place-items-center px-6">
            <Head title="Sign in" />
            <Card className="w-full max-w-md">
                <p className="mb-6 text-xs font-bold tracking-widest text-teal-700">
                    LOGISTICS PLATFORM
                </p>
                <h1 className="text-3xl font-bold">Welcome back</h1>
                <p className="mb-8 mt-2 text-slate-500">
                    Sign in to your company workspace.
                </p>
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post("/login", {
                            onFinish: () => form.reset("password"),
                        });
                    }}
                >
                    <label>
                        Email
                        <input
                            type="email"
                            autoComplete="username"
                            required
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData("email", e.target.value)
                            }
                        />
                    </label>
                    <label>
                        Password
                        <input
                            type="password"
                            autoComplete="current-password"
                            required
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData("password", e.target.value)
                            }
                        />
                    </label>
                    {Object.values(form.errors).map((error) => (
                        <p
                            role="alert"
                            className="text-sm text-red-700"
                            key={error}
                        >
                            {error}
                        </p>
                    ))}
                    <Button disabled={form.processing}>Sign in</Button>
                </form>
            </Card>
        </main>
    );
}
