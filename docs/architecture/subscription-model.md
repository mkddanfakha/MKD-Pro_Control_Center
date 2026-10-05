# Modèle métier des abonnements par installation

Document de référence pour le **MKD-Pro Control Center**.  
Complète [`status-lifecycle.md`](status-lifecycle.md) (séparation `Installation.status` / `Subscription.status`).

Dernière mise à jour modèle crédit : **25/09/2026** (Tasks 89–100, doc Task 103).
Dernière alignement doc / code (Installation ↔ Subscription ↔ Access) : **25/09/2026** (Task 147).
Dernier alignement doc / tarif (défaut, courant, crédit historique) : **25/09/2026** (Task 183).
Cycle **paid ↔ refunded** (HTTP, crédit, tarif) : **28/09/2026** (Tasks 199–201).
Consommation de crédit depuis **`suspended`** (comportement implémenté, tests Task 213) : **28/09/2026**.

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

### Subscription `suspended` et consommation de crédit (comportement actuel)

Une subscription **`suspended`** **n’est pas** un état terminal (contrairement à **`terminated`**).

**Accès installation** (`InstallationAccessService`) :

- tant que le statut reste **`suspended`**, l’accès calculé est **`suspended`** (non autorisé) ;
- **`grace_period`**, en revanche, reste **`accessible`** tant que la subscription n’est pas suspendue.

**Consommation de crédit** (FIFO ou ciblée, comportement **implémenté aujourd’hui** — voir tests `SubscriptionCreditConsumptionTest`, `SubscriptionCreditConsumptionHttpTest`) :

- si un paiement **`paid`** possède encore du crédit **consommable** (règles habituelles : reste > 0, non remboursé, etc.), la consommation **est autorisée** même lorsque la subscription est **`suspended`** ;
- **`performCreditConsumption()`** avance **immédiatement** la période suivante (sans attendre l’échéance calendaire de la période courante) ;
- **`advanceSubscriptionToPeriod()`** remet alors la subscription à **`active`**, efface **`grace_period_ends_at`** et **`suspended_at`**, et **ne modifie pas** **`terminated_at`** ;
- le crédit du paiement est débité d’**un mois** (ligne **`subscription_payment_consumptions`**) ;
- une fois le statut repassé à **`active`**, l’accès installation redevient **`accessible`** via le même mécanisme que pour une subscription active.

**Contraste avec `terminated` :** seul **`terminated`** bloque explicitement la consommation dans `SubscriptionService` ; **`suspended`** ne possède **pas** de garde équivalente dans le moteur de crédit actuel.

### Paiement `refunded` (statut et crédit consommable)

Un paiement **`refunded`** est un encaissement **toujours présent** dans l’historique (`payments`), avec son **`amount`** et ses champs de crédit **persistés** (`monthly_unit_amount`, `credit_months_purchased`). Ce statut décrit la **situation financière déclarée** du paiement, **pas** la suppression du dossier.

Distinctions à conserver :

| Notion | Comportement actuel |
|--------|---------------------|
| **Statut financier** (`Payment.status`, ex. `paid`, `refunded`) | Modifiable via HTTP sous conditions (voir § Cycle paid ↔ refunded) ; tracé par **`payment.updated`** (pas d’événement `payment.refunded` dédié). |
| **Crédit consommable** | Déterminé par `SubscriptionService` (`paymentContributesToConsumableCredit`, FIFO, assertions de consommation) : **`refunded` n’est jamais consommable**, même si le calcul arithmétique « mois restants » est > 0. |
| **Crédit déjà consommé** | Lignes **`subscription_payment_consumptions`** : **jamais** supprimées ni recalculées automatiquement lors d’un passage à `refunded`. |
| **Historique du paiement** | Ligne `payments` conservée ; consommations et audits passés conservés. |
| **Tarif mensuel courant** | **`subscriptions.amount`** — sert au **recalcul serveur** du crédit lors des mises à jour HTTP **sans consommation**. |
| **Tarif mensuel historique du paiement** | **`monthly_unit_amount`** (snapshot au dernier calcul serveur accepté pour ce paiement) ; **figé** dès qu’une consommation existe (champs financiers verrouillés). |

Conséquences d’un **`refunded`** :

- **reste** dans l’historique ;
- **conserve** les consommations déjà enregistrées ;
- **ne peut plus** alimenter une **nouvelle** consommation ;
- **n’est pas** inclus dans `available_months` (agrégat consommable) ;
- l’UI Payment Show n’affiche **pas** le mois restant arithmétique comme crédit utilisable (— + mention « non consommable »).

**Non implémenté** (ne pas documenter comme existant) : remboursement automatique, annulation automatique des périodes d’abonnement, suppression du crédit ou des consommations au remboursement, conversion automatique d’un ancien crédit vers un nouveau tarif, remboursement partiel métier, restauration automatique d’une consommation.

---

## Cycle paid ↔ refunded

Transitions gérées par **`PaymentController::update`** (et création **`store`** pour un paiement créé directement en `refunded`). Aucune modification automatique de **`subscriptions`** lors de ces seuls changements de statut paiement.

Rappel : tant qu’**aucune** ligne `subscription_payment_consumptions` n’existe pour le paiement, le serveur **recalcule** `monthly_unit_amount` et `credit_months_purchased` à partir du **`amount`** soumis et du **`Subscription.amount` courant** (`resolveValidatedPaymentCreditFields` → `calculatePaymentCreditFields`). Un changement de statut **`paid` ↔ `refunded`** **n’évite pas** ce recalcul : ce n’est **pas** une simple bascule si le tarif courant ou le montant ne sont plus compatibles.

### A. `paid` → `refunded` sans consommation

**Autorisé** via HTTP si :

