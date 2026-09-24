# Modèle métier des abonnements par installation

Document de référence pour le **MKD-Pro Control Center**.  
Complète [`status-lifecycle.md`](status-lifecycle.md) (séparation `Installation.status` / `Subscription.status`).

Dernière inspection données : **24/09/2026** (Task 36).

---

## Vue d’ensemble

Modèle commercial cible (15 000 FCFA / mois, renouvellement mensuel, une installation par client) :

```text
Installation
    ↓
Subscription courante (conceptuelle)
    ↓
Payments (historique financier)
```

Une **installation** possède conceptuellement **une subscription courante** : une entité abonnement dont les **périodes** et le **statut commercial** évoluent dans le temps. Le renouvellement **ne crée pas** une nouvelle ligne `subscriptions` ; il met à jour la subscription existante.

L’**historique financier** est porté par **`payments`**. L’**historique des changements** (création, modification, lifecycle, renouvellement) est porté par **`audit_logs`**. Il ne faut **pas** créer une nouvelle subscription pour chaque mois payé.

---

## Champs de la subscription courante

La subscription courante (ligne `subscriptions` retenue pour une installation) contient notamment :

| Champ | Rôle |
|-------|------|
| `status` | État commercial du cycle de vie (`active`, `grace_period`, `suspended`, `terminated`) |
| `starts_at` | Date de début du contrat / ancrage initial |
| `current_period_start` | Début de la période de facturation en cours |
| `current_period_end` | Fin de la période de facturation en cours |
| `grace_period_ends_at` | Fin de la période de grâce après expiration de la période courante |
| `suspended_at` | Horodatage de passage en suspension (cycle ou saisie) |
| `terminated_at` | Horodatage de terminaison définitive |

Montant et devise (`amount`, `currency`) décrivent le tarif de référence de l’abonnement (ex. 15 000 XOF).

---

## Renouvellement mensuel

Comportement **actuel** du code (`SubscriptionService`) :

```text
Payment (status = paid)
    → SubscriptionService::renewFromPayment()
    → renew() sur la même Subscription
    → current_period_start / current_period_end avancés (période mensuelle suivante)
    → status = active
    → grace_period_ends_at = null
    → suspended_at = null
    → payment.renewal_applied_at renseigné (idempotence : un paiement ne renouvelle qu’une fois)
```

Points importants :

- **Aucune** nouvelle subscription n’est créée lors d’un renouvellement.
- Un abonnement **`terminated`** **ne peut pas** être renouvelé (`renew()` lève une exception métier).
- Un paiement non `paid`, déjà utilisé (`renewal_applied_at`), ou dont la période ne correspond pas à la période courante de l’abonnement, est **refusé**.

Référence code : `app/Services/SubscriptionService.php` (`renew`, `renewFromPayment`), `app/Http/Controllers/PaymentController.php` (`renewSubscription`).

---

## Cycle de vie subscription

Transitions **automatiques** (commande `subscriptions:sync-lifecycle`, `SubscriptionService::syncLifecycle`) sur la **même** subscription :

```text
active
    → (fin de current_period_end dépassée)
grace_period
    → (fin de grace_period_ends_at dépassée)
suspended
```

État **`terminated`** :

- Traité comme **terminal** pour le renouvellement dans le comportement actuel de `SubscriptionService` (renouvellement impossible).
- **`syncLifecycle`** ne modifie **pas** une subscription déjà `terminated` (ni une subscription `suspended` : pas de transition automatique sortante documentée dans le service).

La **période de grâce** (`grace_period`) reste la **même** subscription ; seul le `status` (et les dates associées) change.

### Indépendance vis-à-vis de l’installation

```text
Installation.status ≠ Subscription.status
```

- Le cycle subscription **ne modifie jamais** `Installation.status`.
- **`InstallationAccessService`** calcule l’**accès** (autorisé / non autorisé) à partir du **statut de la subscription** retenue pour l’installation, **pas** à partir de `Installation.status`.
- Voir [`status-lifecycle.md`](status-lifecycle.md) pour le détail des statuts installation.

---

## Historique : Payment et AuditLog

| Besoin | Support actuel |
|--------|----------------|
| Historique des **paiements** (montants, statuts, périodes couvertes, renouvellement appliqué) | Table **`payments`**, liée à `subscription_id` |
| Historique des **évolutions** subscription / paiement | **`audit_logs`** (ex. `subscription.created`, `subscription.updated`, `subscription.lifecycle_synced`, `payment.renewal_applied`, …) |

