# Modèle métier des abonnements par installation

Document de référence pour le **MKD-Pro Control Center**.  
Complète [`status-lifecycle.md`](status-lifecycle.md) (séparation `Installation.status` / `Subscription.status`).

Dernière mise à jour modèle crédit : **25/09/2026** (Tasks 89–100, doc Task 103).
Dernière alignement doc / code (Installation ↔ Subscription ↔ Access) : **25/09/2026** (Task 147).
Dernier alignement doc / tarif (défaut, courant, crédit historique) : **25/09/2026** (Task 183).

---

## Vue d’ensemble

Modèle commercial cible (tarif mensuel **par abonnement** via `subscriptions.amount`, crédit multi-mois par paiement, consommation progressive, une installation par client). **15 000 FCFA / mois** n’est qu’un **exemple** courant et la **valeur par défaut produit** à la création — ce n’est **pas** un tarif obligatoire pour tous les abonnements :

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
| `amount` | **Tarif mensuel courant** de référence pour cet abonnement (source de vérité du tarif en vigueur) |
| `currency` | Devise du tarif (ex. `XOF`) |

Le montant **15 000 XOF** cité ailleurs dans ce document sert d’**illustration** ou reflète le **défaut produit actuel** ; chaque abonnement peut avoir un autre tarif valide (ex. **20 000 XOF**).

### Défaut à la création (`config/subscriptions.php`)

| Élément | Rôle |
|--------|------|
| `default_monthly_amount` | Montant mensuel **par défaut** si aucun `amount` explicite n’est fourni à la création (`SubscriptionController::store`) |
| Accès code | `config('subscriptions.default_monthly_amount')` |
| UI | Préremplissage des formulaires via la prop Inertia `defaultMonthlyAmount` (création abonnement et création paiement) |

Cette configuration :

- **n’est pas** le tarif universel du produit ;
- **ne modifie pas** les abonnements, paiements ou crédits **déjà en base** lorsqu’on change le fichier de config ;
- **ne reprice pas** l’historique.

Après création, le tarif réel de l’abonnement est toujours **`subscriptions.amount`** (y compris si une autre valeur a été saisie ou modifiée ensuite).

### Changement du tarif courant (`Subscription.amount`)

La mise à jour de `amount` sur une subscription existante modifie le **tarif courant** pour les **nouveaux** calculs de crédit serveur (création ou modification de paiement **sans consommation**).

Elle **ne** :

- recalcule **pas** automatiquement `current_period_start`, `current_period_end`, `grace_period_ends_at` ni le `status` du seul fait du changement de tarif (sauf champs explicitement envoyés dans la mise à jour) ;
- **repricet pas** les paiements historiques (`payments.amount` reste le montant encaissé enregistré) ;
- **repricet pas** le crédit historique (`monthly_unit_amount` et `credit_months_purchased` **figés** sur un paiement restent tels qu’au moment du calcul serveur) ;
- **altère pas** les consommations déjà enregistrées (`subscription_payment_consumptions`).

Un **ancien crédit** continue d’être consommé **en mois** (une consommation = un mois débité), indépendamment du nouveau tarif courant.

**Exemple :** abonnement à **15 000 XOF**, paiement **90 000 XOF** → `monthly_unit_amount = 15000`, `credit_months_purchased = 6`. Si le tarif courant passe à **20 000 XOF**, ces **6 mois** historiques **ne sont pas** recalculés à 20 000 XOF ; seuls les **nouveaux** paiements utiliseront **20 000** comme mensualité de calcul.

---

## Paiement, crédit acheté et consommation

### Rôle d’un `Payment`

Distinction des montants sur un paiement :

| Champ | Signification |
|-------|----------------|
| `amount` | Montant **financier** encaissé / enregistré pour ce paiement |
| `monthly_unit_amount` | Tarif mensuel **historique** retenu au **calcul serveur** du crédit (snapshot de `Subscription.amount` **au moment** de ce calcul) |
| `credit_months_purchased` | Nombre entier de **mois de crédit** achetés (`amount ÷ monthly_unit_amount` au calcul, division exacte) |

Les lignes **`subscription_payment_consumptions`** représentent les mois de crédit **effectivement consommés** (une ligne = un mois de période financée). Le crédit **restant** se déduit du nombre de mois achetés moins le nombre de consommations — pas d’un recalcul au tarif courant après coup.