- **`consumptionCount === 0`** ;
- pas de blocage legacy **`renewal_applied_at`** sans consommation (dans ce cas le statut doit rester `paid`) ;
- **`paid_at`** fourni (même règle que pour `paid`).

**Conséquences** :

- le paiement **reste** en base ;
- **aucune** consommation n’est créée ni supprimée ;
- le crédit devient **non consommable** (`refunded` exclu du FIFO et de `available_months`) ;
- **aucune** période d’abonnement n’est annulée ni recalculée automatiquement ;
- `monthly_unit_amount` / `credit_months_purchased` sont **recalculés** comme pour toute update sans consommation (souvent **inchangés** si montant et tarif courant identiques) ;
- audit : **`payment.updated`** (`old_values` / `new_values` incluent `status`, champs crédit, etc.).

**Interdit** via HTTP si **≥ 1 consommation** : le statut doit **rester `paid`** (impossible de passer en `refunded` depuis l’application).

### B. `refunded` → `paid` sans consommation

**Autorisé** via HTTP sous les mêmes conditions de base (0 consommation, `paid_at` obligatoire).

**Conséquences** :

- le paiement redevient **éligible** au crédit consommable (FIFO / consommation ciblée) si crédit restant > 0 et subscription non terminée ;
- **`monthly_unit_amount`** et **`credit_months_purchased`** sont **recalculés** avec le **tarif courant** `Subscription.amount` et le **`amount`** du paiement ;
- le montant du paiement doit être un **multiple entier** du tarif courant, sinon **validation refusée** (le paiement **ne change pas**) ;
- **aucune** consommation ni avance de période **automatique** ;
- audit : **`payment.updated`**.

Cas d’usage produit : correction administrative d’un remboursement enregistré **par erreur** (sans supprimer le paiement).

### C. Exemple : changement de tarif entre `refunded` et retour `paid`

Scénario **documenté et couvert par les tests HTTP** (Task 200) :

1. Abonnement à **15 000 FCFA** (`subscriptions.amount`).
2. Paiement **90 000 FCFA**, statut **`paid`** → crédit calculé : **`monthly_unit_amount = 15 000`**, **`credit_months_purchased = 6`**.
3. Passage HTTP **`paid` → `refunded`** (0 consommation) → crédit **non consommable**, champs de crédit en général **inchangés** si montant et tarif stables.
4. Mise à jour de l’abonnement : **`Subscription.amount = 20 000`** (tarif courant).
5. Tentative HTTP **`refunded` → `paid`** avec le **même** paiement **90 000 FCFA** (montant inchangé).
6. **90 000** n’est **pas** divisible par **20 000** → **erreur de validation** (`amount`).
7. Le paiement **reste `refunded`** ; **`amount`**, **`monthly_unit_amount`**, **`credit_months_purchased`** **inchangés** ; **aucune** consommation créée.

Il **ne s’agit pas** d’une conversion automatique des **6 mois à 15 000** en mois à **20 000** : le retour à `paid` **exige** un recalcul compatible avec le **tarif courant**, ou une modification explicite du montant du paiement (toujours sans consommation).

**Important :** changer **`Subscription.amount`** **ne recalcule pas** automatiquement les paiements déjà en base ; seules les **mises à jour HTTP** de paiement **sans consommation** relancent le calcul de crédit.

### D. Cycle complet avec consommation (tarif stable)

Exemple **illustratif** (tarif abonnement **15 000**, paiement **90 000**, **0 consommation** jusqu’à la reprise) :

```text
paid (6 mois achetés)
  → refunded (0 consommation, crédit non consommable)
  → paid (6 mois toujours disponibles arithmétiquement)
  → consommation d’un mois (HTTP FIFO ou ciblée)
```

**Résultat attendu** :

- **1** ligne **`subscription_payment_consumptions`** ;
- **5** mois restants (`credit_months_purchased − COUNT(consumptions)`) ;
- **`monthly_unit_amount = 15 000`**, **`credit_months_purchased = 6`** (historique du paiement inchangé sur ce scénario) ;
- la période d’abonnement avancée **une fois** par la consommation (logique `performCreditConsumption()`).

### Paiement partiellement consommé et remboursement

| Canal | `paid` → `refunded` |
|-------|---------------------|
| **HTTP** | **Refusé** (statut verrouillé `paid`). |
| **Base directe** (hors garde HTTP) | Possible techniquement ; les **consommations restent** ; **nouvelle** consommation **refusée** par le service. |

Retour **`refunded` → `paid`** avec consommations existantes : via HTTP, seul **`paid`** est accepté comme statut si des consommations existent ; les champs financiers **ne sont pas recalculés** (`consumptionCount > 0`).

### Suppression et cycle de statut

La **suppression** (`PaymentController::destroy`) dépend **uniquement** de l’absence de consommations, **pas** du statut `paid` / `refunded`. Un paiement **`refunded` sans consommation** reste **supprimable** ; avec consommations, **interdit** (y compris après un cycle statutaire sans conso).

### Tests de régression (HTTP)

Comportement du cycle documenté ci-dessus aligné avec **`tests/Feature/PaymentCreditHttpTest.php`** (Tasks 200) : transitions `paid` ↔ `refunded`, cycle avec consommation, rejet après changement de tarif incompatible, audits `payment.updated` sur transitions réussies.

---

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

L’état **`suspended`** reste également la **même** ligne `subscriptions` (subscription courante non terminée). Une **consommation de crédit** depuis **`suspended`** peut, dans l’implémentation actuelle, **réactiver** la subscription (`active`) et **lever** les horodatages de grâce/suspension — voir § Subscription `suspended` et consommation de crédit.

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
| Historique des **évolutions** subscription / paiement / consommation | **`audit_logs`** (ex. `subscription.created`, `subscription.updated`, `subscription.lifecycle_synced`, `subscription.credit_consumed`, `subscription.credit_consumption_failed`, `payment.created`, **`payment.updated`** — y compris changements `paid` ↔ `refunded`, `payment.deleted`, `payment.renewal_applied`, `payment.renewal_failed`, …) |

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

