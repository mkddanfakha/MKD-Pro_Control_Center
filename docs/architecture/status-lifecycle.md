# Séparation `Installation.status` et `Subscription.status`

Document de référence pour le **MKD-Pro Control Center**.  
Dernière vérification alignée sur le code applicatif (validation contrôleurs, modèles, services).

---

## Principe fondamental

```text
Subscription.status ≠ Installation.status
```

- **`Installation.status`** décrit l’**état opérationnel / administratif** de l’instance MKD-Pro Gestion telle qu’enregistrée dans le Control Center.
- **`Subscription.status`** décrit l’**état commercial et de facturation** de l’abonnement lié à une installation.

Ces deux dimensions **ne doivent pas être fusionnées** ni supposées synchronisées automatiquement.

En particulier :

```text
subscription.suspended
```

**ne signifie pas** automatiquement :

```text
installation.suspended
```

Le cycle de vie abonnement (`SubscriptionService::syncLifecycle()`) **ne modifie pas** `Installation.status`. Ce comportement est **volontaire** et reste inchangé tant qu’une décision produit explicite n’introduit un autre mécanisme.

---

## `Installation.status`

### Responsabilité

> État opérationnel / administratif de l’instance MKD-Pro Gestion dans le Control Center.

Valeurs **validées aujourd’hui** à la création / modification d’une installation (`InstallationController`) :

```text
active
inactive
suspended
terminated
```

Aucune autre valeur ne doit être introduite sans migration et décision d’architecture.

### `active`

| Aspect | Description |
|--------|-------------|
| **Signification** | L’installation est considérée comme **déployée et exploitée** côté administration (instance connue, potentiellement en service). |
| **Exemple** | Client en production ; fiche installation complétée après mise en ligne manuelle. |
| **Exploitation technique** | Référence ops « instance vivante » ; champs techniques (`subdomain`, `database_name`, etc.) pertinents pour le suivi. |
| **Ce que cela ne signifie pas** | Que l’abonnement est payé ou en règle : un abonnement peut être `suspended` ou absent alors que l’installation reste `active`. |

### `inactive`

| Aspect | Description |
|--------|-------------|
| **Signification** | Instance **désactivée ou non utilisée** côté ops (maintenance planifiée, instance créée mais pas encore activée, mise en pause administrative). |
| **Exemple** | Installation en préparation ; coupure temporaire sans retirer la fiche. |
| **Exploitation technique** | Signal administratif pour les équipes ; **aucune règle automatique** ne lie ce statut au cycle de vie abonnement aujourd’hui. |
| **Ce que cela ne signifie pas** | `grace_period` ou impayé : ce sont des concepts **abonnement**, pas installation. |

### `suspended`

| Aspect | Description |
|--------|-------------|
| **Signification** | Instance **suspendue côté opérations** (incident, non-conformité technique, décision admin). |
| **Exemple** | Arrêt volontaire du service côté hébergeur documenté dans le CC. |
| **Exploitation technique** | Distinct de `Subscription.status = suspended` (impayé / fin de grâce commerciale). |
| **Ce que cela ne signifie pas** | Que le lifecycle abonnement a basculé l’installation en suspendu : **non**, `syncLifecycle()` ne touche pas ce champ. |

### `terminated`

| Aspect | Description |
|--------|-------------|
| **Signification** | Instance **retirée** du parc (fin de contrat ops, désinstallation, archivage). |
| **Exemple** | Client parti ; instance ne doit plus être provisionnée ni monitorée comme active. |
| **Exploitation technique** | Peut coïncider avec `Subscription.terminated` dans un **processus métier manuel**, sans sync auto. |
| **Ce que cela ne signifie pas** | Que l’abonnement est automatiquement `terminated` ou inversement. |

---

## `Subscription.status`

### Responsabilité

> État commercial et de facturation de l’abonnement associé à une installation.

Constantes modèle (`Subscription`) et validation (`SubscriptionController`) :

```text
active
grace_period
suspended
terminated
```

### Cycle de vie **automatique** actuel (`SubscriptionService::syncLifecycle()`)

```text
active
   │
   │ current_period_end dépassé
   ▼
grace_period
   │
   │ grace_period_ends_at dépassé
   ▼
suspended
```

- **Période de grâce** : 7 jours calendaires (`SubscriptionService::GRACE_PERIOD_DAYS`) après la fin de période courante ; `grace_period_ends_at` est posé à la première transition `active` → `grace_period`.
- **`suspended`** et **`terminated`** : **aucune transition automatique** depuis `syncLifecycle()` une fois suspendu ; l’abonnement **reste** `suspended`.
- **`terminated`** : **non produit** par `syncLifecycle()` ; passage en `terminated` = **action manuelle** (CRUD ou processus externe).

```text
suspended → terminated
```

n’est **pas automatisé** par `SubscriptionService::syncLifecycle()`.

La commande planifiée `subscriptions:sync-lifecycle` ne traite que les abonnements `active` et `grace_period`.

### `active`

| Aspect | Description |
|--------|-------------|
| **Condition d’entrée** | Création / édition admin ; retour à `active` après **renouvellement** via paiement payé (`renew()`). |
| **Signification** | Période courante **en cours** ou considérée valide côté commercial. |
| **Accès calculé** | `accessible` → `isAccessible()` = **true**. |
| **Transitions possibles** | → `grace_period` (auto si `current_period_end` dépassé) ; → `suspended` / `terminated` (manuel) ; renouvellement repasse en `active`. |

### `grace_period`

| Aspect | Description |
|--------|-------------|
| **Condition d’entrée** | `syncLifecycle()` lorsque `active` et `now > current_period_end`. |
| **Signification** | Période payée **expirée** ; **tolérance commerciale** de 7 jours avant suspension abonnement. |
| **Accès calculé** | `accessible` → **true** (accès **autorisé** dans le calcul actuel). |
| **Transitions possibles** | → `suspended` (auto si `now > grace_period_ends_at`) ; → `active` (renouvellement) ; `terminated` (manuel). |

