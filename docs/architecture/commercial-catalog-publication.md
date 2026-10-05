# Catalogue commercial — publication et retrait des OfferVersion

## Cycle de vie

```
draft → active → retired
```

- **draft** : contenu modifiable librement.
- **active** : contenu publié immuable ; une seule version active par `Offer`.
- **retired** : historique consultable, immuable.

Les transitions **draft → active** et **active → retired** passent par `App\Services\Commercial\OfferVersionPublicationService` (`publish`, `retire`).

## Règles clés

- Une publication **ne remplace pas** une version active existante : retirer la version courante, puis publier la suivante.
- **Publication** : `effective_from` ne peut pas être dans le futur ; `effective_until` (si renseigné) doit être ≥ `effective_from` et ≥ l’instant de publication.
- **Retrait** : seule une version `active` peut être retirée ; `effective_until` est renseigné à l’instant du retrait **uniquement** s’il était `NULL`.
- Concurrence : transaction + `lockForUpdate()` sur la ligne `offers` (et rechargement verrouillé de la version).

## Audit

- `catalog.offer_version.published`
- `catalog.offer_version.retired`

Via `AuditLogService` (même infrastructure que le reste du Control Center).

## CRUD administratif (Control Center)

Routes sous `/commercial` (authentification requise) :

- Produits : `commercial.products.*`
- Offres : `commercial.offers.*`
- Versions : `commercial.offer-versions.*` + actions `publish` / `retire`

Une version créée via le CRUD est toujours en **draft**. Le statut n’est pas modifiable librement : publication et retrait passent par `OfferVersionPublicationService`.

`OfferVersion.price` est affiché comme **prix catalogue** ; il ne remplace pas `Subscription.amount` ni `Payment.amount`.

Le catalogue commercial est indépendant de `Module` / `InstallationModule`.

## Souscription (Control Center)

Lors de la création d’un abonnement via l’admin :

1. sélection d’une `OfferVersion` **active** et applicable à la date du jour ;
2. `Subscription.amount` = tarif appliqué (prérempli avec le prix catalogue) ;
3. `OfferVersion.price` = prix catalogue de référence (inchangé) ;
4. création atomique de `Subscription` + `SubscriptionOfferSnapshot` ;
5. aucun `Payment` automatique.

Tarif personnalisé : si `Subscription.amount` ≠ prix catalogue, le snapshot conserve `catalogue_price` et `effective_price_at_subscription`, avec `negotiated_rate_reason` si renseigné.

Les abonnements historiques sans `offer_version_id` ne sont pas modifiés automatiquement.