### Interface Subscription Show (état actuel)

`Subscriptions/Show.vue` affiche (props **`credit`** calculées serveur) :

- crédit disponible (`available_months`) ;
- nombre de paiements (`payment_count`) ;
- par paiement : montant, mois achetés, mois restants, mensualité de référence, date de paiement, nombre de consommations, indication remboursé ;

et propose **« Consommer le prochain crédit »** lorsqu’un crédit **consommable** existe. Le frontend **n’envoie pas** de `payment_id` et **ne recalcule pas** le crédit.

Référence code : `app/Services/SubscriptionService.php`, `app/Http/Controllers/SubscriptionController.php`, `app/Http/Controllers/PaymentController.php`.

---

## Politique de renouvellement de période (Task 271)

### Trois notions distinctes

| Notion | Support | Rôle |
|--------|---------|------|
| **Période couverte** | `current_period_start`, `current_period_end` | Fenêtre calendaire **actuellement** portée par la subscription |
| **Crédit disponible** | `credit_months_purchased` − COUNT(`subscription_payment_consumptions`) | Mois **payés** mais pas encore **appliqués** à une période |
| **État commercial** | `status`, `grace_period_ends_at`, `suspended_at`, … | Cycle de vie **indépendant** du stock de crédit |

**Crédit disponible ≠ période déjà prolongée.** Tant qu’aucune ligne `subscription_payment_consumptions` n’existe pour un mois donné, la période **n’est pas** considérée comme financée pour ce mois, même si des paiements `paid` possèdent du crédit restant.

### Décision fonctionnelle implémentée aujourd’hui

Le code actuel correspond à **l’option A** pour **l’extension de période** :

- **Aucune** prolongation automatique de `current_period_*` lorsque `current_period_end` est dépassée, **même** si du crédit Payment est disponible.
- L’administrateur (ou un futur job **non présent aujourd’hui**) doit déclencher explicitement **`performCreditConsumption()`** via :
  - `POST /subscriptions/{subscription}/consume-credit` (FIFO), ou
  - `POST /payments/{payment}/renew-subscription` (ciblé).

**Option B** (consommation automatique à l’échéance de période) **n’est pas implémentée** : ni dans `SubscriptionService::syncLifecycle()`, ni dans `subscriptions:sync-lifecycle`, ni ailleurs dans le dépôt au moment du Task 271.

**Option C partielle (lifecycle automatique sans renouvellement)** : la commande planifiée **`subscriptions:sync-lifecycle`** ne fait **que** des transitions de **statut** sur les abonnements `active` et `grace_period` :

```text
active + current_period_end dépassée  →  grace_period (+ grace_period_ends_at si absent)
grace_period + grace_period_ends_at dépassée  →  suspended (+ suspended_at si absent)
```

Elle **ne** consomme **pas** de crédit, **ne** crée **pas** de Payment, **ne** modifie **pas** `current_period_start` / `current_period_end` pour « rattraper » une période expirée.

### Scénarios cartographiés (comportement réel)

| Situation | Période | Crédit | Effet sans action manuelle |
|-----------|---------|--------|----------------------------|
| `active`, période future | Valide | quelconque | Inchangé (sync ne touche pas) |
| `active`, période expirée | Expirée | oui | `active` → `grace_period` ; période **inchangée** ; crédit **intact** |
| `active`, période expirée | Expirée | non | Idem → `grace_period` puis éventuellement `suspended` après grâce |
| `grace_period`, grâce valide | Expirée | oui | Statut grâce ; accès date-aware selon `InstallationAccessService` |
| `grace_period`, grâce expirée | Expirée | oui | → `suspended` ; crédit **toujours** intact tant qu’aucune consommation |
| `suspended` | Expirée | oui | Sync **ignore** ; consommation **manuelle** possible → `active` + avance d’**un** mois |
| `terminated` | — | oui | Sync **ignore** ; consommation **refusée** |

### Automatisation future

Toute **consommation automatique** de crédit à l’échéance devra réutiliser **`performCreditConsumption()`** (même audits, mêmes verrous, même idempotence) et fera l’objet d’un **chantier séparé** avec règles produit explicites. Le Task 271 **ne l’ajoute pas**.

---

## Préparation du renouvellement automatique par crédit — Task 272

### État : **non activé en production**

| Élément | Rôle |
|---------|------|
| `config('subscriptions.automatic_credit_renewal.enabled')` | **`false` par défaut** (`SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED`) |
| `SubscriptionAutomaticCreditRenewalService` | Orchestrateur **préparatoire** ; **n’est pas** appelé par `subscriptions:sync-lifecycle` |
| Moteur réel | **`SubscriptionService::consumeNextCreditForSubscription()`** → **`performCreditConsumption()`** (FIFO inchangé) |

Tant que `enabled` est `false`, **`attemptAutomaticRenewal()`** retourne `outcome = disabled` **sans** consommer de crédit. Le scheduler lifecycle continue **exactement** comme au Task 271.

### Éligibilité (`assessEligibility`)

Conditions **techniques** prévues pour une future activation (évaluation seule, sans effet si désactivé) :

1. Subscription **non** `terminated` ;
2. `current_period_end` renseigné ;
3. **`now > current_period_end`** (échéance de la période couverte dépassée — compatible avec l’accès date-aware) ;
4. `summarizeSubscriptionCreditForDisplay().available_months > 0` (crédit **consommable**, FIFO futur).

**Ne pas** consommer uniquement parce qu’un Payment `paid` existe tant que la période n’est pas expirée.

### FIFO et un mois par tentative

