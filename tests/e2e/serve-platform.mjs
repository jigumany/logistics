import { spawn, spawnSync } from "node:child_process";
import { mkdirSync, writeFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";
const root = fileURLToPath(new URL("../../", import.meta.url));
mkdirSync(path.join(root, ".cache"), { recursive: true });
const db = path.join(root, ".cache", "playwright.sqlite");
if (!existsSync(db)) writeFileSync(db, "");
const env = {
  ...process.env,
  APP_ENV: "testing",
  APP_DEBUG: "false",
  APP_URL: "http://127.0.0.1:8081",
  DB_CONNECTION: "sqlite",
  DB_DATABASE: db,
  DB_URL: "",
  SESSION_DRIVER: "file",
  FILESYSTEM_DISK: "local",
  CACHE_STORE: "array",
  QUEUE_CONNECTION: "sync",
  MAIL_MAILER: "array",
  SESSION_SECURE_COOKIE: "false",
};
const php = process.env.PHP_BINARY || "php";
const prepared = spawnSync(
  php,
  [path.join(root, "tests/e2e/prepare-platform.php")],
  { cwd: path.join(root, "apps/platform"), env, stdio: "inherit" },
);
if (prepared.status !== 0) process.exit(prepared.status || 1);
const server = spawn(
  php,
  ["artisan", "serve", "--host=127.0.0.1", "--port=8081"],
  {
    cwd: path.join(root, "apps/platform"),
    env: { ...env, APP_ENV: "local" },
    stdio: "inherit",
  },
);
for (const signal of ["SIGINT", "SIGTERM"])
  process.on(signal, () => server.kill());
server.on("exit", (code) => process.exit(code || 0));