Créer une nouvelle subscription par mois **n’est pas** le modèle retenu : les mois successifs sont reflétés par l’évolution de `current_period_*` et par les lignes **`payments`**.

---

## Schéma SQL vs modèle métier cible

| Aspect | État actuel |
|--------|-------------|
| Relation Eloquent | `Installation` **hasMany** `Subscription` |
| Contraintes DB | **Aucune** unicité « une subscription non terminée par installation » ; **aucune** interdiction de plusieurs `active` |
| Données (inspection 24/09/2026) | 3 installations, 2 subscriptions, **aucune** installation avec plusieurs subscriptions |

**Modèle métier cible :** une **subscription courante** par installation.

**Écart connu :** le schéma autorise plusieurs lignes ; une **future tâche** devra **empêcher** ou **gérer explicitement** la création d’une seconde subscription **non terminée** (validation, contrainte, ou règle de sélection documentée). **Cette tâche ne l’implémente pas.**

---

## Accès installation et sélection de la subscription

Aujourd’hui, **`InstallationAccessService::latestSubscription()`** retourne la subscription avec le **`id` le plus élevé** pour l’installation (`max(id)`).

Cette implémentation est **provisoire** : elle n’encode pas encore la règle métier « subscription courante » (ex. priorité au dernier enregistrement **non** `terminated`).

Une **tâche ultérieure** devra aligner la sélection avec la règle « subscription courante » une fois les décisions produit ci-dessous actées. **Ne pas modifier le service dans le cadre de la seule documentation.**

---

## Données observées le 24/09/2026

Inspection en **lecture seule** (environnement de test / dev) :

| Fait | Détail |
|------|--------|
| Installations | 3 |
| Subscriptions | 2 |
| Multi-subscriptions par installation | 0 |
| Subscription **#2** | `active`, **sans** `current_period_start` / `current_period_end` |
| Subscription **#3** | `grace_period`, **sans** `grace_period_ends_at` |

Ces lignes sont des **données de test** ; **aucune correction automatique** n’a été appliquée lors de l’inspection.

---

## Décisions produit

### Décisions actées

#### A. Après `terminated` — **décision prise** (option A retenue)

Une subscription passée à **`terminated`** est **définitivement terminée** :

- Elle **ne peut plus être renouvelée** ni **réactivée** (aligné avec le comportement actuel de `SubscriptionService::renew()`).
- Si le client **reprend ultérieurement** son abonnement, une **nouvelle** subscription est **créée** pour ce **nouveau cycle commercial** (nouvelle ligne `subscriptions`).
- L’**ancienne** subscription et ses **payments** associés **restent conservés** comme **historique** (pas de suppression implicite).
- Cette règle correspond à l’**option A** identifiée lors de l’inspection Task 36 (nouvelle subscription après terminaison, pas de réactivation de la ligne terminée).

*Note :* la création de la nouvelle subscription après `terminated` relève du processus admin / produit ; le code CRUD permet déjà plusieurs lignes par installation — les garde-fous d’unicité relèvent de la décision **B** (encore ouverte).

### Décisions encore ouvertes

Les points suivants **doivent encore être tranchés** par le propriétaire du projet.

#### B. Politique d’unicité

Faut-il imposer, par installation :

- **Une seule** subscription **non terminée** à la fois ;
- **Autre** règle (ex. une seule `active`, historique en `terminated` uniquement, etc.).

#### C. Création initiale

Lors de la création d’une subscription (UI / API admin), faut-il **automatiquement** initialiser la première période mensuelle via **`SubscriptionService::createInitialPeriod()`** (aujourd’hui disponible dans le service mais **non** appelé par `SubscriptionController::store`) ?

---

## Références code (lecture seule)

| Composant | Fichier |
|-----------|---------|
| Modèle | `app/Models/Subscription.php`, `app/Models/Payment.php`, `app/Models/Installation.php` |
| Périodes, lifecycle, renouvellement | `app/Services/SubscriptionService.php` |
| Accès calculé | `app/Services/InstallationAccessService.php` |
| CRUD subscription | `app/Http/Controllers/SubscriptionController.php` |
| Paiements et renouvellement HTTP | `app/Http/Controllers/PaymentController.php` |
| Sync lifecycle planifié | `app/Console/Commands/SyncSubscriptionLifecycle.php` |

---

## Liens

- [`status-lifecycle.md`](status-lifecycle.md) — séparation installation / subscription / accès
- Task 36 — inspection « abonnement courant » et comparaison options A / B
