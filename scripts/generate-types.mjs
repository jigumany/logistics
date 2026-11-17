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
  generated +
    '\nexport type * from "./quotations";\nexport type * from "./bookings";\n',
);
await writeFile(
  new URL("../packages/types/src/quotations.ts", import.meta.url),
  quoteTypes,
);

const bookings = JSON.parse(
  await readFile(
    new URL("../packages/contracts/booking.schema.json", import.meta.url),
    "utf8",
  ),
);
await writeFile(
  new URL("../packages/types/src/bookings.ts", import.meta.url),
  await compile(bookings, "BookingContract", {
    ignoreMinAndMaxItems: true,
    unreachableDefinitions: true,
    bannerComment:
      "/* Generated from packages/contracts/booking.schema.json. */",
  }),
);
