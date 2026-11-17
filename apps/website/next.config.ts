import type { NextConfig } from "next";
const config: NextConfig = {
  agentRules: false,
  allowedDevOrigins: ["127.0.0.1"],
  output: "standalone",
  transpilePackages: ["@logistics/design-system"],
};
export default config;