Un paiement représente donc à la fois :

- un **encaissement financier** (`amount`, `currency`, `status`, `paid_at`, …) ;
- un **stock de mois** (`credit_months_purchased`) ;
- une **mensualité de référence figée** pour ce stock (`monthly_unit_amount`).

Ces champs de crédit sont **calculés côté serveur** (`SubscriptionService::calculatePaymentCreditFields`, appliqués via `PaymentController`) ; le navigateur **ne peut pas** les imposer (`monthly_unit_amount` et `credit_months_purchased` sont **interdits** en entrée HTTP). La preview éventuelle côté Vue est **indicative** ; seul le serveur fait foi.

Exemples **illustratifs** lorsque le **tarif courant** de l’abonnement est **15 000 XOF** au moment du calcul :

| Montant payé | Mois achetés |
|--------------|--------------|
| 15 000 XOF | 1 |
| 45 000 XOF | 3 |
| 90 000 XOF | 6 |
| 180 000 XOF | 12 |

Règles : `monthly_unit_amount > 0`, montant du paiement **divisible exactement** par le **tarif courant** `Subscription.amount` **au moment du calcul**, **aucune fraction de mois**, devise **identique** à celle de la subscription. Si le tarif courant change plus tard, les champs de crédit **déjà persistés** sur un paiement ne sont **pas** recalculés automatiquement (sauf modification HTTP autorisée **sans** consommation, qui relance le calcul serveur).

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

Exemple **illustratif** : paiement **180 000 XOF**, avec `monthly_unit_amount = 15 000 XOF` figé sur ce paiement, **12** mois achetés.

| Événement | Effet |
|-----------|--------|
| Encaissement | 12 mois de crédit **disponibles**, aucune consommation automatique |
| 1ʳᵉ consommation | 1 mois consommé, **11** mois restants, subscription avancée d’**une** période |
| 2ᵉ consommation | 1 mois de plus consommé, **10** mois restants, etc. |

Chaque consommation crée **exactement une** ligne `subscription_payment_consumptions` et appelle la logique centrale **`performCreditConsumption()`** (via `consumeCreditFromPayment()` ou `consumeNextCreditForSubscription()`).

**Séparation Installation / Subscription :** une consommation de crédit **avance** la période de la subscription concernée et, lorsque les règles de renouvellement sont satisfaites, remet le **`status`** de cette subscription à **`active`** (`advanceSubscriptionToPeriod`). Elle **ne modifie pas** `Installation.status` ni les horodatages ops de l’installation (`suspended_at`, `terminated_at`, `installed_at`, `last_seen_at` sur `installations`).

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

### Renouvellement : chemin moderne vs `renew()` legacy

**Chemin nominal (modèle crédit multi-mois)** :

- `consumeCreditFromPayment()`, `consumeNextCreditForSubscription()` et **`renewFromPayment()`** (HTTP : renouvellement / consommation **ciblée** sur un paiement) convergent vers **`performCreditConsumption()`** : **un mois** de crédit consommé, **une** ligne `subscription_payment_consumptions`, avance d’**une** période calendaire.
- `renewFromPayment()` **n’impose pas** que `Payment.amount` égale le tarif courant : l’éligibilité repose sur le **crédit restant** et les règles de statut, pas sur l’égalité montant paiement / `Subscription.amount`.

**Chemin legacy (`SubscriptionService::renew()`)** :

- Conservé pour **compatibilité** et certains tests ; **ne pas** le présenter comme le parcours nominal du modèle crédit actuel.
- Exige notamment que **`Payment.amount` égale le `Subscription.amount` courant**, que les devises correspondent, et que la période du paiement couvre la période courante de l’abonnement ; avance la période via **`advanceSubscriptionToPeriod`** **sans** le même modèle FIFO / multi-mois que la consommation progressive documentée ci-dessus.
- Après un **changement de tarif**, un paiement encaissé à l’**ancien** montant mensuel peut **échouer** ce chemin legacy alors que la **consommation de crédit** reste valable sur la base de `monthly_unit_amount` et `credit_months_purchased` **figés**.

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

## Schéma SQL et politique d’unicité (état actuel)