Chaque **`attemptAutomaticRenewal()`** réussi ne doit consommer **qu’un** mois via le FIFO existant (`paid_at ASC`, `id ASC`). Un paiement de 45 000 FCFA / 15 000 FCFA = **3** consommations **distinctes** sur **3** échéances distinctes — jamais +3 mois en une opération.

### Idempotence et concurrence

Réutilisation des **transactions**, **`lockForUpdate`**, contrainte unique `(subscription_id, period_start, period_end)` et contrôle du crédit restant. Deux tentatives sur la **même** échéance : la seconde voit en général `period_not_expired` après la première consommation. Scénarios concurrents : mêmes garanties que `PaymentConcurrencyTest`.

### Audits (lors d’une future activation)

Événement existant **`subscription.credit_consumed`**, avec contexte JSON **`renewal_trigger: automatic_credit_renewal`** dans `old_values` / `new_values` (pas de nouvelle table). Consommation manuelle FIFO HTTP conserve l’audit actuel **sans** ce trigger ; renouvellement ciblé conserve **`payment.renewal_applied`**.

### Interdictions inchangées

Pas de création de **Payment**, pas de Wave/Orange Money, pas de recalcul de crédit historique, pas de réactivation **`terminated`**.

---

## Politique métier — renouvellement automatique par crédit (Task 273)

**Le renouvellement automatique reste désactivé tant que `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=false`.**

### Deux politiques distinctes

| Politique | Responsable | Effet |
|-----------|-------------|--------|
| **Lifecycle** | `subscriptions:sync-lifecycle` | `active` → `grace_period` → `suspended` ; **ne consomme pas** de crédit ; **ne modifie pas** `current_period_*` |
| **Renouvellement par crédit** | `SubscriptionAutomaticCreditRenewalService` (+ commande **`subscriptions:renew-with-credit`**) | Consomme **un mois déjà payé** via `performCreditConsumption()` ; **une** consommation = **une** période |

Le scheduler lifecycle **n’appelle pas** le renouvellement automatique. La commande dédiée existe mais **n’est pas planifiée** dans `routes/console.php` tant que la fonctionnalité n’est pas activée volontairement.

### Cas A — période encore valide

Si `now <= current_period_end` : **aucune** consommation (`period_not_expired`). À l’instant exact de `current_period_end`, la période est encore valide (aligné `InstallationAccessService`).

### Cas B — période expirée + crédit

Si `now > current_period_end`, subscription **non** `terminated`, crédit consommable > 0 : **une** consommation FIFO autorisée **par appel** (`consumeNextCreditForSubscription`).

### Cas C — période expirée sans crédit

Aucune consommation automatique. Le lifecycle continue (`grace_period`, puis `suspended`).

### Cas D — `terminated`

Toujours interdit (`subscription_terminated`). Aucune consommation ni réactivation.

### Cas E — `grace_period` (décision Task 273)

**Option 1 retenue** : dès que `now > current_period_end` et qu’il reste du crédit, le renouvellement automatique **peut** consommer un mois, **y compris** en `grace_period`. Effet identique à la consommation manuelle : `active`, `grace_period_ends_at` effacé, période avancée d’**un** mois via le moteur existant.

### Cas F — `suspended` (décision Task 273)

Consommation automatique **autorisée** lorsque les critères d’échéance et de crédit sont remplis (**parité** avec consommation manuelle : `performCreditConsumption()` remet `active` et efface `suspended_at`). Ce n’est **pas** une réactivation par le scheduler lifecycle (qui ignore les `suspended`).

### Retard / rattrapage

Si la commande s’exécute longtemps après l’échéance, **un seul** mois calendaire est consommé par exécution et par subscription (`calculateNextSubscriptionPeriod()` depuis `current_period_end`). Pas de rattrapage multi-mois en une passe.

### FIFO, idempotence, concurrence, audit

Inchangés (Task 272) : FIFO moteur ; idempotence via verrous + contrainte unique ; audit `subscription.credit_consumed` + `renewal_trigger: automatic_credit_renewal`.

### Activation

`config/subscriptions.php` : `automatic_credit_renewal.enabled` = **`false`**. Brancher éventuellement `subscriptions:renew-with-credit` au scheduler **uniquement** après validation produit et `enabled=true` — hors périmètre Task 273.

---

## Exploitation CLI — renouvellement par crédit (Task 274)

### Commande

`php artisan subscriptions:renew-with-credit` — orchestrateur CLI uniquement ; délègue à `SubscriptionAutomaticCreditRenewalService` (aucune logique FIFO/crédit dans la commande).

| État config | Comportement |
|-------------|--------------|
| `enabled=false` (défaut) | Sortie immédiate, code **0**, **aucune** consommation ni modification |
| `enabled=true` (tests / activation volontaire) | Parcourt `querySubscriptionsForRenewalEvaluation()` puis `attemptAutomaticRenewal()` — **1 mois max** par abonnement et par exécution |

### Périmètre parcouru

Abonnements à période commerciale expirée (`current_period_end` &lt; now), non `terminated`, statuts autorisés par la politique Task 273. L’éligibilité finale (crédit, etc.) est **`assessEligibility()`** ; absence de crédit ou période encore valide = **ignoré**, pas une erreur technique.

### Sortie et erreurs

Résumé : évalués / renouvelés / ignorés / refusés / erreurs techniques. Une exception non gérée sur un abonnement est journalisée ; le traitement continue ; code de sortie **≠ 0** si au moins une erreur technique.

### Planification future (non activée)

1. Valider manuellement : `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=true` puis `subscriptions:renew-with-credit`.
2. Décider séparément d’un cron (ex. daily) — **ne pas** fusionner avec `subscriptions:sync-lifecycle`.
3. Le lifecycle reste responsable des **statuts** ; cette commande des **mois prépayés**.

