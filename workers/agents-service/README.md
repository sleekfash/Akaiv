# Reserved document-analysis interface

Phase 1 disables this worker. Both the HTTP entrypoint and retained DocumentAssistant durable-object binding return 404 without processing or storing document content. Laravel does not call the worker. No deployment was performed.

The typed payload and binding name are reserved for a separately approved Phase 2 integration. Do not re-enable processing as routine Phase 1 remediation. Run npm ci, npm run typecheck, and npm test to verify containment.
