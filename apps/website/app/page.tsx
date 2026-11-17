import { Button, Card } from "@logistics/design-system";
export default function Home() {
  const portal =
    process.env.NEXT_PUBLIC_PLATFORM_URL || "http://localhost:8080";
  return (
    <>
      <header className="mx-auto flex max-w-6xl items-center justify-between px-6 py-7">
        <a className="font-bold tracking-tight" href="/">
          CAPE TOWN MOVERS
        </a>
        <Button variant="outline" asChild>
          <a href={portal + "/login"}>Company sign in</a>
        </Button>
      </header>
      <main>
        <section className="mx-auto grid max-w-6xl gap-12 px-6 py-20 md:grid-cols-2 md:py-28">
          <div>
            <p className="text-xs font-bold uppercase tracking-widest text-teal-700">
              Cape Town & surrounding areas
            </p>
            <h1 className="mt-6 text-5xl leading-tight font-bold md:text-6xl">
              A fresh start.
              <br />A smoother move.
            </h1>
            <p className="mt-6 max-w-lg text-lg leading-relaxed text-slate-600">
              From the first box to the final delivery, keep your household,
              office or goods moving with care.
            </p>
            <p className="mt-8 text-sm text-slate-500">
              Request a quotation. Booking availability will be confirmed
              separately.
            </p>
            <Button className="mt-6" asChild>
              <a
                href={
                  portal +
                  "/q/" +
                  (process.env.NEXT_PUBLIC_QUOTE_COMPANY || "cape-town-movers")
                }
              >
                Request a quotation
              </a>
            </Button>
          </div>
          <div
            aria-hidden="true"
            className="flex min-h-72 items-center justify-center rounded-3xl bg-teal-900 p-10 text-center text-4xl leading-relaxed font-light text-white"
          >
            Less to carry.
            <br />
            More to look forward to.
          </div>
        </section>
        <section
          aria-labelledby="services"
          className="mx-auto max-w-6xl px-6 pb-24"
        >
          <h2 id="services" className="mb-8 text-3xl font-bold">
            Built around your next move
          </h2>
          <div className="grid gap-5 md:grid-cols-3">
            {[
              [
                "01",
                "Household moving",
                "A new home, with your belongings handled carefully.",
              ],
              [
                "02",
                "Office moving",
                "Move your team and equipment with a clear plan.",
              ],
              [
                "03",
                "General goods",
                "Transport for the things your business depends on.",
              ],
            ].map(([number, title, description]) => (
              <Card key={number}>
                <span className="text-sm text-teal-700">{number}</span>
                <h3 className="mt-5 text-xl font-semibold">{title}</h3>
                <p className="mt-3 leading-relaxed text-slate-600">
                  {description}
                </p>
              </Card>
            ))}
          </div>
        </section>
      </main>
      <footer className="border-t border-slate-200 px-6 py-8 text-center text-sm text-slate-500">
        Cape Town Movers · South Africa
      </footer>
    </>
  );
}
