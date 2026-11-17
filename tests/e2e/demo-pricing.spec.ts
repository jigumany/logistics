import { test, expect } from "@playwright/test";
test.use({ actionTimeout: 15000 });
const origin = "http://127.0.0.1:8081";
test("administrator edits demo rates, compares versions and simulates an itemised move without issuing an offer", async ({
  page,
}) => {
  test.setTimeout(120000);
  await page.route("**/q/cape-town-movers/addresses/search?**", (route) =>
    route.fulfill({ json: { available: false, predictions: [] } }),
  );
  await page.goto(origin + "/login");
  await page
    .getByLabel("Email", { exact: true })
    .fill("browser-owner@example.test");
  await page
    .getByLabel("Password", { exact: true })
    .fill("browser-tests-only-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Your workspaces" }),
  ).toBeVisible();
  await page.goto(origin + "/c/cape-town-movers/price-books");
  await page
    .getByRole("link", {
      name: "Cape Town Logistics — Demo Rates",
      exact: true,
    })
    .click();
  await expect(
    page.getByText("Demonstration rates · not commercially approved", {
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Publish price book", exact: true }),
  ).toHaveCount(0);
  const editor = page.url();
  await expect(page.getByLabel("Tax status", { exact: true })).toHaveValue(
    "demo_zero",
  );
  await expect(page.getByLabel("Tax status", { exact: true })).toBeDisabled();
  await page.getByLabel("Included driver hours", { exact: true }).fill("2");
  const saved = page.waitForResponse(
    (response) =>
      response.request().method() === "PUT" && response.url() === editor,
  );
  await page
    .getByRole("button", { name: "Save draft price book", exact: true })
    .click();
  expect((await saved).status()).toBe(303);
  await expect(
    page.getByText("Draft pricing saved.", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("link", { name: "Copy to new version", exact: true })
    .click();
  await expect(page).toHaveURL(/\/price-books\/create\?copy=/);
  await expect(
    page.getByRole("heading", { name: "Create price book", exact: true }),
  ).toBeVisible();
  await page
    .getByLabel("Price book name", { exact: true })
    .fill("Demo comparison version");
  await page
    .getByRole("button", { name: "Save draft price book", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Demo comparison version", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("combobox", {
      name: "Compare with another service pricing version",
      exact: true,
    })
    .selectOption({
      label: "Version 1 · Cape Town Logistics — Demo Rates · Demo",
    });
  await expect(
    page.getByRole("heading", {
      name: "Version 2 compared with version 1",
      exact: true,
    }),
  ).toBeVisible();
  await page.goto(editor);
  await page
    .getByRole("link", { name: "Run quotation simulation", exact: true })
    .click();
  await expect(
    page.getByRole("heading", {
      name: "Internal quotation simulation",
      exact: true,
    }),
  ).toBeVisible();
  await page
    .getByRole("combobox", { name: "Catalogue vehicle type", exact: true })
    .selectOption("demo_light_box_truck");
  await page
    .getByLabel("Requested additional movers", { exact: true })
    .fill("1");
  await page.getByLabel("Test route distance (km)", { exact: true }).fill("25");
  await page
    .getByLabel("Test driving duration (minutes)", { exact: true })
    .fill("170");
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  const date = new Date(Date.now() + 7 * 86400000);
  while (date.getDay() !== 1) date.setDate(date.getDate() + 1);
  await page
    .getByLabel("Preferred moving date", { exact: true })
    .fill(date.toISOString().slice(0, 10));
  await page
    .getByLabel("Preferred start time (optional)", { exact: true })
    .fill("10:00");
  for (const title of ["Pickup", "Delivery"]) {
    const group = page.getByRole("group", { name: title, exact: true });
    await group
      .getByLabel("Street address", { exact: true })
      .fill(title + " Demo Street");
    await group.getByLabel("City", { exact: true }).fill("Cape Town");
  }
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page
    .getByRole("button", { name: "Add a custom item", exact: true })
    .click();
  await page
    .getByLabel("Item name", { exact: true })
    .fill("Demo measured crate");
  for (const [label, value] of [
    ["Length (cm)", "100"],
    ["Width (cm)", "50"],
    ["Height (cm)", "40"],
    ["Weight per item (kg)", "10"],
  ])
    await page.getByLabel(label, { exact: true }).fill(value);
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page.getByLabel("Pickup flights of stairs", { exact: true }).fill("1");
  const calculated = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      response.url().endsWith("/calculate"),
  );
  await page
    .getByRole("button", { name: "Calculate estimate", exact: true })
    .click();
  const result = await (await calculated).json();
  expect(result.revision.total_minor).toBe(157000);
  expect(result.revision.approval).toBe("pending");
  expect(result.revision.pricing_snapshot.version).toBe(1);
  await expect(
    page.getByRole("heading", {
      name: "How the price is calculated",
      exact: true,
    }),
  ).toBeVisible();
  const amounts: Record<string, number> = {
    demo_light_box_truck_base: 55000,
    demo_light_box_truck_distance: 35000,
    driver_extra: 12000,
    additional_movers: 45000,
    stairs: 10000,
    demo_light_box_truck_minimum: 0,
    demo_tax: 0,
  };
  expect(
    Object.fromEntries(
      result.revision.lines.map(
        (line: { code: string; amount_minor: number }) => [
          line.code,
          line.amount_minor,
        ],
      ),
    ),
  ).toEqual(amounts);
  for (const line of result.revision.lines) {
    const row = page.getByRole("row").filter({
      has: page.getByRole("cell", { name: line.description, exact: true }),
    });
    await expect(row.getByRole("cell").nth(2)).toHaveText(line.rate_display);
    await expect(row.getByRole("cell").nth(3)).toHaveText(
      new Intl.NumberFormat("en-ZA", {
        style: "currency",
        currency: "ZAR",
      }).format(line.amount_minor / 100),
    );
  }
  await expect(
    page.getByRole("heading", { name: "Why this price?", exact: true }),
  ).toBeVisible();
  await page
    .getByText("Applied rule identifiers and quantities", { exact: true })
    .click();
  await expect(
    page.getByText(/driver_extra · Driver labour beyond included hours/),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Submit quotation request", exact: true }),
  ).toHaveCount(0);
  expect(
    await page.evaluate(() =>
      Object.values(sessionStorage).some((value) =>
        String(value).includes("internal_costs"),
      ),
    ),
  ).toBe(false);
  const revisedResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      response.url().endsWith("/calculate"),
  );
  await page
    .getByRole("button", { name: "Recalculate simulation", exact: true })
    .click();
  await expect(page.getByText(/Revision 2/)).toBeVisible();
  const revised = await (await revisedResponse).json();
  expect(revised.revision.total_minor).toBe(157000);
  expect(revised.revision.lines).toEqual(result.revision.lines);
  await page.getByRole("button", { name: "Back", exact: true }).click();
  await page.getByRole("button", { name: "Back", exact: true }).click();
  await page.getByLabel("Weight per item (kg)", { exact: true }).fill("2100");
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  const overloadedResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      response.url().endsWith("/calculate"),
  );
  await page
    .getByRole("button", { name: "Calculate estimate", exact: true })
    .click();
  const overloaded = await (await overloadedResponse).json();
  expect(overloaded.status).toBe("requires_review");
  expect(overloaded.revision.total_minor).toBeNull();
  expect(overloaded.revision.approval).toBe("pending");
  expect(overloaded.revision.suitability[0].technically_fits).toBe(false);
  expect(
    overloaded.revision.suitability[0].reasons.map(
      (reason: { code: string }) => reason.code,
    ),
  ).toContain("capacity_exceeded");
  await expect(
    page.getByText("The price will be prepared after review.", { exact: true }),
  ).toBeVisible();
});
