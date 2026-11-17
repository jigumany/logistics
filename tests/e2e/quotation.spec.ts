import { test, expect, type Page } from "@playwright/test";
test.use({ actionTimeout: 15000 });
const origin = "http://127.0.0.1:8081";
const wizard = origin + "/q/browser-movers";
async function locations(page: Page) {
  await page
    .getByLabel("Preferred moving date", { exact: true })
    .fill(new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10));
  const pickup = page.getByRole("group", { name: "Pickup", exact: true });
  await pickup
    .getByLabel("Street address", { exact: true })
    .fill("1 Browser Pickup Street");
  await pickup.getByLabel("City", { exact: true }).fill("Cape Town");
  const delivery = page.getByRole("group", { name: "Delivery", exact: true });
  await delivery
    .getByLabel("Street address", { exact: true })
    .fill("2 Browser Delivery Street");
  await delivery.getByLabel("City", { exact: true }).fill("Cape Town");
}
async function owner(page: Page) {
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
}
test("quotation wizard validates locations and recovers a draft on mobile", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(wizard);
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Locations", exact: true }),
  ).toBeVisible();
  await locations(page);
  await page.reload();
  await expect(
    page
      .getByRole("group", { name: "Pickup", exact: true })
      .getByLabel("Street address", { exact: true }),
  ).toHaveValue("1 Browser Pickup Street");
  await expect(
    page.getByText("Your draft was recovered", { exact: false }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
});
test("guest submits a reviewed move and an owner approves an immutable offer for acceptance", async ({
  page,
  browser,
}) => {
  test.setTimeout(180000);
  await page.goto(wizard);
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await locations(page);
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page
    .getByRole("button", { name: "Add a custom item", exact: true })
    .click();
  await page
    .getByLabel("Item name", { exact: true })
    .fill("Browser quotation crate");
  await page.getByLabel("Length (cm)", { exact: true }).fill("100");
  await page.getByLabel("Width (cm)", { exact: true }).fill("50");
  await page.getByLabel("Height (cm)", { exact: true }).fill("40");
  await page.getByLabel("Weight per item (kg)", { exact: true }).fill("10");
  await page
    .getByLabel("Photos (up to 5, JPG/PNG/WebP, 5 MB each)", { exact: true })
    .setInputFiles({
      name: "crate.png",
      mimeType: "image/png",
      buffer: Buffer.from(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j7i8AAAAASUVORK5CYII=",
        "base64",
      ),
    });
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page
    .getByRole("button", { name: "Calculate estimate", exact: true })
    .click();
  await expect(
    page.getByRole("heading", {
      name: "Your request needs a review",
      exact: true,
    }),
  ).toBeVisible({ timeout: 30000 });
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page
    .getByLabel("Full name", { exact: true })
    .fill("Browser Quotation Customer");
  await page
    .getByLabel("Email address", { exact: true })
    .fill("quotation-browser@example.test");
  await page
    .getByLabel("I consent to using these details", { exact: false })
    .check();
  await page
    .getByRole("button", { name: "Submit quotation request", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: /reviewing your request/ }),
  ).toBeVisible({ timeout: 30000 });
  const guestUrl = page.url();
  const quoteId = new URL(guestUrl).pathname.split("/").pop();
  await expect(
    page.getByRole("link", { name: "Download inventory photo 1", exact: true }),
  ).toBeVisible();
  const context = await browser.newContext();
  try {
    const admin = await context.newPage();
    await owner(admin);
    await admin.goto(origin + "/c/browser-movers/quotes/" + quoteId);
    await admin.getByLabel("Decision", { exact: true }).selectOption("approve");
    await admin
      .getByLabel("Internal reason / review note", { exact: true })
      .fill("Route and load reviewed for this browser quotation.");
    await admin
      .getByLabel("Description", { exact: true })
      .fill("Reviewed transport and loading");
    await admin.getByLabel("Amount (ZAR)", { exact: true }).fill("1250.00");
    await admin
      .getByLabel("Tax percentage (0 if not applicable)", { exact: true })
      .fill("0");
    await admin
      .getByLabel("Terms", { exact: true })
      .fill(
        "Transport under the submitted scope. Acceptance does not confirm a booking.",
      );
    await admin
      .getByRole("button", { name: "Issue approved revision", exact: true })
      .click();
    await expect(admin.getByRole("status")).toContainText(
      "Quotation review recorded",
      { timeout: 30000 },
    );
  } finally {
    await context.close().catch(() => {});
  }
  await page.goto(guestUrl);
  await expect(
    page.getByRole("heading", { name: "Your itemised estimate", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Reviewed transport and loading", { exact: true }),
  ).toBeVisible();
  await page.getByLabel("I accept the terms", { exact: false }).check();
  await page
    .getByRole("button", { name: "Accept quotation", exact: true })
    .click();
  await expect(
    page.getByRole("heading", {
      name: "Thank you. Your offer is accepted.",
      exact: true,
    }),
  ).toBeVisible({ timeout: 30000 });
  await expect(
    page.getByRole("button", { name: "Accept quotation", exact: true }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "Download quotation PDF", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("being prepared", {
    timeout: 30000,
  });
  const download = page.waitForEvent("download");
  await page
    .getByRole("button", { name: "Download quotation PDF", exact: true })
    .click();
  expect((await download).suggestedFilename()).toBe("quotation.pdf");
  await page.screenshot({
    path: ".cache/phase3-accepted-quote.png",
    fullPage: true,
  });
});
test("owner can save a company catalogue item and a complete draft price book", async ({
  page,
}) => {
  test.setTimeout(120000);
  await owner(page);
  await page.goto(origin + "/c/browser-movers/inventory/create");
  await page
    .getByLabel("Item name", { exact: true })
    .fill("Browser catalogue carton");
  await page.getByLabel("Category", { exact: true }).fill("boxes");
  await page.getByLabel("Standard length (cm)", { exact: true }).fill("50");
  await page.getByLabel("Standard width (cm)", { exact: true }).fill("40");
  await page.getByLabel("Standard height (cm)", { exact: true }).fill("30");
  await page
    .getByRole("button", { name: "Save catalogue item", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("Catalogue item saved", {
    timeout: 30000,
  });
  await page.goto(origin + "/c/browser-movers/price-books/create");
  await page
    .getByLabel("Price book name", { exact: true })
    .fill("Browser draft tariff");
  const rates = page.getByLabel("Rate (ZAR)", { exact: true });
  await rates.nth(0).fill("100.00");
  await rates.nth(1).fill("5.00");
  for (const checkbox of await page
    .getByRole("group", { name: "Included pricing components" })
    .getByRole("checkbox")
    .all()) {
    await checkbox.check();
  }
  await page.getByLabel("base", { exact: true }).uncheck();
  await page.getByLabel("chargeable km", { exact: true }).uncheck();
  await page
    .getByRole("button", { name: "Save draft price book", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText(
    "Draft price book saved",
    { timeout: 30000 },
  );
  await page.getByLabel("I have checked every rate", { exact: false }).check();
  await page
    .getByRole("button", { name: "Publish price book", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("publish completed", {
    timeout: 30000,
  });
  await expect(
    page.getByRole("button", { name: "Retire this version", exact: true }),
  ).toBeVisible();
});
