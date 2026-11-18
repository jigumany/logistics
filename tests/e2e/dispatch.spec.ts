import { test, expect } from "@playwright/test";
import { readFileSync } from "node:fs";
const origin = "http://127.0.0.1:8081";
test("dispatches a reserved team, completes the driver delivery and issues an immutable customer invoice", async ({
  page,
  browser,
}) => {
  test.setTimeout(180000);
  const f = JSON.parse(readFileSync(".cache/phase5-browser.json", "utf8"));
  const base = origin + "/c/" + f.company;
  async function login(email: string) {
    await page.goto(origin + "/login");
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page
      .getByLabel("Password", { exact: true })
      .fill("browser-tests-only-password");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await expect(
      page.getByRole("heading", { name: "Your workspaces" }),
    ).toBeVisible();
  }
  await login("browser-owner@example.test");
  await page.goto(base + "/dispatch");
  await expect(
    page.getByRole("heading", { name: "Dispatch board", exact: true }),
  ).toBeVisible();
  const card = page
    .locator("section")
    .filter({ has: page.getByRole("heading", { name: "Ready for dispatch" }) })
    .locator("div.rounded-xl")
    .filter({ hasText: f.reference });
  await card
    .getByRole("button", { name: "Dispatch reserved team", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: f.reference, exact: true }),
  ).toBeVisible();
  const jobUrl = page.url();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await login("browser-driver@example.test");
  await page.goto(base + "/dispatch");
  await expect(
    page.getByRole("heading", { name: "My driving jobs", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("link")
    .filter({
      has: page.getByRole("heading", { name: f.reference, exact: true }),
    })
    .click();
  await page.setViewportSize({ width: 390, height: 844 });
  for (const name of [
    "Mark accepted",
    "Mark en route",
    "Mark arrived",
    "Mark loading",
    "Mark in transit",
  ]) {
    await page.getByRole("button", { name, exact: true }).click();
  }
  await expect(
    page.getByRole("button", { name: "Mark delivered", exact: true }),
  ).toBeDisabled();
  await page
    .getByLabel("Recipient name", { exact: true })
    .fill("Browser Recipient");
  await page.getByLabel("Delivery photo", { exact: true }).setInputFiles({
    name: "delivery.png",
    mimeType: "image/png",
    buffer: Buffer.from(
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=",
      "base64",
    ),
  });
  await page
    .getByLabel("The named recipient acknowledges receiving this delivery.", {
      exact: true,
    })
    .check();
  await page
    .getByRole("button", { name: "Save delivery proof", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Delivery proof recorded", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Mark delivered", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Mark completed", exact: true })
    .click();
  await expect(
    page.getByText("This job is complete.", { exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  const denied = await page.request.get(base + "/finance");
  expect(denied.status()).toBe(403);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await login("browser-owner@example.test");
  await page.goto(base + "/finance");
  await page.getByLabel("Booking reference", { exact: true }).fill(f.reference);
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await page
    .getByRole("button", { name: "Issue invoice", exact: true })
    .click();
  const invoice = page.getByRole("link", { name: "INV-0000001", exact: true });
  await expect(invoice).toBeVisible();
  const pdf = await page.request.get(
    new URL((await invoice.getAttribute("href"))!, page.url()).href,
  );
  expect(pdf.status()).toBe(200);
  expect(pdf.headers()["content-type"]).toContain("application/pdf");
  expect((await pdf.body()).subarray(0, 5).toString()).toBe("%PDF-");
  await page.goto(jobUrl);
  await expect(
    page.getByText("This job is complete.", { exact: true }),
  ).toBeVisible();
  await page.screenshot({ path: ".cache/phase5-delivery.png", fullPage: true });
  const guest = await browser.newContext();
  const customer = await guest.newPage();
  await customer.goto(f.guest.view);
  await expect(
    customer.getByRole("heading", {
      name: "Payments and invoice",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    customer.getByRole("link", {
      name: "Download invoice INV-0000001",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    customer.getByText("Browser Recipient", { exact: true }),
  ).toHaveCount(0);
  await guest.close();
});
test("an administrator invites a new driver who creates their own password and opens the driver workspace", async ({
  page,
  browser,
}) => {
  test.setTimeout(120000);
  const f = JSON.parse(
    readFileSync(".cache/driver-invitation-browser.json", "utf8"),
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
  await page.goto(origin + "/c/" + f.company + "/dispatch");
  await page.getByText("Driver account invitations", { exact: true }).click();
  const candidate = page
    .getByRole("listitem")
    .filter({ hasText: "New Driver Invite" });
  await candidate
    .getByRole("button", { name: "Create invitation", exact: true })
    .click();
  const link = page.getByLabel(/Driver invitation link/);
  await expect(link).not.toHaveValue("");
  const url = await link.inputValue();
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 },
  });
  const driver = await ctx.newPage();
  await driver.goto(url);
  await expect(
    driver.getByRole("heading", {
      name: "Join the driver workspace",
      exact: true,
    }),
  ).toBeVisible();
  await driver
    .getByLabel("Choose a password", { exact: true })
    .fill("New-driver-password-123");
  await driver
    .getByLabel("Confirm password", { exact: true })
    .fill("New-driver-password-123");
  await driver
    .getByRole("button", { name: "Accept driver invitation", exact: true })
    .click();
  await expect(
    driver.getByRole("heading", { name: "My driving jobs", exact: true }),
  ).toBeVisible();
  expect((await driver.request.get(url)).status()).toBe(404);
  await ctx.close();
});
