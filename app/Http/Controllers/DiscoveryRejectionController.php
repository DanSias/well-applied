<?php

namespace App\Http\Controllers;

use App\Enums\DiscoveryRejectionReason;
use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryRejection;
use App\Support\JobDiscovery\DiscoveryProviderInventory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only list of candidates the discovery filter turned away, newest sighting first. Server-side search and
 * pagination: the list is bounded by the 30-day retention but can still run to hundreds of rows. Separate from the Jobs
 * inbox, which never includes these.
 */
class DiscoveryRejectionController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $reason = DiscoveryRejectionReason::tryFrom((string) $request->query('reason', ''));
        // A real discovery provider only; Manual never produces a rejection and anything unrecognized is no filter.
        $provider = JobDiscoverySource::tryFrom((string) $request->query('provider', ''));
        $provider = $provider === JobDiscoverySource::Manual ? null : $provider;

        $query = DiscoveryRejection::query()->active()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.mb_substr($search, 0, 100).'%';
                $query->where(fn ($inner) => $inner->where('title', 'like', $like)->orWhere('company', 'like', $like));
            })
            ->when($reason !== null, fn ($query) => $query->where('reason_code', $reason))
            ->when($provider !== null, fn ($query) => $query->where('source', $provider));

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        $rows = $query->orderByDesc('last_seen_at')->orderByDesc('id')->forPage($page, self::PER_PAGE)->get();

        return Inertia::render('discovery/rejections', [
            'rejections' => $rows->map(fn (DiscoveryRejection $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'company' => $r->company,
                'provider' => $r->source->value,
                'reason' => ['code' => $r->reason_code->value, 'label' => $r->reason_code->label()],
                'matched_term' => $r->matched_term,
                'matched_field' => $r->matched_field,
                'evidence' => $r->evidence_snippet,
                'location' => $r->location,
                'remote_status' => $r->remote_status,
                'source_url' => $r->source_url !== null && preg_match('~^https?://~i', $r->source_url) === 1 ? $r->source_url : null,
                'first_seen_at' => $r->first_seen_at->toIso8601String(),
                'last_seen_at' => $r->last_seen_at->toIso8601String(),
                'seen_count' => $r->seen_count,
            ])->all(),
            'filters' => ['q' => $search, 'reason' => $reason->value ?? 'all', 'provider' => $provider?->value],
            'providers' => array_map(fn (JobDiscoverySource $s) => ['id' => $s->value, 'label' => DiscoveryProviderInventory::label($s)], DiscoveryProviderInventory::sources()),
            'reasons' => array_map(fn (DiscoveryRejectionReason $r) => ['code' => $r->value, 'label' => $r->label()], DiscoveryRejectionReason::cases()),
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1,
                'to' => min($total, $page * self::PER_PAGE),
            ],
        ]);
    }
}
