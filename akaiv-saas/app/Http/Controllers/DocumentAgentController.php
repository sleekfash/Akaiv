<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DocumentAgentController extends Controller
{
    public function analyze(Request $request, Document $document): JsonResponse
    {
        abort_unless(config('archive.ai_enabled'), 404);
        $this->authorize('view', $document);

        $endpoint = config('services.document_agent.url');
        $secret = config('services.document_agent.secret');

        if (! $endpoint || ! $secret) {
            throw new RuntimeException('Document agent service is not configured.');
        }

        $response = Http::acceptJson()
            ->withToken($secret)
            ->timeout(30)
            ->post(rtrim($endpoint, '/').'/api/analyze-document', [
                'uuid' => $document->uuid,
                'friendly_name' => $document->friendly_name,
                'original_filename' => $document->original_filename,
                'description' => $document->description,
                'extracted_text' => $document->extracted_text,
                'mime_type' => $document->mime_type,
            ]);

        if ($response->failed()) {
            return response()->json([
                'message' => 'The document agent could not analyze this document.',
                'status' => $response->status(),
            ], 502);
        }

        return response()->json($response->json());
    }
}
