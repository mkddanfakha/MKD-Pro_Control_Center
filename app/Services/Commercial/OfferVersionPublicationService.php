<?php

namespace App\Services\Commercial;

use App\Exceptions\Commercial\OfferVersionWorkflowException;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Services\AuditLogService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OfferVersionPublicationService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function publish(OfferVersion $version): OfferVersion
    {
        return DB::transaction(function () use ($version): OfferVersion {
            Offer::query()
                ->whereKey($version->offer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $version = OfferVersion::query()
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($version->status === OfferVersion::STATUS_ACTIVE) {
                throw new OfferVersionWorkflowException('Cette version est déjà publiée (active).');
            }

            if ($version->status === OfferVersion::STATUS_RETIRED) {
                throw new OfferVersionWorkflowException('Une version retirée ne peut pas être republiée.');
            }

            if ($version->status !== OfferVersion::STATUS_DRAFT) {
                throw new OfferVersionWorkflowException('Seule une version brouillon peut être publiée.');
            }

            $this->assertCommercialContentValid($version);
            $this->assertDatesValidForPublication($version);

            $otherActiveExists = OfferVersion::query()
                ->where('offer_id', $version->offer_id)
                ->where('status', OfferVersion::STATUS_ACTIVE)
                ->whereKeyNot($version->getKey())
                ->exists();

            if ($otherActiveExists) {
                throw new OfferVersionWorkflowException('Une autre version active existe déjà pour cette offre.');
            }

            $oldSnapshot = $this->auditSnapshot($version);

            $version->applyPublicationTransition();

            $version = $version->fresh();

            $this->auditLogService->record(
                action: 'catalog.offer_version.published',
                auditable: $version,
                oldValues: $oldSnapshot,
                newValues: $this->auditSnapshot($version),
            );

            return $version;
        });
    }

    public function retire(OfferVersion $version, ?CarbonInterface $retiredAt = null): OfferVersion
    {
        $retiredAt = $retiredAt !== null
            ? Carbon::instance($retiredAt)
            : Carbon::now();

        return DB::transaction(function () use ($version, $retiredAt): OfferVersion {
            Offer::query()
                ->whereKey($version->offer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $version = OfferVersion::query()
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($version->status === OfferVersion::STATUS_RETIRED) {
                throw new OfferVersionWorkflowException('Cette version est déjà retirée.');
            }

            if ($version->status === OfferVersion::STATUS_DRAFT) {
                throw new OfferVersionWorkflowException('Seule une version active peut être retirée.');
            }

            if ($version->status !== OfferVersion::STATUS_ACTIVE) {
                throw new OfferVersionWorkflowException('Seule une version active peut être retirée.');
            }

            $oldSnapshot = $this->auditSnapshot($version);

            $version->applyRetirementTransition($retiredAt);

            $version = $version->fresh();

            $this->auditLogService->record(
                action: 'catalog.offer_version.retired',
                auditable: $version,
                oldValues: $oldSnapshot,
                newValues: $this->auditSnapshot($version),
            );

            return $version;
        });
    }

    /**
     * @throws OfferVersionWorkflowException
     */
    private function assertCommercialContentValid(OfferVersion $version): void
    {
        if ($version->offer_id === null) {
            throw new OfferVersionWorkflowException('L\'offre associée est requise.');
        }

        if ($this->isBlankString($version->code)) {
            throw new OfferVersionWorkflowException('Le code de version est requis.');
        }

        if ($this->isBlankString($version->version)) {
            throw new OfferVersionWorkflowException('Le libellé de version est requis.');
        }

        if ($version->price === null || (int) $version->price < 0) {
            throw new OfferVersionWorkflowException('Le prix catalogue doit être supérieur ou égal à zéro.');
        }

        if ($this->isBlankString($version->currency) || strlen((string) $version->currency) !== 3) {
            throw new OfferVersionWorkflowException('La devise doit comporter exactement 3 caractères.');
        }

        if ($this->isBlankString($version->billing_cycle)) {
            throw new OfferVersionWorkflowException('Le cycle de facturation est requis.');
        }

        if ($version->effective_from === null) {
            throw new OfferVersionWorkflowException('La date de début d\'effet est requise.');
        }

        if ($this->isBlankString($version->description)) {
            throw new OfferVersionWorkflowException('La description commerciale est requise.');
        }

        $this->assertStructuredCommercialList($version->inclusions, 'inclusions');
        $this->assertStructuredCommercialList($version->limitations, 'limitations');
        $this->assertStructuredCommercialList($version->exclusions, 'exclusions');
    }

    /**
     * @throws OfferVersionWorkflowException
     */
    private function assertDatesValidForPublication(OfferVersion $version): void
    {
        $effectiveFrom = Carbon::parse($version->effective_from);
        $now = Carbon::now();

        if ($version->effective_until !== null) {
            $effectiveUntil = Carbon::parse($version->effective_until);

            if ($effectiveUntil->lt($effectiveFrom)) {
                throw new OfferVersionWorkflowException('La date de fin d\'effet ne peut pas être antérieure à la date de début.');
            }

            if ($effectiveUntil->lt($now)) {
                throw new OfferVersionWorkflowException('La date de fin d\'effet ne peut pas être antérieure à la publication.');
            }
        }

        if ($effectiveFrom->gt($now)) {
            throw new OfferVersionWorkflowException('Une version active ne peut pas avoir une date de début d\'effet dans le futur.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     *
     * @throws OfferVersionWorkflowException
     */
    private function assertStructuredCommercialList(?array $payload, string $label): void
    {
        if ($payload === null || ! isset($payload['items']) || ! is_array($payload['items']) || $payload['items'] === []) {
            throw new OfferVersionWorkflowException("Le bloc commercial « {$label} » est requis.");
        }
    }

    private function isBlankString(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(OfferVersion $version): array
    {
        return [
            'id' => $version->id,
            'offer_id' => $version->offer_id,
            'code' => $version->code,
            'version' => $version->version,
            'status' => $version->status,
            'price' => (int) $version->price,
            'currency' => $version->currency,
            'billing_cycle' => $version->billing_cycle,
            'effective_from' => $version->effective_from?->format('Y-m-d H:i:s'),
            'effective_until' => $version->effective_until?->format('Y-m-d H:i:s'),
        ];
    }
}