### `suspended`

| Aspect | Description |
|--------|-------------|
| **Condition d’entrée** | Fin de grâce dépassée (`syncLifecycle`) ; ou saisie admin. |
| **Signification** | Abonnement **non valide** côté facturation (impayé prolongé, décision commerciale). |
| **Accès calculé** | `suspended` → **false**. |
| **Transitions possibles** | → `active` (renouvellement) ; → `terminated` (manuel) ; **pas** de transition auto vers `terminated` aujourd’hui. |

### `terminated`

| Aspect | Description |
|--------|-------------|
| **Condition d’entrée** | **Manuelle** uniquement dans le cycle automatisé actuel. |
| **Signification** | Abonnement **clos** définitivement. |
| **Accès calculé** | `terminated` → **false**. |
| **Transitions possibles** | Aucune transition métier standard (état terminal côté produit). |

---

## Accès actuel : `InstallationAccessService`

Service **sans effet de bord** : lecture seule, **ne modifie** ni installation, ni abonnement, ni base.

- Sélection de l’abonnement pertinent : **`$installation->subscriptions()->latest('id')->first()`** (dernier enregistrement par `id` décroissant).
- **`Installation.status` n’est pas pris en compte** dans le calcul.

### Matrice d’accès (état du code)

| Subscription.status | accessStatus      | Accessible (`isAccessible()`) |
| ------------------- | ----------------- | ----------------------------- |
| active              | accessible        | oui                           |
| grace_period        | accessible        | oui                           |
| suspended           | suspended         | non                           |
| terminated          | terminated        | non                           |
| aucune subscription | no_subscription   | non                           |
| statut inconnu      | suspended         | non                           |

Pour un statut d’abonnement **inconnu**, `accessStatus` retourne `suspended` (refus d’accès dans le calcul) ; seules les valeurs listées ci-dessus sont des statuts métier définis.

### Affichage UI

La page **`Installations/Show.vue`** affiche une section **Accès** alimentée par le contrôleur (`access.accessible`, `access.status`).  
Cet affichage est **informatif** : il **ne bloque aucune** application MKD-Pro Gestion cliente. Aucun middleware, API de blocage ou licence n’est en place dans le Control Center pour imposer cet état aux instances distantes.

---

## Matrice opérationnelle (comportement actuel)

### Cas 1 — `Installation.active` + `Subscription.active`

```text
Installation opérationnelle (côté fiche admin)
Accès calculé = autorisé
```

### Cas 2 — `Installation.active` + `Subscription.grace_period`

```text
Installation toujours active (champ installation inchangé par le lifecycle)
Accès calculé = autorisé
```

### Cas 3 — `Installation.active` + `Subscription.suspended`

```text
Installation toujours active techniquement (pas de sync auto vers installation.suspended)
Accès calculé = refusé (accessStatus suspended)
```

**Important** : cela **ne signifie pas** que l’application cliente MKD-Pro Gestion est bloquée aujourd’hui. Le **blocage réel** n’existe pas encore ; seul le calcul et l’affichage Control Center reflètent le refus.

### Cas 4 — `Installation.suspended` + `Subscription.active`

État **opérationnel distinct** : maintenance ou suspension ops alors que la facturation est encore `active`.  
**Aucune règle automatique** ne modifie l’abonnement ni ne synchronise les deux statuts.  
L’accès calculé reste **autorisé** (basé uniquement sur l’abonnement `active`), **sans** tenir compte de `Installation.suspended`.

### Cas 5 — `Installation.terminated` + `Subscription.terminated`

Peut être **cohérent** dans un processus de fin de vie **manuel** (clôture commerciale + retrait ops).  
**Aucune synchronisation automatique** entre les deux champs n’existe dans le code actuel.

---

## Décisions à prendre avant l’accès réel

Questions **ouvertes** (non tranchées dans ce document) :

1. Que signifie exactement **`Installation.inactive`** dans les processus ops (pré-prod, pause, archivage soft) ?
2. Une installation **`inactive`** doit-elle rester **accessible** lorsque l’abonnement est **`active`** ?
3. Une installation **`terminated`** doit-elle **toujours** imposer la terminaison de l’abonnement (processus, pas sync aveugle) ?
4. Quelle **politique de maintenance** (`Installation.inactive` ou `suspended`) vs accès commercial (`Subscription`) ?
5. Que doit faire **MKD-Pro Gestion** si le **Control Center est indisponible** (fail-open vs fail-closed, cache, JWT) ?
6. Quelle **différence explicite** entre la **grâce commerciale** (7 jours, `grace_period`) et une **tolérance technique** hors ligne (cache, indisponibilité CC) ?

---

## Références code (vérification)

| Élément | Emplacement |
|---------|-------------|
| Valeurs `Installation.status` | `InstallationController` validation |
| Valeurs `Subscription.status` | `Subscription` constantes, `SubscriptionController` validation |
| Lifecycle abonnement | `SubscriptionService::syncLifecycle()` |
| Sync planifiée | `subscriptions:sync-lifecycle`, `routes/console.php` |
| Accès calculé | `InstallationAccessService` |
| Affichage accès | `InstallationController::show`, `Installations/Show.vue` |
| Non-modification installation par lifecycle | Tests `SubscriptionLifecycleSyncTest` |

---

## Périmètre de ce document

Ce document **ne demande aucune implémentation**. Il ne modifie pas les services, modèles, migrations ni les applications clientes MKD-Pro Gestion. Il sert de base pour : accès réel, provisioning, monitoring et terminaison d’installation.
