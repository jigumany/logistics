import { test, expect, type Page } from "@playwright/test";
const base = "http://127.0.0.1:8081/c/browser-movers";
async function signIn(page: Page) {
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
test("administrator can create a depot", async ({ page }) => {
  await signIn(page);
  await page.route("https://*.tile.openstreetmap.org/**", (route) =>
    route.abort(),
  );
  await page.goto(base + "/depots/create");
  await page.getByLabel("Depot name", { exact: true }).fill("Browser Depot");
  await page
    .getByLabel("Street address", { exact: true })
    .fill("Development address");
  await page.getByLabel("City", { exact: true }).fill("Cape Town");
  await page.getByLabel("Latitude", { exact: true }).fill("-33.92");
  await page.getByLabel("Longitude", { exact: true }).fill("18.42");
  await page.getByRole("button", { name: "Save depot", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Saved successfully");
});
test("administrator can create and screen a vehicle", async ({ page }) => {
  await signIn(page);
  await page.goto(base + "/fleet/create");
  await page
    .getByLabel("Vehicle name", { exact: true })
    .fill("Browser Vehicle");
  await page
    .getByLabel("Registration number", { exact: true })
    .fill("UI 123-456");
  await page
    .getByLabel("Operational status", { exact: true })
    .selectOption("available");
  await page.getByRole("button", { name: "Save vehicle", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Saved successfully");
  await expect(
    page.getByLabel("Usable volume (m³)", { exact: true }),
  ).toHaveValue("10");
  await page
    .getByLabel("Total estimated volume (m³)", { exact: true })
    .fill("1");
  await page
    .getByLabel("Total estimated weight (kg)", { exact: true })
    .fill("100");
  await page
    .getByRole("button", { name: "Screen proposed load", exact: true })
    .click();
  await expect(page.getByRole("status").last()).toContainText(
    "Passes screening",
  );
});
test("administrator can register staff without a login", async ({ page }) => {
  await signIn(page);
  await page.goto(base + "/staff/create");
  await page.getByLabel("Full name", { exact: true }).fill("Browser Mover");
  await page
    .getByRole("button", { name: "Save staff member", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("Saved successfully");
  await expect(
    page.getByLabel("Optional company login account", { exact: true }),
  ).toHaveValue("");
});
test("administrator can configure a service and a company calendar", async ({
  page,
}) => {
  await signIn(page);
  await page.goto(base + "/services/create");
  await page
    .getByLabel("System definition", { exact: true })
    .selectOption("office_relocation");
  await page
    .getByLabel("Public service name", { exact: true })
    .fill("Browser Office Moves");
  await page
    .getByRole("button", { name: "Save service configuration", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("Service configured");
  await page.goto(base + "/calendar/weekly/create");
  await page
    .getByLabel("Working day", { exact: true })
    .selectOption({ label: "Monday" });
  await page.getByLabel("Opening time", { exact: true }).fill("22:00");
  await page.getByLabel("Closing time", { exact: true }).fill("06:00");
  await page.getByLabel("Closes on the following day", { exact: true }).check();
  await page
    .getByRole("button", { name: "Save weekly schedule", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText(
    "Calendar configuration saved",
  );
});
test("zone boundary editor validates and saves GeoJSON without inventing coverage", async ({
  page,
}) => {
  await page.route("https://*.tile.openstreetmap.org/**", (route) =>
    route.abort(),
  );
  await signIn(page);
  await page.goto(base + "/zones/create");
  await page
    .getByLabel("Zone name", { exact: true })
    .fill("Browser development example");
  await page.getByLabel("GeoJSON boundary", { exact: true }).fill(
    JSON.stringify({
      type: "Polygon",
      coordinates: [
        [
          [18.3, -34.1],
          [18.6, -34.1],
          [18.6, -33.8],
          [18.3, -33.8],
          [18.3, -34.1],
        ],
      ],
    }),
  );
  await page.getByLabel("Development example only", { exact: true }).check();
  await page
    .getByRole("button", { name: "Save service area", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("Saved successfully");
  await expect(page.getByLabel("Active", { exact: true })).not.toBeChecked();
});
test("unsaved company settings require confirmation before navigation", async ({
  page,
}) => {
  await signIn(page);
  await page.goto(base + "/settings");
  await page.getByLabel("Trading name", { exact: true }).fill("Unsaved name");
  page.once("dialog", (dialog) => dialog.dismiss());
  await page.getByRole("link", { name: "Fleet", exact: true }).click();
  await expect(page).toHaveURL(base + "/settings");
  await expect(page.getByLabel("Trading name", { exact: true })).toHaveValue(
    "Unsaved name",
  );
});

test("administrator can save business profile percentages", async ({
  page,
}) => {
  await signIn(page);
  await page.goto(base + "/settings");
  await page
    .getByLabel("Registered business name", { exact: true })
    .fill("Browser Registered Business");
  await page.getByLabel("Deposit (%)", { exact: true }).fill("30.5");
  await page
    .getByRole("button", { name: "Save company profile", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText(
    "Company settings saved",
  );
  await page.reload();
  await expect(page.getByLabel("Deposit (%)", { exact: true })).toHaveValue(
    "30.5",
  );
  await expect(
    page.getByLabel("Registered business name", { exact: true }),
  ).toHaveValue("Browser Registered Business");
});
