<?php

namespace App\Support\JobFreshness\Pilot;

/**
 * The offline transport of dry-run mode: it answers from recorded responses (a local file, in request order) or, with none given, with a synthetic "everything is open"
 * answer. It performs no I/O at all.
 */
final class RecordedPilotTransport implements PilotTransport
{
    /** @param list<array{http_status?: int, headers?: array<string, string>, body?: string, fail?: bool}> $recorded */
    public function __construct(private array $recorded = [], private int $next = 0) {}

    public function get(string $url, int $timeoutSeconds): PilotResponse
    {
        $entry = $this->recorded[$this->next++] ?? null;
        if ($entry === null) {
            $ids = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            foreach (explode(',', (string) ($query['ids'] ?? '')) as $id) {
                if ($id !== '') {
                    $ids[] = ['id' => (int) $id, 'status' => 'active'];
                }
            }

            return new PilotResponse(200, ['content-type' => 'application/json'], (string) json_encode($ids === [] ? new \stdClass : ['jobs' => $ids, 'success' => true]));
        }
        if (($entry['fail'] ?? false) === true) {
            throw new PilotTransportException('recorded connection failure');
        }

        return new PilotResponse((int) ($entry['http_status'] ?? 200), array_change_key_case((array) ($entry['headers'] ?? ['content-type' => 'application/json']), CASE_LOWER), (string) ($entry['body'] ?? '{}'));
    }
}
