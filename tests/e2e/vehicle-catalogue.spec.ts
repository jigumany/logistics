import { test, expect } from "@playwright/test";
test("administrator creates a truck from demo catalogue defaults and replaces them with verified specifications", async ({
  page,
}) => {
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
  await page.goto("http://127.0.0.1:8081/c/browser-movers/fleet/create");
  await page
    .getByLabel("Vehicle name", { exact: true })
    .fill("Catalogue browser truck");
  await page
    .getByLabel("Registration number", { exact: true })
    .fill("BROWSERBOX1");
  await page
    .getByLabel("Vehicle type", { exact: true })
    .selectOption("demo_light_box_truck");
  await page.getByRole("button", { name: "Save vehicle", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Saved successfully");
  await expect(
    page.getByLabel("Cargo length (m)", { exact: true }),
  ).toHaveValue("4.2");
  await expect(
    page.getByLabel("Maximum payload (kg)", { exact: true }),
  ).toHaveValue("2000");
  await expect(
    page.getByLabel("Actual vehicle capacity verified", { exact: true }),
  ).not.toBeChecked();
  await page.getByLabel("Maximum payload (kg)", { exact: true }).fill("1800");
  await page.getByLabel("Usable volume (m³)", { exact: true }).fill("14");
  await page
    .getByLabel("Capacity verification evidence", { exact: true })
    .fill(
      "Actual measurements and manufacturer payload plate checked for this browser scenario.",
    );
  await page
    .getByLabel("Actual vehicle capacity verified", { exact: true })
    .check();
  await page
    .getByLabel("Operational status", { exact: true })
    .selectOption("available");
  await Promise.all([
    page.waitForResponse(
      (response) =>
        response.request().method() === "PUT" &&
        response.url().includes("/fleet/"),
    ),
    page.getByRole("button", { name: "Save vehicle", exact: true }).click(),
  ]);
  await expect(
    page.getByRole("button", { name: "Save vehicle", exact: true }),
  ).toBeEnabled();
  await expect(page.getByRole("status")).toContainText("Saved successfully");
  await page.reload();
  await expect(
    page.getByLabel("Maximum payload (kg)", { exact: true }),
  ).toHaveValue("1800");
  await expect(
    page.getByLabel("Actual vehicle capacity verified", { exact: true }),
  ).toBeChecked();
});
