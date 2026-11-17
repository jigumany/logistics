import { test, expect, type Page } from "@playwright/test";
async function login(page: Page, email: string) {
  await page.goto("http://127.0.0.1:8081/login");
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page
    .getByLabel("Password", { exact: true })
    .fill("browser-tests-only-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Your workspaces" }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Browser Movers" }).click();
}
test("owner can save company settings and sign out", async ({ page }) => {
  await login(page, "browser-owner@example.test");
  await page.getByLabel("Company name").fill("Updated Browser Movers");
  await page.getByRole("button", { name: "Save settings" }).click();
  await expect(page.getByRole("status")).toContainText(
    "Company settings saved",
  );
  await page.reload();
  await expect(page.getByLabel("Company name")).toHaveValue(
    "Updated Browser Movers",
  );
  await page.getByRole("button", { name: "Sign out" }).click();
  await expect(page).toHaveURL("http://127.0.0.1:8081/login");
});
test("viewer receives read only controls", async ({ page }) => {
  await login(page, "browser-viewer@example.test");
  await expect(page.getByLabel("Company name")).toBeDisabled();
  await expect(page.getByRole("button", { name: "Save settings" })).toHaveCount(
    0,
  );
  const controls = page.getByRole("button", { name: "Enabled", exact: true });
  expect(await controls.count()).toBeGreaterThan(0);
  for (const button of await controls.all())
    await expect(button).toBeDisabled();
});
test("membership protects other companies", async ({ page }) => {
  await login(page, "browser-owner@example.test");
  const response = await page.goto("http://127.0.0.1:8081/c/private-company");
  expect(response?.status()).toBe(404);
});
test("csrf protection rejects unauthenticated POSTs without a token", async ({
  request,
}) => {
  const response = await request.post("http://127.0.0.1:8081/login", {
    form: {
      email: "browser-owner@example.test",
      password: "browser-tests-only-password",
    },
  });
  expect(response.status()).toBe(419);
});
