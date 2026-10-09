import { defineWorkersConfig } from "@cloudflare/vitest-pool-workers/config";

export default defineWorkersConfig({
  test: {
    poolOptions: {
      workers: {
        // The disabled worker is stateless; avoid DO SQLite snapshot cleanup in this harness.
        isolatedStorage: false,
        wrangler: { configPath: "./wrangler.jsonc" },
        miniflare: {
          bindings: {
            AGENT_SHARED_SECRET: "test-secret",
            ALLOWED_ORIGIN: "https://akaiv.localhost",
          },
        },
      },
    },
  },
});
