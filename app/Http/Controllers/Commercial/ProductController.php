<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
        ]);

        $query = Product::query()->withCount('offers');

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        $products = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return Inertia::render('Commercial/Products/Index', [
            'products' => $products,
            'filters' => ['status' => $validated['status'] ?? null],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Commercial/Products/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->validationRules());

        $product = Product::query()->create($validated);

        $this->auditLogService->record(
            'catalog.product.created',
            auditable: $product,
            newValues: $this->auditSnapshot($product),
        );

        return redirect()
            ->route('commercial.products.show', $product)
            ->with('success', 'Produit créé avec succès.');
    }

    public function show(Product $product): Response
    {
        $product->load([
            'offers' => fn ($query) => $query
                ->withCount([
                    'versions',
                    'versions as active_versions_count' => fn ($q) => $q->where('status', 'active'),
                    'versions as draft_versions_count' => fn ($q) => $q->where('status', 'draft'),
                    'versions as retired_versions_count' => fn ($q) => $q->where('status', 'retired'),
                ])
                ->orderByDesc('id'),
        ]);

        return Inertia::render('Commercial/Products/Show', [
            'product' => $product,
        ]);
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('Commercial/Products/Edit', [
            'product' => $product,
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate($this->validationRules($product));

        $oldValues = $this->auditSnapshot($product);

        $product->update($validated);

        $this->auditLogService->record(
            'catalog.product.updated',
            auditable: $product,
            oldValues: $oldValues,
            newValues: $this->auditSnapshot($product->fresh()),
        );

        return redirect()
            ->route('commercial.products.show', $product)
            ->with('success', 'Produit modifié avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(?Product $product = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'code')->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Product $product): array
    {
        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'slug' => $product->slug,
            'status' => $product->status,
        ];
    }
}
