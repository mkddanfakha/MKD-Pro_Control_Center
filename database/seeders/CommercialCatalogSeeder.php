<?php

namespace Database\Seeders;

use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Product;
use Illuminate\Database\Seeder;

class CommercialCatalogSeeder extends Seeder
{
    /**
     * Date d'effet catalogue pour la version 2026-01 (publication TASK 261, pas une date commerciale historique inventée).
     */
    public const GEST_BASE_VERSION_EFFECTIVE_FROM = '2026-01-01 00:00:00';

    public function run(): void
    {
        $product = Product::query()->firstOrCreate(
            ['code' => 'GEST'],
            [
                'name' => 'MKD-Pro Gestion',
                'slug' => 'gestion',
                'description' => null,
                'status' => Product::STATUS_ACTIVE,
            ],
        );

        $offer = Offer::query()->firstOrCreate(
            ['code' => 'MKD-GEST-BASE'],
            [
                'product_id' => $product->id,
                'name' => 'MKD-Pro Gestion — Forfait base',
                'slug' => null,
                'description' => null,
                'status' => Offer::STATUS_ACTIVE,
            ],
        );

        $content = self::gestBase202601CommercialContent();

        OfferVersion::query()->firstOrCreate(
            ['code' => 'MKD-GEST-BASE-2026-01'],
            [
                'offer_id' => $offer->id,
                'version' => '2026-01',
                'price' => 15000,
                'currency' => 'XOF',
                'billing_cycle' => OfferVersion::BILLING_CYCLE_MONTHLY,
                'effective_from' => self::GEST_BASE_VERSION_EFFECTIVE_FROM,
                'effective_until' => null,
                'status' => OfferVersion::STATUS_ACTIVE,
                'description' => $content['description'],
                'inclusions' => $content['inclusions'],
                'limitations' => $content['limitations'],
                'exclusions' => $content['exclusions'],
                'commercial_conditions' => $content['commercial_conditions'],
            ],
        );
    }

    /**
     * @return array{
     *     description: string,
     *     inclusions: array{items: list<string>},
     *     limitations: array{items: list<string>},
     *     exclusions: array{items: list<string>},
     *     commercial_conditions: string|null
     * }
     */
    public static function gestBase202601CommercialContent(): array
    {
        return [
            'description' => 'Forfait base MKD-Pro Gestion — périmètre commercial 15 000 FCFA / mois (TASK 255 / TASK 259).',
            'inclusions' => [
                'items' => [
                    'Produits et catégories',
                    'Stock et mouvements de stock',
                    'Stock minimum et alertes de péremption',
                    'Scanner / codes-barres dans les flux concernés',
                    'Ventes',
                    'Clients',
                    'Devis',
                    'Factures PDF',
                    'Dépenses',
                    'Fournisseurs',
                    'Bons de commande',
                    'Bons de livraison achats',
                    'Inventaires',
                    'Tableau de bord',
                    'Notifications',
                    'Branding entreprise',
                    'Utilisateurs et rôles',
                    'Permissions',
                    'Pièces jointes',
                    'Journal des activités',
                    'Outils administratifs',
                    'Sauvegardes et restauration',
                    'Authentification à deux facteurs (2FA)',
                ],
            ],
            'limitations' => [
                'items' => [
                    'Crédit client géré vente par vente',
                    'Acompte, reste dû et échéance sans journal avancé de recouvrement',
                    'Paiements partiels via modification de la vente',
                    'Wave et Orange Money comme modes de paiement manuels uniquement',
                    'Aucune liaison API opérateur',
                    'Paiement cash sans caisse d\'ouverture / clôture',
                    'Rapports avancés non inclus',
                    'Fondation multi-store sans gestion multi-sites complète',
                    'Notifications temps réel dépendantes de Pusher / configuration',
                    'Pas d\'import catalogue Excel',
                ],
            ],
            'exclusions' => [
                'items' => [
                    'Caisse (POS)',
                    'Dette fournisseur et paiements fournisseurs avancés',
                    'Crédit client avancé',
                    'Import catalogue Excel',
                    'Impression thermique POS',
                    'Livraison client dédiée',
                    'Reporting avancé',
                    'Lots',
                    'Variantes produit',
                    'API opérateurs (Wave / Orange Money)',
                    'Modules commerciaux intégrés au Control Center',
                    'Multitenant / licence embarquée dans Gestion',
                ],
            ],
            'commercial_conditions' => 'Abonnement mensuel. Tarif catalogue 15 000 XOF. Le montant contractuel de l\'abonnement (Subscription.amount) peut différer du tarif catalogue lorsque négocié explicitement.',
        ];
    }
}
