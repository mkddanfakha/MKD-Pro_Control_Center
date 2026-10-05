<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Product;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OfferController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'status' => ['nullable', Rule::in([Offer::STATUS_ACTIVE, Offer::STATUS_INACTIVE])],
        ]);

        $query = Offer::query()->with('product')->withCount('versions');

        if (filled($validated['product_id'] ?? null)) {
            $query->where('product_id', $validated['product_id']);
        }

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        $offers = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return Inertia::render('Commercial/Offers/Index', [
            'offers' => $offers,
            'products' => Product::query()->orderBy('name')->get(['id', 'code', 'name']),
            'filters' => [
                'product_id' => isset($validated['product_id']) ? (int) $validated['product_id'] : null,
                'status' => $validated['status'] ?? null,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Commercial/Offers/Create', [
            'products' => Product::query()->orderBy('name')->get(['id', 'code', 'name']),
            'selectedProductId' => $request->integer('product_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->validationRules());

        $offer = Offer::query()->create($validated);

        $this->auditLogService->record(
            'catalog.offer.created',
            auditable: $offer,
            newValues: $this->auditSnapshot($offer),
        );

        return redirect()
            ->route('commercial.offers.show', $offer)
            ->with('success', 'Offre créée avec succès.');
    }

    public function show(Offer $offer): Response
    {
        $offer->load([
            'product',
            'versions' => fn ($query) => $query->orderByDesc('id'),
        ]);

        $activeVersion = $offer->versions->firstWhere('status', OfferVersion::STATUS_ACTIVE);

        return Inertia::render('Commercial/Offers/Show', [
            'offer' => $offer,
            'activeVersion' => $activeVersion,
            'versionCounts' => [
                'draft' => $offer->versions->where('status', OfferVersion::STATUS_DRAFT)->count(),
                'active' => $offer->versions->where('status', OfferVersion::STATUS_ACTIVE)->count(),
                'retired' => $offer->versions->where('status', OfferVersion::STATUS_RETIRED)->count(),
            ],
        ]);
    }

    public function edit(Offer $offer): Response
    {
        $offer->load('product');

        return Inertia::render('Commercial/Offers/Edit', [
            'offer' => $offer,
            'products' => Product::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, Offer $offer): RedirectResponse
    {
        $validated = $request->validate($this->validationRules($offer));

        $oldValues = $this->auditSnapshot($offer);

        $offer->update($validated);

        $this->auditLogService->record(
            'catalog.offer.updated',
            auditable: $offer,
            oldValues: $oldValues,
            newValues: $this->auditSnapshot($offer->fresh()),
        );

        return redirect()
            ->route('commercial.offers.show', $offer)
            ->with('success', 'Offre modifiée avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(?Offer $offer = null): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('offers', 'code')->ignore($offer?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in([Offer::STATUS_ACTIVE, Offer::STATUS_INACTIVE])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'product_id' => $offer->product_id,
            'code' => $offer->code,
            'name' => $offer->name,
            'status' => $offer->status,
        ];
    }
}
