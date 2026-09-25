# Modèle métier des abonnements par installation

Document de référence pour le **MKD-Pro Control Center**.  
Complète [`status-lifecycle.md`](status-lifecycle.md) (séparation `Installation.status` / `Subscription.status`).

Dernière mise à jour modèle crédit : **25/09/2026** (Tasks 89–100, doc Task 103).
Dernière inspection données : **24/09/2026** (Task 36).

---

## Vue d’ensemble

Modèle commercial cible (15 000 FCFA / mois, crédit multi-mois par paiement, consommation progressive, une installation par client) :

```text
Installation
    ↓
Subscription courante (conceptuelle)
    ↓
Payment (encaissement + crédit acheté)
    ↓
SubscriptionPaymentConsumption (mois de crédit consommé → période financée)
```

Une **installation** possède conceptuellement **une subscription courante** : une entité abonnement dont les **périodes** et le **statut commercial** évoluent dans le temps. L’avancement d’un mois **ne crée pas** une nouvelle ligne `subscriptions` ; il met à jour la subscription existante (`current_period_start` / `current_period_end`).

L’**historique financier** est porté par **`payments`**. L’**historique des mois réellement consommés** est porté par **`subscription_payment_consumptions`**. L’**historique des changements** (création, modification, lifecycle, consommation de crédit) est porté par **`audit_logs`**. Il ne faut **pas** créer une nouvelle subscription pour chaque mois payé ou consommé.

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

## Paiement, crédit acheté et consommation

### Rôle d’un `Payment`

Un paiement représente à la fois :

- un **encaissement financier** (`amount`, `currency`, `status`, `paid_at`, …) ;
- un **nombre de mois de crédit achetés** (`credit_months_purchased`) ;
- une **mensualité de référence figée** au moment du paiement (`monthly_unit_amount`, alignée sur le tarif de l’abonnement au moment du calcul serveur).

Ces champs de crédit sont **calculés côté serveur** (`SubscriptionService::calculatePaymentCreditFields`, appliqués via `PaymentController`) ; le navigateur **ne peut pas** les imposer (`monthly_unit_amount` et `credit_months_purchased` sont **interdits** en entrée HTTP).

Exemples (tarif abonnement 15 000 XOF) :

| Montant payé | Mois achetés |
|--------------|--------------|
| 15 000 XOF | 1 |
| 45 000 XOF | 3 |
| 90 000 XOF | 6 |
| 180 000 XOF | 12 |

Règles : `monthly_unit_amount > 0`, montant **divisible exactement** par la mensualité, **aucune fraction de mois**, devise **identique** à celle de la subscription.

### Source de vérité du crédit restant

Le crédit **restant** sur un paiement est :

```text
credit_months_remaining =
max(0, credit_months_purchased − COUNT(subscription_payment_consumptions pour ce payment_id))
```

La source de vérité du crédit **consommé** est donc la table **`subscription_payment_consumptions`**, et **non** le champ **`renewal_applied_at`**.

Le champ **`credit_exhausted_at`** sur `payments` est un **indicateur d’épuisement** (maintenance côté service après consommation), pas la source de vérité du calcul.

### Rôle de `renewal_applied_at` (legacy / compatibilité)

`renewal_applied_at` est un champ **historique** hérité d’un modèle où un paiement « renouvelait » l’abonnement **une seule fois**.

Dans le **modèle crédit actuel** :

- il **ne détermine pas** le crédit disponible ;
- il **ne remplace pas** le comptage des lignes `subscription_payment_consumptions` ;
- il peut encore servir à des **règles HTTP legacy** (ex. immutabilité de certains champs après marquage historique) ou au **backfill** de données anciennes (migration Task 89).

**Ne pas** interpréter `renewal_applied_at` comme « un paiement ne peut consommer qu’un mois » : un paiement multi-mois consomme **une consommation à la fois** jusqu’à épuisement du crédit.

### Consommation progressive (un mois à la fois)

Un paiement de plusieurs mois **ne déclenche pas** plusieurs avances de période immédiates à l’encaissement.

Exemple : paiement **180 000 XOF**, mensualité **15 000 XOF**, **12** mois achetés.

| Événement | Effet |
|-----------|--------|
| Encaissement | 12 mois de crédit **disponibles**, aucune consommation automatique |
| 1ʳᵉ consommation | 1 mois consommé, **11** mois restants, subscription avancée d’**une** période |
| 2ᵉ consommation | 1 mois de plus consommé, **10** mois restants, etc. |

Chaque consommation crée **exactement une** ligne `subscription_payment_consumptions` et appelle la logique centrale **`performCreditConsumption()`** (via `consumeCreditFromPayment()` ou `consumeNextCreditForSubscription()`).

### Table `subscription_payment_consumptions`

Chaque ligne enregistre **l’utilisation d’un mois de crédit** et la **période d’abonnement financée** par ce mois :

| Colonne | Rôle |
|---------|------|
| `payment_id` | Paiement dont le crédit est débité |
| `subscription_id` | Abonnement dont la période est avancée |
| `period_start` | Début de la période financée |
| `period_end` | Fin de la période financée |
| `consumed_at` | Horodatage métier de la consommation |

**Contrainte unique** : `(subscription_id, period_start, period_end)`.

Elle empêche que **deux consommations** financent **exactement la même période** pour le même abonnement (protection complémentaire aux verrous applicatifs).

### FIFO (consommation globale du prochain crédit)

`SubscriptionService::consumeNextCreditForSubscription()` sélectionne le **prochain** paiement éligible selon :

```text
ORDER BY paid_at ASC, id ASC
```