**Le renouvellement automatique reste désactivé tant que `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=false`.**

---

## Observabilité opérationnelle — renouvellement par crédit (Task 276)

### Résumé CLI (`subscriptions:renew-with-credit`)

| Situation | Code sortie | Résumé |
|-----------|-------------|--------|
| `enabled=false` | **0** | Fonctionnalité désactivée — aucun abonnement évalué |
| Exécution normale | **0** | Évalués, renouvelés, ignorés (non éligibles), refusés (moteur), erreurs techniques, **durée ms** |
| ≥1 erreur technique | **≠ 0** | Idem + journalisation |

Les **non éligibles** affichent un détail par motif (`no_consumable_credit`, etc.). Ce ne sont **pas** des erreurs techniques.

### Non-éligibilité vs erreur

- **Non-éligible** : pas de crédit, moteur refusant après éligibilité (`rejected`), ou hors périmètre de requête (période encore valide, `terminated` non parcouru).
- **Erreur technique** : exception non gérée — log `error` avec `subscription_id` et `exception`, traitement des autres abonnements poursuivi.

### Audits et logs (pas d’audit d’exécution global)

- **Consommation réussie** : `subscription.credit_consumed` + `renewal_trigger: automatic_credit_renewal` (inchangé).
- **Pas** d’événement `subscription.automatic_renewal_run` : le résumé CLI + log `info` structuré (`subscriptions:renew-with-credit — exécution terminée`) suffisent sans table supplémentaire.

### Traçabilité

`Payment` → `SubscriptionPaymentConsumption` (période, `consumed_at`) → audit `subscription.credit_consumed` (trigger automatique). Aucun Payment créé ; `Subscription.amount` / `offer_version_id` / tarif catalogue **non** recalculés.

---

## Mode dry-run — renouvellement par crédit (Task 277)

### Syntaxe

```bash
php artisan subscriptions:renew-with-credit --dry-run   # simulation
php artisan subscriptions:renew-with-credit           # exécution réelle (si enabled)
```

### Interaction avec `enabled`

| Config | Option | Effet |
|--------|--------|--------|
| `false` | *(aucune)* | Sortie immédiate, aucune consommation |
| `false` | `--dry-run` | Toujours **désactivé** — message dry-run demandé, **aucune simulation** (le dry-run n’est pas une activation) |
| `true` | `--dry-run` | Parcours complet **lecture seule** : `assessEligibility()` + `previewNextFifoCreditConsumption()` — **aucune** écriture |
| `true` | *(réel)* | Comportement Task 274–276 inchangé |

### Non-mutation

Le dry-run **n’appelle pas** `performCreditConsumption()` / `consumeNextCreditForSubscription()`. Pas de rollback comme mécanisme principal. Aucun `subscription.credit_consumed`.

### Utilisation avant activation production

Après validation : `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=true` en préproduction, puis `--dry-run` pour observer Payment FIFO simulé, période suivante et motifs d’ignorance — sans modifier les données. Le scheduler **ne** passe **pas** `--dry-run` (exécution manuelle de validation uniquement).

---

## Validation préproduction — activation contrôlée (Task 278)

Activation **`SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=true`** uniquement sur environnement **non production** et base **isolée**. Le dépôt conserve **`false`**.

Procédure détaillée : `docs/operations/automatic-credit-renewal-preprod-validation.md`.

Suite de référence technique : `SubscriptionAutomaticCreditRenewalPreprodValidationTest` (scénarios A–G, dry-run puis run réel, idempotence, traçabilité, scheduler).

**Ne pas** consommer du crédit sur la production client réelle dans le cadre de cette tâche.

---

## Garde-fous avant activation production (Task 279)

- **Désactivé par défaut** : `config('subscriptions.automatic_credit_renewal.enabled')` ← `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED` (dépôt = `false`).
- **Diagnostic lecture seule** : `php artisan subscriptions:automatic-renewal-status` (environnement, timezone, ENABLED/DISABLED, scheduler, dry-run).
- **Dry-run** : `subscriptions:renew-with-credit --dry-run` (simulation non mutative).
- **Scheduler** : appelle `subscriptions:renew-with-credit` (sans `--dry-run`) ; consommation toujours via `consumeNextCreditForSubscription()` → `performCreditConsumption()`.
- **Production + activé** : avertissement CLI explicite sur `renew-with-credit` (n’empêche pas une activation volontaire).
- **Rollback** : remettre `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=false` — stoppe les futures consommations auto, **sans** annuler l’historique déjà consommé.

Procédure détaillée : `docs/operations/automatic-credit-renewal-preprod-validation.md`.

---

## Audit read-only — renouvellement par crédit (Task 280)

Quatre mécanismes **séparés** :

| Mécanisme | Commande | Effet |
|-----------|----------|--------|
| Configuration | `subscriptions:automatic-renewal-status` | Diagnostic ENABLED/DISABLED |
| Audit | `subscriptions:automatic-renewal-audit` | Lecture seule, éligibilité via `assessEligibility()` |
| Simulation | `subscriptions:renew-with-credit --dry-run` | Aperçu FIFO + période simulée, sans écriture |
| Mutation | `subscriptions:renew-with-credit` | Consommation réelle (si enabled) |

L’audit ne remplace pas le dry-run et ne consomme jamais de crédit.

---

---

## Lifecycle, renouvellement automatique et rappels (Task 281)

Responsabilités **distinctes** — ne pas les fusionner :

| Domaine | Rôle | Effet sur les données |
|---------|------|------------------------|
| **Subscription lifecycle** | `subscriptions:sync-lifecycle` (scheduler) | Modifie le **statut** (`active` → `grace_period` → `suspended`, etc.) |
| **Automatic credit renewal** | `subscriptions:renew-with-credit` | **Consomme** un mois de crédit (`SubscriptionPaymentConsumption`), avance la période |
| **Reminder detection** | `subscriptions:reminder-audit` | **Observe** `current_period_end` et les seuils configurés — **aucune** mutation, **aucune** notification |

