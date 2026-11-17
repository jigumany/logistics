import { defineConfig } from "@playwright/test";
export default defineConfig({
  timeout: 60000,
  expect: { timeout: 15000 },
  testDir: "tests/e2e",
  workers: 1,
  use: { baseURL: "http://127.0.0.1:3000", trace: "retain-on-failure" },
  webServer: [
    {
      command: "npm run dev --workspace=@logistics/website",
      url: "http://127.0.0.1:3000",
      reuseExistingServer: !process.env.CI,
      timeout: 120000,
    },
    {
      command: "node tests/e2e/serve-platform.mjs",
      url: "http://127.0.0.1:8081/login",
      reuseExistingServer: false,
      timeout: 240000,
    },
  ],
  projects: [{ name: "chromium", use: { browserName: "chromium" } }],
});
