import { SELF, env } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import type { Env } from "../src/index";

describe("Phase 1 disables all document processing entrypoints", () => {
  it.each([undefined, "wrong-secret", "test-secret"])("rejects analysis regardless of token %s", async (token) => {
    const response = await SELF.fetch("https://example.com/api/analyze-document", {
      method: "POST",
      headers: { "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: JSON.stringify({ uuid: "synthetic-document", friendly_name: "Synthetic", extracted_text: "Synthetic bytes" }),
    });
    expect(response.status).toBe(404);
  });

  it("does not expose the generic agent router or preflight", async () => {
    expect((await SELF.fetch("https://example.com/agents/document-assistant/example")).status).toBe(404);
    expect((await SELF.fetch("https://example.com/api/analyze-document", { method: "OPTIONS" })).status).toBe(404);
  });

  it("rejects direct requests to the retained durable-object binding", async () => {
    const namespace = (env as unknown as Env).DOCUMENT_ASSISTANT;
    const object = namespace.get(namespace.idFromName("synthetic-document"));
    expect((await object.fetch("https://example.com/analyze", { method: "POST", body: "synthetic" })).status).toBe(404);
  });
});