Le renouvellement automatique répond à « la période est expirée et du crédit est disponible ». La détection de rappel répond à « la fin de période approche selon un seuil (7/3/1/0 jours) » pour un abonnement **actif** en période courante. Configurations indépendantes : `automatic_credit_renewal` et `subscription_reminders`.

---

## SubscriptionReminder — persistance des rappels (Task 282)

```text
Subscription
    ↓
SubscriptionReminder (detected / sent / failed)
    ↓
future notification channel (non implémenté)
```

- `SubscriptionReminder` **n’est pas** un `Payment` ni une `SubscriptionPaymentConsumption`.
- N’**avance pas** la période commerciale et ne modifie pas le lifecycle.
- Idempotence garantie par contrainte UNIQUE sur la clé logique (seuil + `scheduled_for`).

---

## NotificationData — composition des rappels (Task 283)

```text
Subscription
    ↓
SubscriptionReminder
    ↓
SubscriptionReminderNotificationData (titre + corps + destinataire logique)
    ↓
Future Notification Channel (non implémenté)
```

- `SubscriptionReminderNotificationData` est un **objet de composition** : ce n’est **pas** une notification envoyée, **pas** un `Payment`, **pas** un audit.
- Destinataire actuel : `central_admin` (abstrait — pas de user client final).
- Montant affiché : `Subscription.amount` (tarif effectif), pas `OfferVersion.price` ni `Payment.amount`.
- Commande : `subscriptions:reminder-preview {id}` — preview uniquement.

---

## Sender et canal de notification (Task 284)

```text
SubscriptionReminder
    ↓
SubscriptionReminderNotificationComposer → NotificationData
    ↓
SubscriptionReminderNotificationSender
    ↓
SubscriptionReminderNotificationChannel (contrat)
    ↓
PreparedSubscriptionReminderChannel (défaut : prepared, sans envoi externe)
EmailSubscriptionReminderChannel (Task 286 — Mail Laravel, `SUBSCRIPTION_REMINDER_ADMIN_EMAIL`)
    ↓
(futurs canaux SMS / push — non implémentés)
```

| Composant | Rôle |
|-----------|------|
| `SubscriptionReminderNotificationSender` | Orchestration (verrou InnoDB, compose, canal, transition) |
| `PreparedSubscriptionReminderChannel` | Valide le routage, retourne `prepared` (défaut) |
| `EmailSubscriptionReminderChannel` | `Mail` + `SubscriptionReminderMail` (Task 286) |
| `subscription_reminder_notifications` | `enabled`, `channel` (`prepared`/`email`), `central_admin_email` |

Après Task 285, le sender appelle `markAsSent()` / `markAsFailed()` lorsque le canal répond ; les états `sent` et `failed` sont **terminaux** (`already_sent` / `already_failed` sans retry). Task 286 ajoute le **premier envoi externe** via Laravel Mail (pas de classe `Notification` Laravel). Destinataire `central_admin` : `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` (null → `unavailable`, rappel `failed`, aucun appel Mail). Anti-double-envoi : `lockForUpdate()` **avant** compose + envoi email (même transaction). Erreur transport → `error` → `failed`. Tests CI : `Mail::fake()` uniquement. CLI : `subscriptions:reminder-notification-test {id} [--channel=email]`.

```text
… → sender → canal prepared|email → transition detected→sent (ou failed)
```

---

## Traitement quotidien des rappels (Task 287)

`ProcessSubscriptionReminders` (`subscriptions:process-reminders`) enchaîne **sans dupliquer** la logique métier :

```text
chunk abonnements actifs (current_period_end)
    ↓
SubscriptionReminderService::assessReminder()
    ↓
recordDetectedReminder() (si nouveau uniquement → sender)
    ↓
SubscriptionReminderNotificationSender (si notifications enabled)
```

| Option | Rôle |
|--------|------|
| `--dry-run` | Détection simulée, aucune persistance ni envoi |
| `--installation` / `--subscription` | Périmètre ciblé |
| `--json` | Compteurs (`newly_detected`, `sent`, `failed`, `ignored`, …) + `duration_ms` |

Rappels déjà enregistrés (`sent`, `failed`, `detected`) : **aucun** nouvel appel sender. Planification : `routes/console.php` — `daily()` + `withoutOverlapping()`, distinct de lifecycle et renouvellement crédit.

Validation préproduction (Task 288) : `docs/operations/subscription-reminders-preprod-validation.md` — scénarios A–N, `Mail::fake()`, pas d’activation production.

Préparation activation production (Task 290) : `docs/operations/subscription-reminders-production-activation.md` — procédure manuelle serveur, fonctionnalité **non** activée dans le dépôt.

### Dashboard administratif — synthèse read-only (Task 293)

Route **`/dashboard`** (`DashboardController`, page Inertia `Dashboard`) — accès **`auth`** + Gate **`accessControlCenter`**.

| Bloc | Source | Lecture seule |
|------|--------|----------------|
| Installations / abonnements | Agrégations SQL (`COUNT`, `CASE`) sur `installations` et `subscriptions` | Oui |
| Échéances | Abonnements **`active`** avec `current_period_end` — jours restants UTC alignés sur `SubscriptionReminderService` (seuils **7, 3, 1, 0**) | Oui |
| Rappels | Comptages par `SubscriptionReminder.status` (`detected`, `sent`, `failed`) | Oui |

Le dashboard **ne** déclenche **ni** `SubscriptionReminderNotificationSender`, **ni** notification email, **ni** mutation lifecycle / renouvellement / rappels. Liens vers `/subscriptions`, `/installations`, `/subscription-reminders` pour consultation uniquement.

