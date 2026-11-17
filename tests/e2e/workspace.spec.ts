import { test, expect, type Page } from "@playwright/test";
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
test("company sidebar follows the current section and closes after mobile navigation", async ({
  page,
}) => {
  await owner(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + "/fleet");
  await expect(
    page.getByRole("navigation", { name: "Company navigation" }),
  ).not.toBeVisible();
  await page.getByRole("button", { name: "Open menu", exact: true }).click();
  const nav = page.getByRole("navigation", { name: "Company navigation" });
  await expect(
    nav.getByRole("link", { name: "Fleet", exact: true }),
  ).toHaveAttribute("aria-current", "page");
  await nav
    .getByRole("link", { name: "Staff and drivers", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Staff and drivers", exact: true }),
  ).toBeVisible();
  await expect(nav).not.toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
  await page.getByRole("button", { name: "Open menu", exact: true }).click();
  await page.keyboard.press("Escape");
  await expect(nav).not.toBeVisible();
});
test("service territories can be selected and hidden without changing saved coverage", async ({
  page,
}) => {
  await page.route("https://*.tile.openstreetmap.org/**", (route) =>
    route.abort(),
  );
  await owner(page);
  const name = "Workspace example territory";
  await page.goto(base + "/zones/create");
  await page.getByLabel("Zone name", { exact: true }).fill(name);
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
  await page.goto(base + "/zones");
  await expect(
    page.getByRole("heading", { name: "Service territories" }),
  ).toBeVisible();
  await page.getByRole("button", { name: new RegExp("^" + name) }).click();
  await expect(
    page.getByRole("link", { name: /^Edit service area/ }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Hide " + name, exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Show " + name, exact: true }),
  ).toHaveAttribute("aria-pressed", "false");
  await page
    .getByRole("button", { name: "Show all areas", exact: true })
    .click();
  await expect(
    page.getByText("Example only", { exact: true }).first(),
  ).toBeVisible();
});
test("working hours appear in a resource by day board with overnight context", async ({
  page,
}) => {
  await owner(page);
  await page.goto(base + "/calendar/weekly/create");
  await page
    .getByLabel("Working day", { exact: true })
    .selectOption({ label: "Tuesday" });
  await page.getByLabel("Opening time", { exact: true }).fill("21:00");
  await page.getByLabel("Closing time", { exact: true }).fill("05:00");
  await page.getByLabel("Closes on the following day", { exact: true }).check();
  await page
    .getByRole("button", { name: "Save weekly schedule", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText(
    "Calendar configuration saved",
  );
  await page.goto(base + "/calendar/weekly");
  const board = page.getByRole("region", { name: "Weekly working hours" });
  await expect(board.getByText("21:00 – 05:00", { exact: true })).toBeVisible();
  await expect(
    board.getByText("+1 day · Africa/Johannesburg", { exact: true }).first(),
  ).toBeVisible();
  await page
    .getByRole("navigation", { name: "Calendar sections" })
    .getByRole("link", { name: "Resource blackout periods" })
    .click();
  await expect(
    page.getByRole("heading", {
      name: "Resource blackout periods",
      exact: true,
    }),
  ).toBeVisible();
});
