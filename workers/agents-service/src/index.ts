export interface Env {
  DOCUMENT_ASSISTANT: DurableObjectNamespace;
  AGENT_SHARED_SECRET: string;
  ALLOWED_ORIGIN?: string;
}

/** Reserved Phase 2 interface. Phase 1 accepts and processes no document content. */
export type DocumentPayload = {
  uuid: string;
  friendly_name: string;
  original_filename: string;
  description?: string | null;
  extracted_text?: string | null;
  mime_type?: string | null;
};

function unavailable(): Response {
  return Response.json({ error: "Not found" }, { status: 404 });
}

/** Preserve the existing binding name without activating an analysis service. */
export class DocumentAssistant {
  async fetch(): Promise<Response> {
    return unavailable();
  }
}

export default {
  async fetch(): Promise<Response> {
    return unavailable();
  },
};
