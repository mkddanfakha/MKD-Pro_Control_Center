<?php

namespace App\Http\Controllers\Commercial;

use App\Exceptions\Commercial\ImmutableCommercialRecordException;
use App\Exceptions\Commercial\OfferVersionWorkflowException;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Services\AuditLogService;
use App\Services\Commercial\OfferVersionPublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OfferVersionController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly OfferVersionPublicationService $publicationService,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'offer_id' => ['nullable', 'integer', 'exists:offers,id'],
            'status' => ['nullable', Rule::in([
                OfferVersion::STATUS_DRAFT,
                OfferVersion::STATUS_ACTIVE,
                OfferVersion::STATUS_RETIRED,
            ])],
        ]);

        $query = OfferVersion::query()->with(['offer.product']);

        if (filled($validated['offer_id'] ?? null)) {
            $query->where('offer_id', $validated['offer_id']);
        }

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        $offerVersions = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return Inertia::render('Commercial/OfferVersions/Index', [
            'offerVersions' => $offerVersions,
            'offers' => Offer::query()->with('product')->orderBy('name')->get(['id', 'product_id', 'code', 'name']),
            'filters' => [
                'offer_id' => isset($validated['offer_id']) ? (int) $validated['offer_id'] : null,
                'status' => $validated['status'] ?? null,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Commercial/OfferVersions/Create', [
            'offers' => Offer::query()->with('product')->orderBy('name')->get(),
            'selectedOfferId' => $request->integer('offer_id') ?: null,
            'defaults' => [
                'currency' => 'XOF',
                'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
                'price' => 15000,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->filled('status') && $request->input('status') !== OfferVersion::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Une version commerciale ne peut être créée qu’en brouillon.',
            ]);
        }

        $validated = $this->validateDraftPayload($request);

        $offerVersion = OfferVersion::query()->create([
            ...$validated,
            'status' => OfferVersion::STATUS_DRAFT,
        ]);

        $this->auditLogService->record(
            'catalog.offer_version.created',
            auditable: $offerVersion,
            newValues: $this->auditSnapshot($offerVersion),
        );

        return redirect()
            ->route('commercial.offer-versions.show', $offerVersion)
            ->with('success', 'Version commerciale créée en brouillon.');
    }

    public function show(OfferVersion $offerVersion): Response
    {
        $offerVersion->load(['offer.product']);

        return Inertia::render('Commercial/OfferVersions/Show', [
            'offerVersion' => $this->serializeOfferVersion($offerVersion),
        ]);
    }

    public function edit(OfferVersion $offerVersion): RedirectResponse|Response
    {
        if ($offerVersion->status !== OfferVersion::STATUS_DRAFT) {
            return redirect()
                ->route('commercial.offer-versions.show', $offerVersion)
                ->with('error', 'Seule une version brouillon peut être modifiée.');
        }

        $offerVersion->load(['offer.product']);

        return Inertia::render('Commercial/OfferVersions/Edit', [
            'offerVersion' => $this->serializeOfferVersion($offerVersion),
            'offers' => Offer::query()->with('product')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, OfferVersion $offerVersion): RedirectResponse
    {
        if ($offerVersion->status !== OfferVersion::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Cette version publiée ou historique est immuable.',
            ]);
        }

        if ($request->has('status') && $request->input('status') !== OfferVersion::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Le statut ne peut pas être modifié directement. Utilisez la publication ou le retrait.',
            ]);
        }

        $validated = $this->validateDraftPayload($request, $offerVersion);

        $oldValues = $this->auditSnapshot($offerVersion);

        try {
            $offerVersion->update($validated);
        } catch (ImmutableCommercialRecordException $exception) {
            throw ValidationException::withMessages([
                'status' => $exception->getMessage(),
            ]);
        }

        $this->auditLogService->record(
            'catalog.offer_version.updated',
            auditable: $offerVersion,
            oldValues: $oldValues,
            newValues: $this->auditSnapshot($offerVersion->fresh()),
        );

        return redirect()
            ->route('commercial.offer-versions.show', $offerVersion)
            ->with('success', 'Version commerciale modifiée.');
    }

    public function publish(OfferVersion $offerVersion): RedirectResponse
    {
        try {
            $this->publicationService->publish($offerVersion);
        } catch (OfferVersionWorkflowException $exception) {
            return redirect()
                ->route('commercial.offer-versions.show', $offerVersion)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('commercial.offer-versions.show', $offerVersion)
            ->with('success', 'Version commerciale publiée.');
    }

    public function retire(OfferVersion $offerVersion): RedirectResponse
    {
        try {
            $this->publicationService->retire($offerVersion);
        } catch (OfferVersionWorkflowException $exception) {
            return redirect()
                ->route('commercial.offer-versions.show', $offerVersion)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('commercial.offer-versions.show', $offerVersion)
            ->with('success', 'Version commerciale retirée du catalogue.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDraftPayload(Request $request, ?OfferVersion $offerVersion = null): array
    {
        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('offer_versions', 'code')->ignore($offerVersion?->id),
            ],
            'version' => [
                'required',
                'string',
                'max:255',
                Rule::unique('offer_versions', 'version')
                    ->where(fn ($query) => $query->where('offer_id', $request->integer('offer_id')))
                    ->ignore($offerVersion?->id),
            ],
            'description' => ['required', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_cycle' => ['required', Rule::in([OfferVersion::BILLING_CYCLE_MONTHLY])],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'commercial_conditions' => ['nullable', 'string'],
            'inclusions' => ['required', 'array', 'min:1'],
            'inclusions.*' => ['required', 'string'],
            'limitations' => ['required', 'array', 'min:1'],
            'limitations.*' => ['required', 'string'],
            'exclusions' => ['required', 'array', 'min:1'],
            'exclusions.*' => ['required', 'string'],
        ]);

        return [
            'offer_id' => (int) $validated['offer_id'],
            'code' => $validated['code'],
            'version' => $validated['version'],
            'description' => $validated['description'],
            'price' => (int) $validated['price'],
            'currency' => strtoupper($validated['currency']),
            'billing_cycle' => $validated['billing_cycle'],
            'effective_from' => $validated['effective_from'],
            'effective_until' => $validated['effective_until'] ?? null,
            'commercial_conditions' => $validated['commercial_conditions'] ?? null,
            'inclusions' => ['items' => array_values($validated['inclusions'])],
            'limitations' => ['items' => array_values($validated['limitations'])],
            'exclusions' => ['items' => array_values($validated['exclusions'])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(OfferVersion $offerVersion): array
    {
        return [
            'id' => $offerVersion->id,
            'offer_id' => $offerVersion->offer_id,
            'code' => $offerVersion->code,
            'version' => $offerVersion->version,
            'status' => $offerVersion->status,
            'price' => (int) $offerVersion->price,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOfferVersion(OfferVersion $offerVersion): array
    {
        return [
            'id' => $offerVersion->id,
            'offer_id' => $offerVersion->offer_id,
            'code' => $offerVersion->code,
            'version' => $offerVersion->version,
            'description' => $offerVersion->description,
            'price' => (int) $offerVersion->price,
            'currency' => $offerVersion->currency,
            'billing_cycle' => $offerVersion->billing_cycle,
            'effective_from' => $offerVersion->effective_from?->format('Y-m-d H:i:s'),
            'effective_until' => $offerVersion->effective_until?->format('Y-m-d H:i:s'),
            'status' => $offerVersion->status,
            'commercial_conditions' => $offerVersion->commercial_conditions,
            'inclusions' => $offerVersion->inclusions['items'] ?? [],
            'limitations' => $offerVersion->limitations['items'] ?? [],
            'exclusions' => $offerVersion->exclusions['items'] ?? [],
            'offer' => $offerVersion->offer ? [
                'id' => $offerVersion->offer->id,
                'code' => $offerVersion->offer->code,
                'name' => $offerVersion->offer->name,
                'product' => $offerVersion->offer->product ? [
                    'id' => $offerVersion->offer->product->id,
                    'code' => $offerVersion->offer->product->code,
                    'name' => $offerVersion->offer->product->name,
                ] : null,
            ] : null,
            'created_at' => $offerVersion->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $offerVersion->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
