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

**Modèle métier cible :** une **subscription courante** (non `terminated`) par installation, avec plusieurs subscriptions **`terminated`** possibles en historique (voir décision **B** actée).

**Écart connu :** le schéma autorise encore plusieurs lignes non terminées ; une **future tâche** devra **imposer** la politique d’unicité documentée (validation, contrainte, sélection d’accès). **Cette documentation ne l’implémente pas** (cf. § Décisions actées — B).

---

## Accès installation et sélection de la subscription

Aujourd’hui, **`InstallationAccessService::latestSubscription()`** retourne la subscription avec le **`id` le plus élevé** pour l’installation (`max(id)`).

Cette implémentation est **provisoire** : elle n’encode pas encore la politique d’unicité (**décision B** — une seule subscription non `terminated` par installation).

Une **tâche ultérieure** devra aligner la sélection (et la création) avec cette règle, afin de **ne plus dépendre** du choix arbitraire **`max(id)`** pour déterminer la subscription courante. **Ne pas modifier le service dans le cadre de la seule documentation.**

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

**Décision C (documentation) — pas de rétro-correction :** la politique d’initialisation ci-dessous **ne corrige pas** les données existantes. En l’état actuel de la base de test :

- la subscription **#2** reste **`active` sans période** (`current_period_start` / `current_period_end` absents) ;
- la subscription **#3** reste en **`grace_period` sans `grace_period_ends_at`**.

Leur traitement fera l’objet d’**une tâche distincte**.

---

## Décisions produit

### Décisions actées

#### A. Après `terminated` — **décision prise** (option A retenue)

Une subscription passée à **`terminated`** est **définitivement terminée** :

- Elle **ne peut plus être renouvelée** ni **réactivée** (aligné avec le comportement actuel de `SubscriptionService::renew()`).
- Si le client **reprend ultérieurement** son abonnement, une **nouvelle** subscription est **créée** pour ce **nouveau cycle commercial** (nouvelle ligne `subscriptions`).
- L’**ancienne** subscription et ses **payments** associés **restent conservés** comme **historique** (pas de suppression implicite).
- Cette règle correspond à l’**option A** identifiée lors de l’inspection Task 36 (nouvelle subscription après terminaison, pas de réactivation de la ligne terminée).

#### B. Politique d’unicité — **décision prise**

Une installation peut avoir **plusieurs subscriptions historiques**, mais **une seule subscription non `terminated` à la fois**.

Règles :

1. Plusieurs subscriptions **`terminated`** pour la **même** installation sont **autorisées** (historique de cycles commerciaux clos).
2. Pour une installation donnée, il ne doit exister **qu’une seule** subscription parmi les statuts **`active`**, **`grace_period`** ou **`suspended`** (ensemble des statuts « non terminés »).
3. Une **nouvelle** subscription ne peut être **créée** que lorsqu’il **n’existe aucune** subscription non `terminated` pour cette installation (typiquement : après `terminated`, ou première souscription).
4. Une subscription **`suspended`** reste la **subscription courante** et peut être **renouvelée** selon les règles actuelles (`SubscriptionService::renew` / `renewFromPayment`).
5. Une subscription **`terminated`** ne peut **plus** être réactivée ni renouvelée (cohérent avec la décision **A**).
6. Lorsqu’un client **revient** après une subscription `terminated`, une **nouvelle** subscription **peut** être créée (nouveau cycle).
7. Cette règle vise notamment à **supprimer la dépendance métier** au choix arbitraire **`max(id)`** pour identifier la subscription courante : la courante est la subscription **non `terminated`** (unique lorsque la règle est respectée).

**Non imposé aujourd’hui (décision documentée uniquement) :** cette politique **n’est pas encore appliquée** par la **base de données**, le **modèle Eloquent**, le **`SubscriptionController`**, ni un **service** dédié — le CRUD et le schéma actuels permettent encore plusieurs lignes non terminées.

#### C. Initialisation à la création — **décision prise**

Règles métier pour une **nouvelle** subscription commerciale :

1. Une nouvelle subscription doit être créée avec une **période initiale exploitable** (lifecycle et renouvellement par paiement supposent des dates de période cohérentes).
2. **`starts_at`** représente le **véritable début commercial** de l’abonnement et **doit être renseigné** à la création.
3. La période initiale est calculée **automatiquement** à partir de **`starts_at`** via la logique centralisée **`SubscriptionService::createInitialPeriod()`** (calendrier mensuel : `calculateNextPeriod`).
4. **`current_period_start`** et **`current_period_end`** doivent être **initialisés ensemble**. Une **période partielle** (une seule des deux dates, ou saisie incohérente) **n’est pas** une création valide.
5. Une nouvelle subscription commerciale est créée avec le statut initial **`active`**.
6. **`grace_period`** et **`suspended`** sont des états issus du **cycle de vie** (ou de décisions ops ultérieures) ; ce ne sont **pas** les statuts normaux d’une **nouvelle** création.
7. Une subscription **`terminated`** ne doit **pas** être réactivée ; après terminaison, une **nouvelle** subscription est créée (décision **A**).
8. Lors de l’**implémentation**, la création devra être **atomique** : enregistrement de la subscription **et** initialisation de la période dans la **même transaction**.
9. L’audit **`subscription.created`** devra refléter l’**état initial finalisé** de la subscription, **avec** sa période initiale renseignée (après `createInitialPeriod()`, pas un enregistrement sans période).
10. La décision **B** s’applique : une nouvelle subscription ne peut être créée que s’il **n’existe aucune** subscription **non `terminated`** pour l’installation.

**Non imposé aujourd’hui (décision documentée uniquement) :** cette politique **n’est pas encore implémentée** techniquement — `SubscriptionController::store()` crée encore la ligne sans appeler `createInitialPeriod()`, les dates restent optionnelles en validation, et le statut initial peut encore être choisi librement dans le formulaire.

### Décisions encore ouvertes (modèle d’abonnement)

**Aucune.** Les décisions **A**, **B** et **C** concernant le modèle d’abonnement par installation sont **actées** dans ce document. Les écarts avec le code ou la base relèvent d’**implémentations futures**, pas de décisions produit ouvertes.

---

## Conséquences d’implémentation futures

Lorsqu’une tâche d’implémentation appliquera les décisions **B** et **C**, elle devra notamment prévoir :

- **Validation** de **`starts_at`** obligatoire à la création ;
- **Interdiction** des **périodes partielles** (`current_period_start` / `current_period_end` saisis séparément ou incohérents avec la règle C) ;
- **Statut initial** **`active`** imposé (ou forcé) pour une nouvelle subscription commerciale ;
- **Appel** à **`SubscriptionService::createInitialPeriod()`** après création, lorsque les dates de période ne sont pas déjà définies conformément à la règle ;
- **Transaction** englobant création + initialisation de période ;
- **Audit** `subscription.created` **après** finalisation (période incluse dans le snapshot) ;
- **Règle d’unicité B** : refus de création si une subscription non `terminated` existe déjà pour l’installation ;
- **Alignement** ultérieur de **`InstallationAccessService`** avec la subscription courante (non `terminated`) plutôt que `max(id)` seul.

Cette section **ne prescrit pas** l’ordre ni le périmètre exact d’une unique tâche code — elle liste les sujets techniques identifiés.

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
