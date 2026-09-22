<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $clients = Client::query()
            ->orderByDesc('id')
            ->paginate(15);

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Clients/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
        ]);

        $client = Client::create($validated);

        $this->auditLogService->record(
            'client.created',
            auditable: $client,
            newValues: $this->clientAuditSnapshot($client),
        );

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Client $client)
    {
        $client->load([
            'installations' => function ($query) {
                $query->orderByDesc('id');
            },
        ]);

        return Inertia::render('Clients/Show', [
            'client' => $client,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Client $client)
    {
        return Inertia::render('Clients/Edit', [
            'client' => $client,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
        ]);

        $oldValues = $this->clientAuditSnapshot($client);

        $client->update($validated);

        $this->auditLogService->record(
            'client.updated',
            auditable: $client,
            oldValues: $oldValues,
            newValues: $this->clientAuditSnapshot($client->fresh()),
        );

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Client modifié avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client)
    {
        $oldValues = $this->clientAuditSnapshot($client);

        $client->delete();

        $this->auditLogService->record(
            'client.deleted',
            oldValues: $oldValues,
        );

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client supprimé avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function clientAuditSnapshot(Client $client): array
    {
        return array_merge(
            ['id' => $client->id],
            $client->only([
                'company_name',
                'contact_name',
                'phone',
                'email',
                'address',
                'city',
                'country',
                'status',
                'notes',
            ]),
        );
    }
}
