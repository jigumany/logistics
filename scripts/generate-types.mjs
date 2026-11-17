import { readFile, writeFile } from "node:fs/promises";
import { compile } from "json-schema-to-typescript";
const platform = JSON.parse(
  await readFile(
    new URL("../packages/contracts/platform.schema.json", import.meta.url),
    "utf8",
  ),
);
const quotations = JSON.parse(
  await readFile(
    new URL("../packages/contracts/quotation.schema.json", import.meta.url),
    "utf8",
  ),
);
const generated = await compile(platform, "PlatformContract", {
  unreachableDefinitions: true,
  bannerComment:
    "/* Generated from packages/contracts/platform.schema.json. */",
});
const quoteTypes = await compile(quotations, "QuotationContract", {
  ignoreMinAndMaxItems: true,
  unreachableDefinitions: true,
  bannerComment:
    "/* Generated from packages/contracts/quotation.schema.json. */",
});
await writeFile(
  new URL("../packages/types/src/index.ts", import.meta.url),
  generated + '\nexport type * from "./quotations";\n',
);
await writeFile(
  new URL("../packages/types/src/quotations.ts", import.meta.url),
  quoteTypes,
);
