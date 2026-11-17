import { test, expect } from "@playwright/test";
test("presents phase one services and the company sign in", async ({
  page,
}) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { level: 1 })).toContainText(
    "smoother move",
  );
  await expect(page.getByRole("heading", { level: 3 })).toHaveCount(3);
  await expect(
    page.getByRole("link", { name: "Company sign in" }),
  ).toHaveAttribute("href", "http://localhost:8080/login");
});
test("services remain readable on a narrow screen", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto("/");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
});
