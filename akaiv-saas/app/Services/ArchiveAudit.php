<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArchiveAudit
{
    public function append(int $organization, ?int $actor, string $action, string $type, string $target, array $metadata = []): array
    {
        return DB::transaction(function () use ($organization, $actor, $action, $type, $target, $metadata) {
            $head = DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();
            $sequence = $head->sequence + 1;
            $time = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $payload = [
                'sequence' => $sequence, 'event_id' => (string) Str::uuid(), 'organization_id' => $organization,
                'actor_id' => $actor, 'action' => $action, 'target_type' => $type, 'target_id' => $target,
                'occurred_at' => $time, 'previous_hash' => $head->hash,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
                'user_agent' => app()->runningInConsole() ? null : request()->userAgent(), 'metadata' => $metadata,
            ];
            $canonical = json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $hash = hash('sha256', $canonical);
            DB::table('archive_audit_events')->insert([
                'sequence' => $sequence, 'event_id' => $payload['event_id'], 'organization_id' => $organization,
                'actor_id' => $actor, 'action' => $action, 'target_type' => $type, 'target_id' => $target,
                'canonical_payload' => $canonical, 'previous_hash' => $head->hash, 'current_hash' => $hash,
                'occurred_at' => $payload['occurred_at'],
            ]);
            DB::table('audit_chain_heads')->where('id', 1)->update(['sequence' => $sequence, 'hash' => $hash]);

            return ['sequence' => $sequence, 'hash' => $hash];
        });
    }

    public function verify(?array $checkpoint = null): array
    {
        return DB::transaction(function () use ($checkpoint) {
            $head = DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();
            $previous = null;
            $sequence = 0;
            $checkpointSeen = $checkpoint === null || (int) ($checkpoint['sequence'] ?? -1) === 0;
            foreach (DB::table('archive_audit_events')->orderBy('sequence')->cursor() as $row) {
                $data = json_decode($row->canonical_payload, true, 512, JSON_THROW_ON_ERROR);
                if ((int) $row->sequence !== ++$sequence || $row->previous_hash !== $previous
                    || ! hash_equals($row->current_hash, hash('sha256', $row->canonical_payload))) {
                    throw new \RuntimeException('Audit chain integrity failure at sequence '.$sequence);
                }
                foreach (['sequence', 'event_id', 'organization_id', 'actor_id', 'action', 'target_type', 'target_id', 'previous_hash'] as $field) {
                    if ((string) ($data[$field] ?? '') !== (string) ($row->$field ?? '')) {
                        throw new \RuntimeException('Audit envelope mismatch: '.$field);
                    }
                }
                if (Carbon::parse($data['occurred_at'])->utc()->format('Y-m-d\TH:i:s.u\Z') !== Carbon::parse($row->occurred_at)->utc()->format('Y-m-d\TH:i:s.u\Z')) {
                    throw new \RuntimeException('Audit timestamp mismatch');
                }
                $previous = $row->current_hash;
                if ($checkpoint && $sequence === (int) $checkpoint['sequence']) {
                    if (! hash_equals($previous, $checkpoint['hash'])) {
                        throw new \RuntimeException('External checkpoint mismatch');
                    }
                    $checkpointSeen = true;
                }
            }
            if ($sequence !== (int) $head->sequence || $previous !== $head->hash || ! $checkpointSeen) {
                throw new \RuntimeException('Audit head or external checkpoint mismatch');
            }

            return ['sequence' => $sequence, 'hash' => $previous];
        });
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }
}