### Navigation Dashboard → listes administratives (Task 300)

Les indicateurs opérationnels du **Dashboard** (`Dashboard.vue`) sont cliquables et ouvrent les listes read-only déjà protégées par **`accessControlCenter`**, avec les **filtres query string** reconnus par chaque index :

| Destination | Exemples de deep-links |
|-------------|------------------------|
| `clients.index` | `/clients`, `/clients?status=active` |
| `installations.index` | `/installations`, `/installations?status=suspended` |
| `subscriptions.index` | `/subscriptions?status=grace_period`, `/subscriptions?period=due_7&status=active`, `/subscriptions?period=expired&status=active`, alerte `/subscriptions?expiring_within_days=7` |
| `payments.index` | `/payments`, `/payments?status=paid`, `/payments?overdue=1` |
| `subscription-reminders.index` | `/subscription-reminders?status=detected`, `/subscription-reminders?status=failed` |

Aucun recalcul métier côté Vue : les liens ne font que transmettre des filtres vers les pages TASK 294–299.

### Liste administrative des installations (Task 294)

Route **`GET /installations`** (`InstallationController@index`, Inertia `Installations/Index`) — **`auth`** + Gate **`accessControlCenter`**.

| Élément | Comportement |
|---------|----------------|
| Données | Nom, sous-domaine, domaine, statut installation, version, client, dates (`installed_at`, `last_seen_at`), abonnement courant non terminé (`status`, `current_period_end`), dernier rappel read-only optionnel |
| Filtres serveur | `status`, `client_id`, `search` (name/subdomain/domain), `version` |
| Pagination | 15 par page, query string conservé |
| Exclusions | `database_name`, `database_host`, credentials — **non** exposés |
| Actions | **Aucune** action provisioning / lifecycle depuis cette page (consultation + modal détail) |

Les routes resource `installations` hors `index` restent distinctes ; la liste index est strictement orientée consultation.

### Fiche installation — détail read-only (Task 295)

Route **`GET /installations/{installation}`** (`InstallationController@show`, Inertia `Installations/Show`) — **`auth`** + Gate **`accessControlCenter`**.

| Section | Contenu |
|---------|---------|
| Installation | Identité, statut, version, dates (`installed_at`, `last_seen_at`, …) sans champs de connexion DB |
| Client | Coordonnées administratives déjà prévues (ex. `company_name`, `email`) |
| Abonnement courant | Dernier abonnement **non terminé** uniquement ; sinon message « Aucun abonnement actif » |
| Paiements | Liste paginée (`payments_page`), tri `created_at` DESC |
| Rappels | Liste paginée (`reminders_page`), tri `scheduled_for` DESC — lecture seule, aucun envoi |

Aucune action de provisioning, lifecycle, paiement ou notification depuis cette page.

### Liste administrative des clients (Task 296)

Route **`GET /clients`** (`ClientController@index`, Inertia `Clients/Index`) — **`auth`** + Gate **`accessControlCenter`**.

| Élément | Comportement |
|---------|----------------|
| Données | Entreprise, contact, e-mail, téléphone, statut client, date de création |
| Statistiques | Comptages SQL (`withCount`, sous-requête) : installations totales / actives / suspendues / terminées ; abonnements non terminés |
| Filtres | `search` (client + installations liées), `status`, `email`, `phone` |
| Pagination | 15 par page, `withQueryString()`, tri `created_at` DESC |
| Action | Lien **Voir** → **`GET /clients/{client}`** (`clients.show`) — fiche client (consultation uniquement) |

Aucune création, modification ou suppression depuis cette liste.

### Liste administrative des paiements (Task 299)

Route **`GET /payments`** (`PaymentController@index`, Inertia `Payments/Index`) — **`auth`** + Gate **`accessControlCenter`**.

| Élément | Comportement |
|---------|----------------|
| Données | Paiement (montant encaissé `amount`, statut, dates, référence, crédit) ; abonnement ; installation ; client — sans champs de connexion DB |
| Montants indicateurs | Sommes SQL sur **`Payment.amount`** selon le statut (`paid`, `pending`, `refunded`) — distinct du tarif abonnement et de `monthly_unit_amount` |
| Crédit | `credit_months_purchased`, `credit_exhausted_at`, `consumptions_count` en lecture seule — aucune consommation depuis la liste |
| Filtres | `status`, `payment_method`, `client_id`, `installation_id`, `subscription_id`, `search`, `date_from` / `date_to` (payé → `paid_at`, sinon `created_at`) ; compatibilité alerte `overdue=1` |
| Tri | `created_at` DESC, `id` DESC |
| Pagination | 15 par page, `withQueryString()` |
| Action | **Voir l'installation** → `installations.show` uniquement |

Aucune création, modification, remboursement, renouvellement ou consommation de crédit depuis cette liste.

### Liste administrative des abonnements (Task 298)

Route **`GET /subscriptions`** (`SubscriptionController@index`, Inertia `Subscriptions/Index`) — **`auth`** + Gate **`accessControlCenter`**.

| Élément | Comportement |
|---------|----------------|
| Données | Abonnement (statut, montant, devise, dates de période) ; installation (identité, statut) ; client (id, raison sociale) — sans champs de connexion DB |
| Indicateurs | Agrégations SQL : total et statuts ; échéances actives alignées sur `ControlCenterDashboardStatisticsService` / rappels (UTC, jours calendaires J-7, J-3, J-1, J0, expirée, future) |
| Filtres | `status`, `installation_status`, `client_id`, `installation_id`, `search`, `period` (`future`, `due_7`, `due_3`, `due_1`, `due_0`, `expired`) ; compatibilité alerte dashboard `expiring_within_days=7` |
| Tri | `current_period_end` ASC, puis `id` ASC (échéances proches en tête) |
| Pagination | 15 par page, `withQueryString()` |
| Action | **Voir l'installation** → `installations.show` uniquement |

