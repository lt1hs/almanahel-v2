import type { NextConfig } from "next";
import path from "path";
import { fileURLToPath } from "url";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

const frontendRoot = path.dirname(fileURLToPath(import.meta.url));

const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  images: {
    unoptimized: true,
  },
  // Pin tracing to this app. Do not set turbopack.root to the same folder:
  // Next.js 16 then resolves CSS @import from the parent directory
  // (https://github.com/vercel/next.js/issues/90307).
  outputFileTracingRoot: frontendRoot,
};

export default withNextIntl(nextConfig);