Parmi les paiements **réellement consommables** :

- `status = paid`, `paid_at` renseigné ;
- devise cohérente avec la subscription ;
- `monthly_unit_amount` et `credit_months_purchased` initialisés ;
- crédit restant **> 0** (via COUNT des consommations).

**Exclus** : `refunded`, `failed`, `pending`, paiement **entièrement consommé**, subscription **`terminated`**.

Un paiement **partiellement consommé** reste **prioritaire** (FIFO) jusqu’à épuisement de son crédit avant de passer au paiement suivant.

### Deux parcours de consommation (UI / HTTP)

Les deux parcours convergent vers **`performCreditConsumption()`** ; le frontend **ne calcule pas** le FIFO ni le crédit restant.

#### Parcours global (FIFO automatique)

- **Page** : `resources/js/Pages/Subscriptions/Show.vue`
- **Action utilisateur** : « Consommer le prochain crédit » (si `credit.available_months > 0`)
- **Route** : `POST /subscriptions/{subscription}/consume-credit` (`subscriptions.consume-credit`)
- **Service** : `consumeNextCreditForSubscription()` — choix du paiement par FIFO
- **Corps de requête** : vide (aucun `payment_id`, aucune quantité de mois)

#### Parcours ciblé par paiement (historique)

- **Page** : `resources/js/Pages/Subscriptions/Payments/Show.vue`
- **Action** : renouvellement / consommation **explicitement liée** au paiement affiché
- **Route** : `POST /payments/{payment}/renew-subscription`
- **Service** : `renewFromPayment()` → `consumeCreditFromPayment()` sur **ce** paiement

Il s’agit d’un parcours **ciblé** ; le parcours subscription Show délègue le choix du paiement au **FIFO**.

### Subscription `terminated`

Une subscription **`terminated`** :

- **ne peut plus** consommer de crédit (refus métier dans `SubscriptionService`) ;
- **conserve** l’historique des paiements et des consommations passées ;
- expose **`available_months = 0`** dans les props Inertia d’affichage (`summarizeSubscriptionCreditForDisplay`), même si des lignes de paiement affichent encore un crédit théorique non consommable.

### Paiement `refunded`

Un paiement **remboursé** :

- **reste** dans l’historique ;
- **conserve** les consommations déjà enregistrées (aucune suppression automatique) ;
- **ne peut plus** alimenter une **nouvelle** consommation ;
- **n’est pas** inclus dans `available_months` côté affichage.

### Concurrence

Les chemins de consommation s’exécutent dans une **transaction** avec verrouillage cohérent :

```text
Subscription (lockForUpdate)
    ↓
Payment (lockForUpdate)
```

L’ordre **Subscription → Payment** est respecté sur `consumeNextCreditForSubscription()` et `consumeCreditFromPayment()`.

La contrainte unique `(subscription_id, period_start, period_end)` constitue une **dernière barrière** contre une double consommation de la même période.

### Interface Subscription Show (état actuel)

`Subscriptions/Show.vue` affiche (props **`credit`** calculées serveur) :

- crédit disponible (`available_months`) ;
- nombre de paiements (`payment_count`) ;
- par paiement : montant, mois achetés, mois restants, mensualité de référence, date de paiement, nombre de consommations, indication remboursé ;

et propose **« Consommer le prochain crédit »** lorsqu’un crédit **consommable** existe. Le frontend **n’envoie pas** de `payment_id` et **ne recalcule pas** le crédit.

Référence code : `app/Services/SubscriptionService.php`, `app/Http/Controllers/SubscriptionController.php`, `app/Http/Controllers/PaymentController.php`.

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

## Historique : Payment, consommations et AuditLog

| Besoin | Support actuel |
|--------|----------------|
| Historique des **paiements** (montants, statuts, crédit acheté, indicateurs legacy) | Table **`payments`**, liée à `subscription_id` |
| Historique des **mois de crédit consommés** et périodes financées | Table **`subscription_payment_consumptions`** |
| Historique des **évolutions** subscription / paiement / consommation | **`audit_logs`** (ex. `subscription.created`, `subscription.updated`, `subscription.lifecycle_synced`, `subscription.credit_consumed`, `subscription.credit_consumption_failed`, `payment.renewal_applied`, `payment.renewal_failed`, …) |

Créer une nouvelle subscription par mois **n’est pas** le modèle retenu : les mois successifs sont reflétés par l’évolution de `current_period_*`, les lignes **`payments`** (crédit acheté) et les lignes **`subscription_payment_consumptions`** (crédit utilisé).

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
| Modèles | `app/Models/Subscription.php`, `app/Models/Payment.php`, `app/Models/SubscriptionPaymentConsumption.php`, `app/Models/Installation.php` |
| Périodes, lifecycle, crédit, consommation, FIFO | `app/Services/SubscriptionService.php` |
| Accès calculé | `app/Services/InstallationAccessService.php` |
| CRUD subscription, consommation FIFO HTTP, props crédit Show | `app/Http/Controllers/SubscriptionController.php` |
| Paiements, calcul crédit à la création, renouvellement ciblé HTTP | `app/Http/Controllers/PaymentController.php` |
| Sync lifecycle planifié | `app/Console/Commands/SyncSubscriptionLifecycle.php` |
| UI crédit + consommation globale | `resources/js/Pages/Subscriptions/Show.vue` |
| UI renouvellement ciblé paiement | `resources/js/Pages/Subscriptions/Payments/Show.vue` |

---

## Liens

- [`status-lifecycle.md`](status-lifecycle.md) — séparation installation / subscription / accès
- Task 36 — inspection « abonnement courant » et comparaison options A / B