| Aspect | État actuel |
|--------|-------------|
| Relation Eloquent | `Installation` **hasMany** `Subscription` |
| Unicité « non terminée » | **Imposée** : colonne générée `non_terminated_installation_id` + index unique `subscriptions_non_terminated_installation_id_unique` (migration `2026_09_24_164500_add_non_terminated_uniqueness_to_subscriptions_table.php`) |
| Validation applicative | `SubscriptionController` (création + mise à jour) ; message « Cette installation possède déjà un abonnement non terminé. » |
| Historique `terminated` | **Plusieurs** subscriptions `terminated` par installation **autorisées** |

**Règle :** au **maximum une** subscription **non terminée** (`active`, `grace_period`, `suspended`) par installation ; **plusieurs** `terminated` possibles.

Tests : `SubscriptionUniquenessConstraintTest`, `SubscriptionStoreTest`, `SubscriptionUpdateTest`.

---

## Accès installation et sélection de la subscription

**`InstallationAccessService`** calcule l’accès à partir de la **subscription courante**, **pas** à partir de `Installation.status`.

`latestSubscription()` sélectionne la subscription **non terminée** la plus récente (`status IN (active, grace_period, suspended)`, tri `latest('id')`). Les subscriptions **`terminated`** ne sont **pas** la subscription courante.

| Subscription courante | Accès (`accessSummary`) |
|-----------------------|-------------------------|
| `active` | `accessible` — autorisé |
| `grace_period` | `accessible` — autorisé |
| `suspended` | `suspended` — non autorisé |
| Aucune non terminée (uniquement `terminated` ou aucune ligne) | `no_subscription` — non autorisé |

Voir [`status-lifecycle.md`](status-lifecycle.md) (§ Nuance `terminated` / `no_subscription`).

Tests : `InstallationShowAccessTest`, `Tests\Unit\InstallationAccessServiceTest`.

---

## Dashboard (rappel)

Statistiques **installations** : cartes `active`, `suspended`, `terminated` ; `inactive` filtrable sur `/installations?status=inactive` sans carte Dashboard.
Statistiques **abonnements** : `active`, `grace_period`, `suspended`, `terminated`.
Détail : [`status-lifecycle.md`](status-lifecycle.md) § Dashboard.

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
4. Une subscription **`suspended`** reste la **subscription courante** ; le **renouvellement nominal** passe par la **consommation de crédit** (`renewFromPayment` / FIFO). La méthode legacy **`renew()`** reste disponible pour compatibilité avec des règles plus strictes (voir § Renouvellement : chemin moderne vs `renew()` legacy).
5. Une subscription **`terminated`** ne peut **plus** être réactivée ni renouvelée (cohérent avec la décision **A**).
6. Lorsqu’un client **revient** après une subscription `terminated`, une **nouvelle** subscription **peut** être créée (nouveau cycle).
7. La subscription courante est la subscription **non `terminated`** (unique lorsque la règle est respectée), sélectionnée par `InstallationAccessService` parmi `active` / `grace_period` / `suspended`.

**Implémentation actuelle :** contrainte **base de données** (colonne générée + index unique) et **validation** dans `SubscriptionController` ; voir § Schéma SQL et politique d’unicité.

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

**Implémentation actuelle :** `SubscriptionController::store()` impose **`starts_at`**, crée la subscription en **`active`**, appelle **`SubscriptionService::createInitialPeriod()`** dans une transaction, puis audite `subscription.created`. Les mises à jour manuelles restent soumises aux règles de validation du contrôleur (périodes, unicité).

### Décisions encore ouvertes (modèle d’abonnement)

**Aucune** pour les règles **A**, **B** et **C** ci-dessus. Les questions **produit** sur le sens ops de `Installation.inactive` et l’accès réel côté MKD-Pro Gestion restent dans [`status-lifecycle.md`](status-lifecycle.md) (§ Décisions à prendre avant l’accès réel).

---

## Données historiques / rétro-correction

Les inspections antérieures (ex. Task 36, 24/09/2026) peuvent mentionner des subscriptions de test **sans période** ou **sans** `grace_period_ends_at`. La politique documentée ci-dessus décrit le **comportement attendu du code actuel** pour les **nouvelles** créations ; une **rétro-correction** des jeux de données existants reste une **tâche distincte** si nécessaire.

---

## Références code (lecture seule)

| Composant | Fichier |
|-----------|---------|
| Défaut tarif à la création | `config/subscriptions.php` (`default_monthly_amount`) |
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
