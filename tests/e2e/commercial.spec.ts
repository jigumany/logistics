import { test, expect, type Page } from "@playwright/test";
test.use({ actionTimeout: 15000 });
const base = "http://127.0.0.1:8081/c/browser-movers";
async function owner(page: Page) {
  await page.goto("http://127.0.0.1:8081/login");
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
test("owner can configure commercial allowances internal costs and margin policy in a draft", async ({
  page,
}) => {
  test.setTimeout(120000);
  await owner(page);
  await page.goto(base + "/price-books/create");
  await page
    .getByLabel("Price book name", { exact: true })
    .fill("Illustrative commercial browser tariff");
  const rates = page.getByLabel("Rate (ZAR)", { exact: true });
  await rates.nth(0).fill("100");
  await rates.nth(1).fill("5");
  await page
    .getByLabel("Use commercial controls and readiness validation", {
      exact: true,
    })
    .check();
  await page
    .getByLabel("Operating cost journey basis", { exact: true })
    .selectOption("customer");
  await page
    .getByLabel("Toll treatment", { exact: true })
    .selectOption("included");
  await page.getByLabel("Included distance (km)", { exact: true }).fill("10");
  await page
    .getByLabel("Minimum chargeable distance (km)", { exact: true })
    .fill("5");
  await page.getByLabel("Minimum labour hours", { exact: true }).fill("2");
  await page.getByLabel("Fuel cost per km", { exact: true }).fill("1.25");
  await page.getByLabel("Driver cost per hour", { exact: true }).fill("20");
  await page
    .getByLabel("Additional mover cost per hour", { exact: true })
    .fill("10");
  await page
    .getByLabel("Target contribution margin (%)", { exact: true })
    .fill("30");
  await page
    .getByLabel("Minimum acceptable margin (%)", { exact: true })
    .fill("20");
  await page
    .getByLabel("Tax status and the matching tax rules have been reviewed", {
      exact: true,
    })
    .check();
  await page
    .getByLabel(
      "Operating costs, zero-cost items and margin thresholds have been reviewed",
      { exact: true },
    )
    .check();
  await page
    .getByRole("button", { name: "Add pricing rule", exact: true })
    .click();
  const minimum = page.getByRole("group", { name: "Rule 3", exact: true });
  await minimum
    .getByLabel("Customer-facing label", { exact: true })
    .fill("Illustrative minimum charge");
  await minimum
    .getByLabel("Calculation stage", { exact: true })
    .selectOption("minimum");
  await minimum.getByLabel("Rate (ZAR)", { exact: true }).fill("200");
  for (const box of await page
    .getByRole("group", { name: "Included pricing components", exact: true })
    .getByRole("checkbox")
    .all())
    await box.check();
  await page.getByLabel("base", { exact: true }).uncheck();
  await page.getByLabel("chargeable km", { exact: true }).uncheck();
  await page
    .getByRole("button", { name: "Save draft price book", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText(
    "Draft price book saved",
  );
  await page.reload();
  await expect(
    page.getByLabel("Fuel cost per km", { exact: true }),
  ).toHaveValue("1.250000");
  await expect(
    page.getByLabel("Minimum acceptable margin (%)", { exact: true }),
  ).toHaveValue("20.00");
  await expect(
    page.getByLabel("Included distance (km)", { exact: true }),
  ).toHaveValue("10");
});
test("draft insurance information can be saved while readiness remains incomplete", async ({
  page,
}) => {
  await owner(page);
  await page.goto(base + "/quotation-policies");
  const form = page.locator("section").filter({
    has: page.getByRole("heading", { name: "Household moving", exact: true }),
  });
  await form
    .getByLabel("Customer quotation terms", { exact: true })
    .fill("Illustrative browser terms. No booking is confirmed.");
  await form
    .getByLabel("Goods-in-transit insurance information", { exact: true })
    .fill("Unverified browser insurance information.");
  await form
    .getByRole("button", { name: "Save quotation policy", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText(
    "Quotation policy saved",
  );
  await page.reload();
  await expect(
    form.getByLabel("Insurance information review", { exact: true }),
  ).toHaveValue("draft");
  await expect(
    form.getByText("Saved terms version:", { exact: false }),
  ).toBeVisible();
  await page.goto(base);
  await expect(
    page.getByRole("heading", { name: "Quotation readiness", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("configuration incomplete", { exact: true }).first(),
  ).toBeVisible();
});
