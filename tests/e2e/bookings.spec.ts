import { test, expect, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
test.use({ actionTimeout: 15000 });
const origin = "http://127.0.0.1:8081";
function fixture() {
  return JSON.parse(readFileSync(".cache/booking-browser.json", "utf8")) as {
    date: string;
    start: string;
    customers: Record<string, string>[];
  };
}
async function search(page: Page, date: string, time: string) {
  await page.getByLabel("Moving date", { exact: true }).fill(date);
  await page
    .getByRole("button", { name: "Find available times", exact: true })
    .click();
  await page.getByRole("radio", { name: new RegExp(time) }).check();
}
async function command(page: Page, url: string, body: Record<string, unknown>) {
  return await page.evaluate(
    async ({ url, body }) => {
      const token = decodeURIComponent(
        document.cookie
          .split("; ")
          .find((value) => value.startsWith("XSRF-TOKEN="))
          ?.slice(11) ?? "",
      );
      const response = await fetch(url, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-XSRF-TOKEN": token,
        },
        body: JSON.stringify(body),
      });
      return { status: response.status, body: await response.json() };
    },
    { url, body },
  );
}
test("two customers compete for one truck and manage the winning booking through the planner", async ({
  page,
  browser,
}) => {
  test.setTimeout(180000);
  const data = fixture();
  const otherContext = await browser.newContext();
  const other = await otherContext.newPage();
  try {
    await page.goto(data.customers[0].view);
    await search(page, data.date, "09:00");
    await page
      .getByRole("button", { name: "Hold selected time", exact: true })
      .click();
    await expect(
      page.getByRole("heading", {
        name: "Resources temporarily held",
        exact: true,
      }),
    ).toBeVisible();
    await expect(page.getByTestId("booking-status")).toHaveText(
      "Temporarily held",
    );
    await other.goto(data.customers[1].view);
    const conflict = await command(other, data.customers[1].hold, {
      starts_at: data.start,
      idempotency_key: crypto.randomUUID(),
    });
    expect(conflict.status).toBe(409);
    expect(conflict.body.reasons[0].code).toBe("resource_conflict");
    await other
      .getByRole("button", { name: "Find available times", exact: true })
      .click();
    await expect(other.getByRole("radio", { name: /09:00/ })).toHaveCount(0);
    await expect(other.getByRole("radio", { name: /10:00/ })).toBeVisible();
    const confirmedResponse = page.waitForResponse(
      (response) =>
        response.request().method() === "POST" &&
        response.url().includes("/booking/confirm?"),
    );
    await page
      .getByRole("button", {
        name: "Request booking confirmation",
        exact: true,
      })
      .click();
    const confirmed = await (await confirmedResponse).json();
    expect(confirmed.booking.status).toBe("confirmed");
    expect(confirmed.quote.booking_confirmed).toBe(true);
    const reference = confirmed.booking.reference;
    await expect(page.getByTestId("booking-status")).toHaveText("confirmed");
    await page.reload();
    await expect(
      page.getByRole("heading", { name: reference, exact: true }),
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Reschedule booking", exact: true })
      .click();
    await search(page, data.date, "11:00");
    await page
      .getByRole("button", { name: "Hold selected time", exact: true })
      .click();
    await expect(
      page.getByText(
        "Your existing booking remains in place until the replacement is confirmed.",
        { exact: true },
      ),
    ).toBeVisible();
    await page
      .getByLabel("Reason for rescheduling", { exact: true })
      .fill("Customer requested a later pickup.");
    await page
      .getByRole("button", { name: "Confirm reschedule", exact: true })
      .click();
    await expect(page.getByText("11:00–11:30", { exact: false })).toBeVisible();
    const operations = await browser.newPage();
    await operations.goto(origin + "/login");
    await operations
      .getByLabel("Email", { exact: true })
      .fill("browser-owner@example.test");
    await operations
      .getByLabel("Password", { exact: true })
      .fill("browser-tests-only-password");
    await operations
      .getByRole("button", { name: "Sign in", exact: true })
      .click();
    await expect(
      operations.getByRole("heading", { name: "Your workspaces" }),
    ).toBeVisible();
    await operations.goto(origin + "/c/quotation-company/bookings");
    await operations
      .getByLabel("Search bookings", { exact: true })
      .fill(reference);
    await operations
      .getByRole("button", { name: "Apply filters", exact: true })
      .click();
    await operations
      .getByRole("link", { name: reference, exact: true })
      .click();
    await expect(
      operations.getByRole("heading", {
        name: "Allocated resources",
        exact: true,
      }),
    ).toBeVisible();
    await operations
      .getByLabel("Internal operations note", { exact: true })
      .fill("PRIVATE_OPERATIONS_NOTE_NOT_FOR_CUSTOMERS");
    await operations
      .getByRole("button", { name: "Save internal note", exact: true })
      .click();
    await expect(
      operations.getByText("Internal note saved.", { exact: true }),
    ).toBeVisible();
    await operations.goto(
      origin + "/c/quotation-company/planner?date=" + data.date + "&view=day",
    );
    await expect(
      operations.getByRole("heading", {
        name: "Operations planner",
        exact: true,
      }),
    ).toBeVisible();
    await expect(
      operations.getByRole("link").filter({ hasText: reference }),
    ).toHaveCount(1);
    await operations.screenshot({
      path: ".cache/phase4-planner.png",
      fullPage: true,
    });
    await operations
      .getByRole("combobox", { name: "Planner resource kind", exact: true })
      .selectOption("staff");
    await expect(
      operations.getByRole("link").filter({ hasText: reference }),
    ).toHaveCount(2);
    await page
      .getByLabel("Cancellation reason", { exact: true })
      .fill("Customer cancelled within policy.");
    await page
      .getByRole("button", { name: "Cancel booking", exact: true })
      .click();
    await expect(page.getByTestId("booking-status")).toHaveText("cancelled");
    const current = await (
      await page.request.get(data.customers[0].view, {
        headers: { Accept: "application/json" },
      })
    ).json();
    expect(JSON.stringify(current)).not.toContain(
      "PRIVATE_OPERATIONS_NOTE_NOT_FOR_CUSTOMERS",
    );
    expect(current.booking.status).toBe("cancelled");
    await other
      .getByRole("button", { name: "Find available times", exact: true })
      .click();
    await expect(other.getByRole("radio", { name: /11:00/ })).toBeVisible();
    await operations.close();
  } finally {
    await otherContext.close();
  }
});
test("expired hold countdown disables customer confirmation without claiming a booking", async ({
  page,
}) => {
  test.setTimeout(90000);
  const data = fixture();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.clock.install();
  await page.goto(data.customers[2].view);
  await search(page, data.date, "20:00");
  await page
    .getByRole("button", { name: "Hold selected time", exact: true })
    .click();
  await expect(page.getByRole("timer")).toContainText("Hold expires");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await page.clock.fastForward(16 * 60 * 1000);
  await expect(page.getByRole("timer")).toContainText("This hold expired");
  await expect(
    page.getByRole("button", {
      name: "Request booking confirmation",
      exact: true,
    }),
  ).toBeDisabled();
  await expect(page.getByTestId("booking-status")).not.toHaveText("confirmed");
});