Aucune création, modification, renouvellement, rappel ou autre action métier depuis cette liste.

### Fiche administrative client (Task 297)

Route **`GET /clients/{client}`** (`ClientController@show`, Inertia `Clients/Show`) — **`auth`** + Gate **`accessControlCenter`**.

| Section | Contenu |
|---------|---------|
| Informations client | Champs administratifs du modèle `Client` (sans données techniques) |
| Statistiques | Agrégations SQL : installations (total, actives, inactives, suspendues, terminées) ; abonnements (actifs, grâce, suspendus, terminés) |
| Installations | Liste paginée (`installations_page`), lien **Ouvrir la fiche** → `installations.show` |
| Abonnements | Historique paginé (`subscriptions_page`), tri `current_period_end` DESC — terminés inclus |
| Paiements | Liste paginée (`payments_page`) via jointures `subscriptions` / `installations` |
| Rappels | Liste paginée (`reminders_page`), tri `scheduled_for` DESC — lecture seule, **aucun** appel au moteur d’envoi |

Aucune action métier (création, modification, provisioning, paiement, renouvellement, rappel) depuis cette page.

---

## Planification scheduler — renouvellement par crédit (Task 275)

### Tâches planifiées distinctes (`routes/console.php`)

| Commande | Rôle | Fréquence |
|----------|------|-----------|
| `subscriptions:sync-lifecycle` | Statuts `active` → `grace_period` → `suspended` | **`daily()`** (00:00 `config('app.timezone')`, actuellement **UTC**) |
| `subscriptions:renew-with-credit` | Consommation FIFO d’**un** mois prépayé (orchestrateur CLI) | **`daily()`** + **`withoutOverlapping()`** |
| `subscriptions:process-reminders` | Détection + persistance + notification optionnelle (Task 287) | **`daily()`** + **`withoutOverlapping()`** |

Le scheduler Laravel est déclenché en production par **`php artisan schedule:run`** (typiquement cron **chaque minute**). Les deux commandes peuvent tomber le même jour à minuit ; **aucune** ne contient de logique métier de l’autre.

**Ordre d’enregistrement** : lifecycle puis renewal. Les deux ordres d’exécution manuels ou quasi-simultanés restent cohérents (crédit disponible → au plus une consommation ; lifecycle ne consomme pas).

### Présence planifiée ≠ activation commerciale

La commande planifiée s’exécute, mais si `automatic_credit_renewal.enabled` est **`false`**, elle **sort immédiatement** sans consommer ni modifier les abonnements. Déploiement du scheduler possible **avant** `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=true`.

### Chevauchement (`withoutOverlapping`)

Sur `subscriptions:renew-with-credit` uniquement : mutex cache Laravel (expiration par défaut **24 h** si le processus ne se termine pas). Une seconde exécution concurrente **du même jour** est ignorée tant que le mutex est actif — complète l’idempotence métier (`now > current_period_end`, un mois par run).

### Politique Task 273 inchangée

`grace_period` / `suspended` + crédit → renouvellement possible via la commande planifiée ; `terminated` exclu ; sans crédit → renewal no-op, lifecycle continue.

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

L’état **`suspended`** reste également la **même** ligne `subscriptions` (subscription courante non terminée). Une **consommation de crédit** depuis **`suspended`** peut, dans l’implémentation actuelle, **réactiver** la subscription (`active`) et **lever** les horodatages de grâce/suspension — voir § Subscription `suspended` et consommation de crédit.

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
| Historique des **évolutions** subscription / paiement / consommation | **`audit_logs`** (ex. `subscription.created`, `subscription.updated`, `subscription.lifecycle_synced`, `subscription.credit_consumed`, `subscription.credit_consumption_failed`, `payment.created`, **`payment.updated`** — y compris changements `paid` ↔ `refunded`, `payment.deleted`, `payment.renewal_applied`, `payment.renewal_failed`, …) |

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
| Tests HTTP cycle paid ↔ refunded, recalcul crédit | `tests/Feature/PaymentCreditHttpTest.php` |
| Tests consommation depuis `suspended` (service + HTTP) | `tests/Feature/SubscriptionCreditConsumptionTest.php`, `tests/Feature/SubscriptionCreditConsumptionHttpTest.php` |
| Sync lifecycle planifié | `app/Console/Commands/SyncSubscriptionLifecycle.php` |
| UI crédit + consommation globale | `resources/js/Pages/Subscriptions/Show.vue` |
| UI renouvellement ciblé paiement | `resources/js/Pages/Subscriptions/Payments/Show.vue` |

---

## Liens

- [`status-lifecycle.md`](status-lifecycle.md) — séparation installation / subscription / accès
- Task 36 — inspection « abonnement courant » et comparaison options A / B

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
| Tests HTTP cycle paid ↔ refunded, recalcul crédit | `tests/Feature/PaymentCreditHttpTest.php` |
| Tests consommation depuis `suspended` (service + HTTP) | `tests/Feature/SubscriptionCreditConsumptionTest.php`, `tests/Feature/SubscriptionCreditConsumptionHttpTest.php` |
| Sync lifecycle planifié | `app/Console/Commands/SyncSubscriptionLifecycle.php` |
| UI crédit + consommation globale | `resources/js/Pages/Subscriptions/Show.vue` |
| UI renouvellement ciblé paiement | `resources/js/Pages/Subscriptions/Payments/Show.vue` |

---

## Liens

- [`status-lifecycle.md`](status-lifecycle.md) — séparation installation / subscription / accès
- Task 36 — inspection « abonnement courant » et comparaison options A / B
