# Contrat du pipeline provisioning — TASK 339

**Statut :** contrat architectural — **aucune exécution externe** (SSH, DNS, o2switch, API MKD-Pro, Vault).

---

## 1. Flux des couches

```text
ProvisioningRun
    ↓
ProvisioningPipeline          (orchestration — plan, ordre, arrêt)
    ↓
ProvisioningStep (interface)  (logique métier future par étape)
    ↓
Adaptateurs externes          (futurs — hors TASK 339)
    ↓
ProvisioningStepResult
    ↓
ProvisioningRunStateService / ProvisioningRunStepStateService
    ↓
Persistance (runs / steps)
    ↓
Audit (futur — provisioning.*, installation.*)
    ↓
Readiness (futur — dérivé des steps mark_*)
```

| Couche | Responsabilité | TASK 339 |
|--------|----------------|----------|
| **Orchestration** | Ordre, arrêt après échec, retry via nouveau run | `ProvisioningPipeline`, `ProvisioningStepRegistry` |
| **Logique métier** | Une étape = `execute(ProvisioningContext)` | Interface seule |
| **Adaptateurs** | SSH, DNS, panel, API Gestion | **Non créés** |
| **Persistance** | Eloquent + StateServices | Existant (337–338) |
| **Audit** | Événements append-only | Documenté, non codé |
| **Readiness** | Paliers 6–8 | Documenté, non calculé |

Le pipeline **ne connaît pas** directement SSH, o2switch, DNS, MySQL distant, API MKD-Pro, Vault.

---

## 2. Contrat `ProvisioningStep`

- `stepKey(): string` — identifiant stable (unique dans le registre exécutable).
- `order(): int` — ordre relatif d'exécution (tri croissant, puis `stepKey` si égalité).
- `execute(ProvisioningContext $context): ProvisioningStepResult` — logique **déclarative** de l'étape ; aucune dépendance o2switch / SSH / DNS / API MKD-Pro dans le contrat.

Les implémentations métier réelles seront branchées plus tard ; les tests utilisent des fakes no-op dans `Tests\Fakes\Provisioning`.

---

## 3. `ProvisioningContext`

DTO **immuable** transportant le contexte d'un run sans secrets :

| Champ (minimum) | Rôle |
|-----------------|------|
| `provisioning_run_id` | Run en cours |
| `installation_id` / `client_id` | Ancrage tenant |
| `installation_name`, `subdomain`, `domain` | Identité logique |
| `database_name`, `database_host` | Cibles DB **nom/host uniquement** |
| `target_version`, `target_commit`, `pipeline_version` | Cible déploiement |
| `configuration`, `external_references` | Maps non secrètes |

Références objet : `ProvisioningRun`, `Installation` (lecture).

**Interdit :** password, token, clé privée, credential brut, secret Vault, contenu `.env` sensible.

Validation : `ProvisioningContext::isForbiddenSecretKey()`.

---

## 4. `ProvisioningStepResult`

Outcomes : `succeeded`, `failed`, `manual_intervention_required`, `skipped`.

Champs : `step_key`, `code`, message opérateur, `output_summary`, `retryable`, `metadata` redacted, `ProvisioningErrorCategory`.

Ne jamais persister automatiquement le message complet d'une exception PHP.

---

## 5. Classification d'erreurs

| Catégorie | Exemples documentaires | Comportement futur |
|-----------|------------------------|-------------------|
| **Retryable** | Timeout réseau, service temporairement indisponible | Retry intra-step ou nouvelle tentative contrôlée |
| **Manual intervention** | DNS incohérent, DB distante état inattendu, credentials invalides, ambiguïté externe | Run → `manual_intervention_required` ; nouveau run après correction |
| **Definitive** | Installation inexistante, version impossible, précondition commerciale invalide | Échec terminal ; pas de « succès » silencieux |

---

## 6. Registre — 18 étapes (ordre fixe)

1. `validate` → 2. `reserve` → 3. `dns` → 4. `hosting` → 5. `database` → 6. `deploy` → 7. `environment` → 8. `dependencies` → 9. `build` → 10. `migrate` → 11. `storage` → 12. `cache` → 13. `admin` → 14. `modules` → 15. `health` → 16. `mark_deployed` → 17. `mark_verified` → 18. `mark_ready`

Implémentation canonique : `App\Services\Provisioning\ProvisioningStepRegistry::orderedStepKeys()`.

### Registre exécutable

- `register(ProvisioningStep)` — enregistre une instance ; refuse les doublons de `stepKey`.
- `orderedExecutableSteps()` — étapes triées par `order()` puis `stepKey`.
- `getExecutableStep($key)` — lève une exception si clé inconnue.
- Registre vide autorisé (run synthétique sans étape).

---

## 7. `ProvisioningPipeline::run()`

1. Reçoit un `ProvisioningContext`.
2. Charge les étapes exécutables du registre (ordre déterministe).
3. Appelle `execute()` pour chaque étape avec **le même contexte**.
4. **`succeeded`** ou **`skipped`** → étape suivante.
5. **`failed`** ou **`manual_intervention_required`** → arrêt immédiat ; étapes suivantes **non** exécutées.
6. Retourne `ProvisioningPipelineResult` (outcome global + liste des `ProvisioningStepResult`).

**Ne fait pas :** transition SQL sur `ProvisioningRun` / `ProvisioningRunStep` (rôle de `ProvisioningRunStateService` / `ProvisioningRunStepStateService`).

`buildPlan()` / `stepsBlockedAfterFailure()` restent des aides de **planification** sur les 18 clés canoniques.

---

## 8. Règles d'arrêt (exécution)

Si une étape **obligatoire** échoue (`failed` ou `manual_intervention_required`), les étapes **suivantes** ne doivent pas être exécutées.

Exemple : `build` failed → `migrate`, `storage`, … `mark_ready` **bloqués**.

Les étapes `mark_deployed`, `mark_verified`, `mark_ready` ne sont pas atteignables sans succès des prérequis documentés (spec TASK 333).

---

## 9. Idempotence (contrat)

Pour chaque étape future :

- **Avant action :** vérifier l'état externe (preuve), pas seulement `ProvisioningRunStep.status = succeeded`.
- **Preuve de succès :** `output_summary` + revérification (HTTP, commit on disk, migration count, etc.).
- **Interdit :** `migrate:fresh`, `db:wipe`, DROP aveugle, écrasement installation existante.

---

## 10. Reprise (contrat)

```text
Run A → failed | manual_intervention_required (terminal, immuable)
Run B → retry_of_run_id = A
```

Run A **jamais** repassé en `running`. Run B : chaque étape **vérifiée** ; skip uniquement si preuve fiable. Non implémenté en TASK 339.

---

## 11. Transactions

**Pas** de transaction MySQL unique sur tout le pipeline : effets externes (DNS, SSH, deploy) non annulables par rollback SQL. Transactions **locales** futures pour transitions d'état uniquement.

---

## 12. Secrets (architecture future)

```text
Step → secret reference → SecretProvider/Vault → credential temporaire → action → destruction/expiration
```

Aucun credential brut dans Context, Result, metadata, summaries, logs. Vault / SecretProvider **non créés** en TASK 339.

---

## 13. Audit & readiness

Événements futurs : run started, step started/succeeded/failed, run failed/completed, deployed, verified, ready.

Readiness 6–8 : **non** calculée ; steps `mark_*` = contrats futurs. **Aucune** colonne sur `Installation`.

---

## Création d'une demande de provisioning

**Service :** `App\Services\Provisioning\ProvisioningRunFactory::createRequest(Installation)`

**La création d'une demande ne provisionne rien.**

| Étape | Action |
|-------|--------|
| Validation | Installation existante, non terminée, client présent, `name` + `subdomain` renseignés |
| Run existant | `pending` / `running` / `succeeded` → **refus** ; `failed` / `manual_intervention_required` / `cancelled` → **nouveau run** avec `retry_of_run_id` |
| Persistance | Transaction courte : `ProvisioningRun` `status=pending` + 18 `ProvisioningRunStep` `pending` |
| Exécution | **Aucune** — pas d'appel à `runPersisted()` ni transition `running` sur les steps |

Métadonnées run : identifiants non sensibles (`installation_name`, `subdomain`, `database_name`, etc.) — pas de secrets.

Distinction volontaire **demande** (factory) vs **exécution** (orchestrateur persisté), pour l'UI et les jobs futurs.

---

## Orchestration persistée d'un ProvisioningRun

**Service :** `App\Services\Provisioning\ProvisioningPersistedRunOrchestrator`  
**Entrée pipeline :** `ProvisioningPipeline::runPersisted(ProvisioningRun $run)`

| Méthode | Persistance | Transitions SQL run/step |
|---------|-------------|---------------------------|
| `run(ProvisioningContext)` | Non | Non |
| `runPersisted(ProvisioningRun)` | Oui | Oui (via StateServices) |

### Déroulé `runPersisted`

1. Refus si run terminal ou non `pending` (reprise partielle **non supportée** — retry = nouveau run).
2. `ProvisioningRunStateService` : `pending` → `running`.
3. `ProvisioningContext::fromRun($run)`.
4. Synchronisation des `ProvisioningRunStep` (clé + ordre, unique par run via contrainte SQL).
5. Pour chaque étape enregistrée (ordre du registre exécutable) :
   - ignorer si step déjà terminal (`finished_at` / statut terminal) ;
   - `setCurrentStep` sur le run ;
   - `execute()` ;
   - persister `output_summary`, `metadata`, `error_code`, `error_message` (non secrets) ;
   - `ProvisioningRunStepStateService` : `skipped` depuis `pending`, sinon `pending` → `running` → terminal ;
   - arrêt run + retour si `failed` ou `manual_intervention_required`.
6. Si toutes les étapes passent : run → `succeeded`.

**Limites de reprise :** un run `running` ou partiellement exécuté ne peut pas être relancé sur le même enregistrement ; créer un run avec `retry_of_run_id`.

**Cette orchestration reste locale et ne déclenche aucun effet externe.**

---

## Déclenchement depuis l’administration (TASK 349)

| Élément | Comportement |
|---------|--------------|
| **Point d’entrée UI** | Fiche **Installation Show** — section « Provisioning » |
| **Action** | Bouton « Créer une demande de provisioning » (ou « Créer une nouvelle demande… » après échec / annulation / intervention) |
| **Route** | `POST installations/{installation}/provisioning-runs` (`installations.provisioning-runs.store`) — `auth` + `can:accessControlCenter` |
| **Contrôleur** | `InstallationController::storeProvisioningRun()` — délègue à `ProvisioningRunFactory::createRequest()` uniquement |
| **Effet** | `ProvisioningRun` en `pending` + 18 `ProvisioningRunStep` en `pending` |
| **Non effectué** | Aucun appel à `ProvisioningPipeline::runPersisted()` ; pas de job, pas de commande, pas d’effet SSH/DNS/o2switch |

### Disponibilité du bouton (autorité serveur)

Props Inertia `provisioning_actions` (`can_create_request`, `unavailable_reason`, `button_label`, `retry_basis_run_id`, `store_url`) — calculées via `ProvisioningRunFactory::describeCreateRequestAvailability()`.

| Dernier run (ou absence) | Création autorisée |
|--------------------------|-------------------|
| Aucun run | Oui |
| `pending` / `running` / `succeeded` | Non (raison exposée) |
| `failed` / `manual_intervention_required` / `cancelled` | Oui — nouveau run ; `retry_of_run_id` = id du run précédent |

### Demande vs exécution

- **Demande** : enregistrement administratif pending — TASK 349.
- **Exécution** : `runPersisted()` — hors périmètre de la création depuis l’admin ; déclenchée par un futur mécanisme dédié.

---

## Consultation d’un ProvisioningRun (TASK 350)

**La consultation d’un ProvisioningRun n’exécute aucune étape et ne modifie aucun état.**

| Élément | Comportement |
|---------|--------------|
| **Route** | `GET provisioning-runs/{provisioning_run}` (`provisioning-runs.show`) — `auth` + `can:accessControlCenter` |
| **Contrôleur** | `ProvisioningRunController::show()` — lecture seule |
| **Présentation** | `ProvisioningRunAdminPresentation` — sérialisation sans logique d’exécution |
| **Navigation** | Depuis Installation Show → lien « Voir le provisioning » ; retour installation, client, run parent (`retry_of_run_id`) |

### Informations exposées

- Run : id, statut, dates (UTC), trigger, retry, messages/codes run.
- Installation et client (identité administrative).
- Statistiques des steps (comptages par statut, calcul serveur).
- Liste des steps ordonnées par `step_order` : clé, statut, dates, message, `error_code`, `output_summary` filtré.

### Metadata

- Les champs JSON `metadata` (run et step) **ne sont pas affichés** dans l’UI admin : pas de contrat d’affichage sûr au-delà du filtrage partiel sur `output_summary` (clés interdites via `ProvisioningContext::isForbiddenSecretKey`).
- Priorité sécurité : pas d’exposition arbitraire de structures JSON.

### Absence d’actions mutantes

- Aucun bouton Exécuter / Relancer / Annuler sur cette page.
- Aucune route POST/PUT/PATCH/DELETE associée au Show dans TASK 350.

---

## Audit préalable à l’exécution manuelle (TASK 351)

**Aucune exécution n’est disponible depuis l’administration à ce stade.** Cette section cartographie le futur parcours et l’état actuel du code, sans modifier le comportement du pipeline.

> **La consultation d’un ProvisioningRun n’exécute aucune étape et ne modifie aucun état.**

### Parcours futur (non implémenté)

```text
Admin (ProvisioningRun Show)
    ↓
POST provisioning-runs/{provisioning_run}/execute   [à créer]
    ↓
auth + can:accessControlCenter
    ↓
ProvisioningRunController::execute()                [à créer]
    ↓
ProvisioningPipeline::runPersisted($run)
    ↓
ProvisioningPersistedRunOrchestrator::runPersisted()
    ↓
ProvisioningRunStateService / ProvisioningRunStepStateService
    ↓
ProvisioningStep::execute(ProvisioningContext)
    ↓
ProvisioningPipelineResult + redirect Show + flash
```

### État actuel du pipeline (inchangé par TASK 351)

| Méthode | Entrée | Sortie | Persistance | Effet externe |
|---------|--------|--------|-------------|---------------|
| `ProvisioningPipeline::run(ProvisioningContext)` | Contexte immuable | `ProvisioningPipelineResult` | Non | Dépend des steps enregistrés dans le registre **de l’instance** |
| `ProvisioningPipeline::runPersisted(ProvisioningRun)` | Run Eloquent | `ProvisioningPipelineResult` | Oui (StateServices) | Idem — délègue à l’orchestrateur |
| `ProvisioningPersistedRunOrchestrator::runPersisted()` | Run `pending` | `ProvisioningPipelineResult` | Oui, step par step | Idem |

**Déroulé `runPersisted` (orchestrateur)** — résumé :

1. `assertRunEligibleForPersistedExecution` : refus si terminal ou si statut ≠ `pending` ou si `finished_at` renseigné.
2. Run : `pending` → `running` (`ProvisioningRunStateService`).
3. `ProvisioningContext::fromRun($run)`.
4. Sync des steps persistées avec le registre exécutable (création si manquante, correction d’ordre).
5. Pour chaque step exécutable : skip si step déjà terminal ; `setCurrentStep` ; `execute()` ; persistance champs non-transition ; transitions step ; arrêt run si `failed` ou `manual_intervention_required`.
6. Si toutes les steps passent : run → `succeeded`.

**Arrêt / exceptions** :

- Outcomes step `failed` / `manual_intervention_required` : run terminal correspondant, steps suivantes non exécutées (restent `pending`).
- `InvalidProvisioningRunTransition` / `InvalidProvisioningRunStepTransition` : transition refusée (run terminal, reprise, etc.).
- Exception PHP non gérée dans `execute()` : **non interceptée** par l’orchestrateur — risque de run bloqué en `running` (documenté ci-dessous).

### Préconditions d’exécution (contrat actuel)

| Statut run | Exécutable via `runPersisted()` ? | Raison (code) |
|------------|-----------------------------------|---------------|
| `pending` | **Oui** | Seul statut autorisé à démarrer l’orchestration |
| `running` | **Non** | Reprise partielle non supportée |
| `succeeded` | **Non** | Run terminal |
| `failed` | **Non** | Run terminal |
| `manual_intervention_required` | **Non** | Run terminal |
| `cancelled` | **Non** | Run terminal |

Retry métier : **nouveau** `ProvisioningRun` (`retry_of_run_id`) via `ProvisioningRunFactory`, pas réexécution du même enregistrement.

### Concurrence (double POST)

- **Installation** : contrainte SQL `active_installation_key` — un seul run `pending`/`running` par installation à la création.
- **Même run** : **aucun verrou applicatif** (pas de `SELECT FOR UPDATE`, pas de token idempotent) autour de `runPersisted()`. Deux requêtes concurrentes sur le **même** run `pending` pourraient tenter `pending → running` ; la seconde devrait échouer une fois le premier passage effectué (statut plus `pending`), mais une fenêtre de course reste possible sans garde-fou dédié.
- **Où ajouter (futur)** : début de `ProvisioningRunController::execute()` ou service dédié — verrou pessimiste sur la ligne `provisioning_runs` ou compare-and-set `status = pending`.

### Reprise / refresh

| Situation | Comportement actuel |
|-----------|---------------------|
| Run `pending`, jamais exécuté | Éligible à une première exécution |
| Run terminal | Refus `runPersisted()` — retry = nouveau run |
| Run `running` (crash PHP mid-flight) | Non réexécutable ; steps partiellement terminées ; pas de reprise sur le même run |
| Step déjà terminal | Ignorée (`isPersistedStepTerminal`) lors d’un **nouveau** run uniquement ; sur un run bloqué `running`, pas de mécanisme de reprise |

### Autorisation future

- Conventions actuelles : `auth` + middleware `can:accessControlCenter` (Gate central dans `AppServiceProvider`).
- **Aucune** `ProvisioningRunPolicy` aujourd’hui — le Gate central suffit pour une première route `execute` alignée sur les routes provisioning existantes.
- Aucune policy à ajouter tant qu’il n’y a pas de rôle opérateur distinct.

### Rôle futur `ProvisioningRunController::execute()` (non créé)

Doit : autoriser ; charger le run ; appeler **uniquement** `ProvisioningPipeline::runPersisted($run)` (ou façade mince) ; attraper `InvalidProvisioningRunTransition` / erreurs métier ; redirect `provisioning-runs.show` + flash.

Ne doit pas : créer des steps (Factory) ; appeler StateServices directement ; accepter des secrets HTTP ; contacter o2switch / SSH / DNS.

### UI future (`ProvisioningRuns/Show.vue`)

- Emplacement probable : en-tête de section « Run », à côté des liens navigation (TASK 350 : zone actions réservée, actuellement absente).
- Visible si : run `pending` **et** serveur expose `execute_actions.can_execute` (à calculer comme pour `provisioning_actions`).
- Masqué si : tout statut non `pending`, ou run déjà `running` / terminal.
- UX : confirmation modale, état loading, `preserveScroll`, prévention double clic — **aucun de ces éléments n’existe aujourd’hui** (page « Lecture seule »).

### Les 18 étapes (registre canonique vs exécutable)

| Ordre | `step_key` | Implémentation prod `app/` | Effet externe réel aujourd’hui |
|------:|------------|----------------------------|--------------------------------|
| 1 | `validate` | Aucune (`ProvisioningStepRegistry` prod **vide**) | Non — contrat / clés canoniques seulement |
| 2 | `reserve` | Idem | Non |
| 3 | `dns` | Idem | Non |
| 4 | `hosting` | Idem | Non |
| 5 | `database` | Idem | Non |
| 6 | `deploy` | Idem | Non |
| 7 | `environment` | Idem | Non |
| 8 | `dependencies` | Idem | Non |
| 9 | `build` | Idem | Non |
| 10 | `migrate` | Idem | Non |
| 11 | `storage` | Idem | Non |
| 12 | `cache` | Idem | Non |
| 13 | `admin` | Idem | Non |
| 14 | `modules` | Idem | Non |
| 15 | `health` | Idem | Non |
| 16 | `mark_deployed` | Idem | Non |
| 17 | `mark_verified` | Idem | Non |
| 18 | `mark_ready` | Idem | Non |

Les tests d’orchestration utilisent des fakes `Tests\Fakes\Provisioning\*` — **hors** autoload production.

**Conséquence (TASK 352)** : un `runPersisted()` avec le conteneur Laravel par défaut (registre exécutable vide) est **refusé** (`ProvisioningExecutableRegistryEmptyException`). Il faudra enregistrer des steps **no-op explicites** ou réels avant toute future route `execute`.

### Transactions et atomicité

- Pas de transaction DB globale sur le run (commentaire explicite dans `ProvisioningPipeline`).
- Chaque transition run/step = `save()` distinct.
- Crash mid-run : état partiel possible (run `running`, mélange de steps terminées / `pending`).
- Erreur DB sur `save()` : propagation PHP standard — pas de rollback automatique des steps déjà commitées.

### Échecs techniques

| Cas | Run final | Steps suivantes |
|-----|-----------|-----------------|
| Step `failed` | `failed` + `error_code` / `error_message` | Non exécutées (`pending`) |
| Step `manual_intervention_required` | `manual_intervention_required` | Non exécutées |
| Step `skipped` | Continue | — |
| Exception PHP dans `execute()` | Probablement `running` sans finalisation | Indéterminé / partiel |
| Exception transition | Aucun changement si `save()` non atteint | — |

Pas de retry automatique dans l’orchestrateur.

### Audit / logs

- **Aucun** `AuditLogService` / événement `provisioning.*` branché sur `runPersisted()` aujourd’hui.
- Historique implicite : champs run/steps (`status`, timestamps, messages) uniquement.
- Logs Laravel : exceptions non catchées seulement.

### Sécurité / secrets

- `ProvisioningContext` : validation `isForbiddenSecretKey` à la construction.
- Factory : metadata de demande filtrée.
- Show admin : pas de metadata brute ; `output_summary` filtré (`ProvisioningRunAdminPresentation`).
- Future route `execute` : **aucun** corps secret requis (POST vide ou token CSRF Laravel standard).

### Ce qui manque avant d’autoriser l’exécution

1. Route POST `provisioning-runs/{provisioning_run}/execute` + `ProvisioningRunController::execute()` (délégation pipeline uniquement).
2. Props serveur `execute_actions` (éligibilité, raison de refus, URL) — miroir de TASK 349.
3. UI : bouton + confirmation + loading (TASK 351 interdit de les ajouter prématurément ; audit seulement).
4. **Garde-fou registre prod** : enregistrer 18 steps no-op explicites **ou** refuser l’exécution si `ProvisioningStepRegistry::isEmpty()`.
5. **Verrou concurrence** sur exécution du même run (ou file / job idempotent).
6. Stratégie exceptions PHP (run `running` orphelin — opérateur / runbook).
7. Audit append-only (`provisioning.run.started`, `provisioning.run.finished`, etc.) — amélioration future documentée.
8. Steps métier réels + adaptateurs externes — **hors** première activation HTTP ; à brancher progressivement avec revue sécurité par step.

---

## Socle de sécurité avant exécution (TASK 352)

**Aucune route HTTP `execute` ni bouton admin.** Cette tâche durcit uniquement le moteur `ProvisioningPersistedRunOrchestrator`.

### Protection contre double démarrage (même run)

- Avant toute exécution : garde-fou registre exécutable non vide.
- Prise de run : transaction DB courte + `lockForUpdate()` sur la ligne `provisioning_runs`, revalidation `pending`, puis transition `pending` → `running` via `ProvisioningRunStateService`.
- Un second appel concurrent sur le **même** run voit un statut ≠ `pending` après verrou et reçoit `InvalidProvisioningRunTransition`.
- Pas de verrou applicatif global sur toute la durée du run (compatible persistance step par step).

### Exception pendant `step->execute()`

- Capture `Throwable` dans l’orchestrateur.
- Step : `pending` → `running` → `failed` (StateServices), champs diagnostic sûrs.
- Run : `failed` terminal + `error_code` / `error_message` (sans secret).
- Propagation : `ProvisioningStepExecutionException` (cause originale en `previous`) après persistance — le run **n’est jamais laissé** en `running` silencieux.

### Registre production vide

- Si `ProvisioningStepRegistry::isEmpty()` : `ProvisioningExecutableRegistryEmptyException` **avant** toute transition.
- Impossible d’obtenir un faux `succeeded` avec zéro step exécutable.
- Les fakes restent dans `Tests\Fakes\Provisioning` uniquement.

### États partiels et absence de reprise

- Toujours **pas** de transaction globale sur le run complet.
- Crash entre deux steps : run peut rester `running` avec steps partiellement terminées — **non réexécutable** sur le même enregistrement.
- Retry : **nouveau** `ProvisioningRun` via `ProvisioningRunFactory` (`retry_of_run_id`).

### Diagnostics sûrs

- `ProvisioningExecutionDiagnostics` : code `uncaught_<ExceptionClass>`, message tronqué / masqué si fragments sensibles (`password`, `token`, `api_key`, clés interdites `ProvisioningContext::isForbiddenSecretKey`).

### Traçabilité actuelle (sans AuditLog execute)

- Suffisant pour diagnostiquer un échec : `current_step`, statuts/timestamps run & steps, `error_code`, `error_message`, metadata step (`exception_class` non secret).
- **Reste à faire** avant execute HTTP : événements audit append-only (`provisioning.run.*`), corrélation opérateur, flash admin.

### Encore requis avant execute HTTP (TASK 352 n’ajoute pas)

- Route POST + `ProvisioningRunController::execute()` + props `execute_actions` + UI.
- ~~Enregistrement des 18 steps production~~ — **TASK 353** (contrats sans infra).
- Politique opérateur affinée si besoin au-delà de `accessControlCenter`.
- Stratégie run `running` orphelin (crash process) — runbook opérationnel.

---

## Steps de provisioning — TASK 353

Enregistrement production via `ProvisioningServiceProvider` → `ProductionProvisioningStepCatalog` (18 classes sous `app/Services/Provisioning/Steps/`).

**Stratégie de résultat (pas de faux succès d’installation) :**

| Type | Outcome | Quand |
|------|---------|--------|
| Validation locale CC | `succeeded` | Données installation/client valides (`validate` uniquement) |
| Adaptateur infra manquant | `manual_intervention_required` | Steps 2–15 — aucun appel OVH/o2switch/DNS/deploy réel |
| Readiness non branché | `manual_intervention_required` | Steps 16–18 — pas de persistance readiness |
| `skipped` | — | Non utilisé en TASK 353 (éviter un run `succeeded` artificiel) |

**Idempotence :** `à définir` pour chaque step (aucune idempotence externe implémentée).

| Ordre | Clé | Classe | État actuel | Effet externe | Dépendances / futur adaptateur |
|------:|-----|--------|-------------|---------------|--------------------------------|
| 1 | `validate` | `ValidateProvisioningStep` | Validation locale CC | Non | `ProvisioningContext` (nom, subdomain, client) |
| 2 | `reserve` | `ReserveProvisioningStep` | Contrat — MI | Non | Adaptateur `capacity_reservation` |
| 3 | `dns` | `DnsProvisioningStep` | Contrat — MI | Non | Adaptateur `dns` |
| 4 | `hosting` | `HostingProvisioningStep` | Contrat — MI | Non | Adaptateur `hosting_panel` |
| 5 | `database` | `DatabaseProvisioningStep` | Contrat — MI | Non | Adaptateur `client_database` |
| 6 | `deploy` | `DeployProvisioningStep` | Contrat — MI | Non | Adaptateur `application_deploy` |
| 7 | `environment` | `EnvironmentProvisioningStep` | Contrat — MI | Non | Adaptateur `environment_configuration` |
| 8 | `dependencies` | `DependenciesProvisioningStep` | Contrat — MI | Non | Adaptateur `dependency_installation` |
| 9 | `build` | `BuildProvisioningStep` | Contrat — MI | Non | Adaptateur `application_build` |
| 10 | `migrate` | `MigrateProvisioningStep` | Contrat — MI | Non | Adaptateur `database_migrate` |
| 11 | `storage` | `StorageProvisioningStep` | Contrat — MI | Non | Adaptateur `storage_link` |
| 12 | `cache` | `CacheProvisioningStep` | Contrat — MI | Non | Adaptateur `cache_warmup` |
| 13 | `admin` | `AdminProvisioningStep` | Contrat — MI | Non | Adaptateur `admin_user_bootstrap` |
| 14 | `modules` | `ModulesProvisioningStep` | Contrat — MI | Non | Adaptateur `installation_modules` |
| 15 | `health` | `HealthProvisioningStep` | Contrat — MI | Non | Adaptateur `health_check` |
| 16 | `mark_deployed` | `MarkDeployedProvisioningStep` | Contrat readiness — MI | Non | Service readiness `deployed` |
| 17 | `mark_verified` | `MarkVerifiedProvisioningStep` | Contrat readiness — MI | Non | Service readiness `verified` |
| 18 | `mark_ready` | `MarkReadyProvisioningStep` | Contrat readiness — MI | Non | Service readiness `ready` |

**Comportement pipeline production (tests locaux) :** `validate` → `succeeded`, puis `reserve` → `manual_intervention_required` — le run **ne** passe **pas** en `succeeded` global.

Les fakes de test restent dans `Tests\Fakes\Provisioning` et ne sont **pas** enregistrés dans le conteneur production.

---

## Contrats d'adaptateurs d'infrastructure — TASK 354

**Objectif :** séparer les steps de provisioning des fournisseurs futurs (OVH, o2switch, Cloudflare, SSH, HTTP, MySQL distant) via des **interfaces** injectées. Aucune implémentation opérationnelle n'est branchée ; aucun appel réseau.

**Chaîne :**

```text
ProvisioningStep (reserve … health)
    ↓ execute()
DelegatingInfrastructureAdapterStep
    ↓ invokeAdapter()
Contrat App\Contracts\Provisioning\Infrastructure\*
    ↓
Implémentation future (hors TASK 354) ou Unavailable* (production par défaut)
    ↓
InfrastructureAdapterResult → InfrastructureAdapterResultMapper → ProvisioningStepResult
```

**Pourquoi :** les steps ne doivent pas importer SDK, clients HTTP, SSH ni credentials. Les tests remplacent les contrats par des fakes dans `tests/Fakes/Provisioning/Infrastructure/`.

**Résultat adaptateur (`InfrastructureAdapterResult`) :** outcomes `succeeded`, `failed`, `manual_intervention_required` ; code stable ; message opérateur sans secret ; `output_summary` / `metadata` filtrés via `ProvisioningContext::isForbiddenSecretKey` (aligné avec `ProvisioningExecutionDiagnostics`).

**Contexte :** `ProvisioningContext` reste inchangé (run, client, installation, subdomain, domain, database_host/name, etc.). Les secrets (mots de passe DB, tokens API, DSN complets) seront gérés par un mécanisme sécurisé **dans** les futures implémentations d'adaptateurs — **pas** dans le contexte du pipeline.

**Readiness (`mark_deployed`, `mark_verified`, `mark_ready`) :** steps `ReadinessContractStep` — **sans** adaptateur infrastructure. Preuves futures attendues avant persistance readiness :

| Step | Preuves attendues (futures) |
|------|-----------------------------|
| `mark_deployed` | Steps `deploy`, `environment`, `dependencies`, `build`, `migrate`, `storage`, `cache` en succès ; métadonnées non secrètes de déploiement |
| `mark_verified` | `health` réussi + contrôles post-déploiement documentés |
| `mark_ready` | `admin`, `modules` réussis + palier readiness métier validé |

Logique de readiness finale **non** implémentée en TASK 354.

**Idempotence / vérification post-op (futures implémentations) :** réservation, DNS, hosting, DB, deploy, env, migrations, storage — chaque adaptateur devra documenter idempotence et contrôle après exécution (propagation DNS, connexion DB sans secret en log, version déployée, etc.).

**Bindings production (`ProvisioningServiceProvider`) :** chaque contrat → classe `Unavailable*` qui retourne `infrastructure_adapter_unavailable` / `manual_intervention_required` sans effet externe.

| Contrat | Steps consommateurs | Implémentation réelle | État |
|---------|---------------------|------------------------|------|
| `CapacityReservationAdapter` | `reserve` | OVH / capacité hébergement | Contrat + Unavailable |
| `DnsRecordAdapter` | `dns` | Cloudflare / DNS | Contrat + `CloudflareDnsAdapter` (non opérationnel — TASK 358) |
| `HostingSpaceAdapter` | `hosting` | o2switch / panel | Contrat + `O2SwitchHostingAdapter` (non opérationnel — TASK 357) |
| `ClientDatabaseAdapter` | `database` | MySQL o2switch / cPanel | Contrat + `O2SwitchDatabaseAdapter` (non opérationnel — TASK 359) |
| `ApplicationDeployAdapter` | `deploy` | Git cPanel / o2switch | Contrat + `O2SwitchDeployAdapter` (non opérationnel — TASK 360) |
| `EnvironmentConfigurationAdapter` | `environment` | `.env` Gestion o2switch | Contrat + `O2SwitchEnvironmentAdapter` (non opérationnel — TASK 361) |
| `DependencyInstallationAdapter` | `dependencies` | Composer/npm o2switch | Contrat + `O2SwitchDependenciesAdapter` (non opérationnel — TASK 362) |
| `ApplicationBuildAdapter` | `build` | Vite/npm o2switch | Contrat + `O2SwitchBuildAdapter` (non opérationnel — TASK 363) |
| `DatabaseMigrationAdapter` | `migrate` | SSH / artisan distant | Contrat + `O2SwitchMigrateAdapter` (non opérationnel — TASK 364) |
| `StorageSetupAdapter` | `storage` | SSH / liens storage | Contrat + `O2SwitchStorageAdapter` (non opérationnel — TASK 365) |
| `CacheWarmupAdapter` | `cache` | SSH / cache distant | Contrat + `O2SwitchCacheAdapter` (non opérationnel — TASK 366) |
| `AdminBootstrapAdapter` | `admin` | API Gestion / seed | Contrat + `O2SwitchAdminAdapter` (non opérationnel — TASK 367) |
| `InstallationModulesAdapter` | `modules` | API Gestion / modules | Contrat + `O2SwitchModulesAdapter` (non opérationnel — TASK 368) |
| `ApplicationHealthAdapter` | `health` | HTTP health check cible | Contrat + `O2SwitchHealthAdapter` (non opérationnel — TASK 369) |

**Comportement sans implémentation réelle :** identique à TASK 353 — pipeline s'arrête sur `reserve` en `manual_intervention_required` ; pas de faux `succeeded` d'infrastructure.

**Tests :** `ProvisioningInfrastructureContractTest`, fakes locaux, tests d'architecture (pas de Guzzle/OVH/Cloudflare/SSH dans `Steps/`).

**Readiness (TASK 354+) :** contrat `InstallationReadinessPersistenceAdapter` ; production → `UnavailableInstallationReadinessPersistenceAdapter` ; steps `mark_*` injectés via `ReadinessContractStep`.

---

## Adapters locaux de validation — TASK 355

**Pourquoi :** valider le parcours complet des 18 steps (jusqu'à run `succeeded`) dans un environnement **contrôlé**, sans OVH/o2switch/Cloudflare/SSH/API distante.

**Simulation ≠ infrastructure réelle :** un `succeeded` local signifie qu'une opération a été **enregistrée dans un store mémoire** (`LocalControlledInfrastructureState`) avec `execution_mode = local_test` et `simulated = true` — jamais qu'un DNS, hébergement ou déploiement réel a eu lieu.

**Schéma (aujourd'hui — tests) :**

```text
Step → Contrat adaptateur → Local*Adapter → LocalControlledInfrastructureState → InfrastructureAdapterResult
```

**Schéma (futur — production réelle) :**

```text
Step → Contrat adaptateur → Fournisseur réel (o2switch, Cloudflare, …) → InfrastructureAdapterResult
```

**Injection :** `LocalProvisioningInfrastructureBundle::registerInApplication()` + trait test `BindsLocalProvisioningInfrastructure` — **jamais** enregistré dans `ProvisioningServiceProvider`. Les bindings production restent `Unavailable*`.

**Store local :** mémoire uniquement (réservations, opérations simulées par clé, marqueurs readiness) — pas de table métier, pas de migration.

**Idempotence locale (contrat comportemental) :** second appel sur la même installation + même opération → `succeeded` avec `idempotent_replay: true` ; l'état simulé n'est pas dupliqué.

**Échec / MI :** `forcedNextResult` sur un adaptateur local ; le pipeline s'arrête ; run terminal `failed` ou `manual_intervention_required` — jamais promu en `succeeded`.

**Tests principaux :** `ProductionProvisioningFullPipelineTest` (succès 18 steps, échecs ciblés, MI, retry, concurrence claim), `LocalInfrastructureArchitectureTest`.

| Adaptateur local | Contrat | Step(s) |
|------------------|---------|---------|
| `LocalCapacityReservationAdapter` | `CapacityReservationAdapter` | `reserve` |
| `LocalDnsRecordAdapter` | `DnsRecordAdapter` | `dns` |
| … | … | … |
| `LocalInstallationReadinessPersistenceAdapter` | `InstallationReadinessPersistenceAdapter` | `mark_deployed`, `mark_verified`, `mark_ready` |

**Ne jamais activer en production :** les classes sous `Infrastructure/Local/` sont explicites `local_test` ; absence de binding production garantie par test d'architecture.

---

## Exécution manuelle administrateur — TASK 356

**Flux :**

```text
Admin (Show run)
    → POST provisioning-runs/{run}/execute
    → ProvisioningRunController::execute()
    → (vérif. execute_actions / orchestrateur)
    → ProvisioningPipeline::runPersisted()
    → ProvisioningPersistedRunOrchestrator
    → Steps + adaptateurs (Unavailable* en prod)
    → ProvisioningPipelineResult
    → redirect Show + flash (success / error)
```

**Autorisation :** middleware `auth` + `can:accessControlCenter` (pas de policy provisioning dédiée).

**Validation :** `ProvisioningRunAdminPresentation::describeExecuteAvailability()` — run `pending`, `finished_at` null, installation/client valides, registre non vide. Le contrôleur refuse sans appeler le pipeline si `can_execute` est false. L'éligibilité fine (terminal, claim) reste dans `ProvisioningPersistedRunOrchestrator` (`lockForUpdate`, `pending → running`).

**Concurrence :** une seule stratégie — claim atomique TASK 352 ; le contrôleur n'ajoute pas de verrou.

**Exceptions :** `ProvisioningStepExecutionException`, `InvalidProvisioningRunTransition`, `ProvisioningExecutableRegistryEmptyException` → redirect Show + flash sans secret ; run jamais laissé `running` silencieux.

**UI :** props `execute_actions` (`can_execute`, `unavailable_reason`, `button_label`, `store_url`) ; bouton avec confirmation et état loading sur `ProvisioningRuns/Show.vue`.

**Production (bindings `Unavailable*`) :** l'admin **peut** lancer l'orchestration, mais le run s'arrête proprement sur `reserve` en `manual_intervention_required` — **pas** de succès d'infrastructure réelle.

**Tests locaux (bundle TASK 355) :** injection explicite en test → parcours 18 steps possible via POST execute.

`execute` déclenche l'orchestration interne ; cela **ne signifie pas** qu'une infrastructure externe (DNS, hébergement, déploiement réel) est provisionnée.

---

## Adapter Hosting o2switch — TASK 357

**Contrat consommé :** `HostingSpaceAdapter` → step `hosting` (`HostingProvisioningStep`).

**Responsabilité limitée :** vérifier/préparer l'espace d'hébergement client, contrôler la configurabilité, retourner des informations **non secrètes** (document root logique, état dry-run). **Hors scope :** DNS, base client, déploiement Git, modules, abonnements.

**Protocole réel :** aucune API o2switch officielle n'est documentée dans le dépôt pour automatiser la création d'hébergement. Port `O2SwitchHostingGateway` + implémentation `NullO2SwitchHostingGateway` (`o2switch_hosting_protocol_pending`) jusqu'à validation d'un accès (panel API, SSH runbook, autre).

**Configuration (`config/provisioning.php` + `.env`) :**

| Clé | Rôle | Secret |
|-----|------|--------|
| `PROVISIONING_O2SWITCH_HOSTING_ENABLED` | Feature flag ( défaut `false` ) | Non |
| `PROVISIONING_O2SWITCH_HOSTING_DRY_RUN` | Simulation sans réseau | Non |
| `PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID` | Référence compte o2switch | Non |
| `PROVISIONING_O2SWITCH_ENVIRONMENT` | Environnement logique | Non |
| `PROVISIONING_O2SWITCH_API_TOKEN` | Futur token API | Oui (.env) |
| `PROVISIONING_O2SWITCH_SSH_USERNAME` | Futur accès SSH | Oui (.env) |

**Activation :** `ENABLED=true` + identifiant logique requis pour sortir de `not_configured`. **Aucun appel réseau** tant que le gateway reste `Null*` et qu'aucun client HTTP n'est branché.

**Dry-run :** `DRY_RUN=true` → `succeeded` avec `execution_mode=dry_run` et `simulated=true` — **ne prétend pas** à un hébergement réel créé.

**Production par défaut :** flag `false` → `manual_intervention_required` (`o2switch_hosting_disabled`). Le pipeline s'arrête toujours sur `reserve` tant que les autres adaptateurs restent `Unavailable*`.

**Étapes avant premier appel réel :** protocole validé, gateway implémenté, secrets en vault/.env, tests HTTP fake, revue sécurité, activation explicite hors dry-run.

**Non opérationnel :** l'adapter **n'est pas** présenté comme capable de créer un hébergement o2switch en TASK 357.

---

## Adapter DNS Cloudflare — TASK 358

**Contrat :** `DnsRecordAdapter` → step `dns` (`DnsProvisioningStep`).

**Responsabilité :** DNS uniquement (zone, enregistrement pour `subdomain` / `domain` du contexte). **Hors scope :** hosting o2switch, base, deploy, Gestion, abonnements.

**Protocole retenu (documenté) :** [Cloudflare API v4](https://developers.cloudflare.com/api/) — authentification **Bearer token** (`Authorization: Bearer`), endpoints `GET /zones`, `GET/POST /zones/{zone_id}/dns_records`. Implémentation HTTP : `HttpCloudflareDnsGateway` (**non enregistrée** en production).

**Chaîne :** `DnsProvisioningStep` → `CloudflareDnsAdapter` → `CloudflareDnsGateway` → (`NullCloudflareDnsGateway` par défaut).

**Configuration (`config/provisioning.php`) :**

| Variable | Rôle |
|----------|------|
| `PROVISIONING_CLOUDFLARE_DNS_ENABLED` | Feature flag ( défaut `false` ) |
| `PROVISIONING_CLOUDFLARE_DNS_DRY_RUN` | Simulation sans réseau |
| `PROVISIONING_CLOUDFLARE_ZONE_NAME` / `ZONE_ID` | Zone mkd-pro.com (référence non secrète) |
| `PROVISIONING_CLOUDFLARE_RECORD_TYPE` | ex. `CNAME` |
| `PROVISIONING_CLOUDFLARE_RECORD_CONTENT` | Cible enregistrement (requise pour création réelle) |
| `PROVISIONING_CLOUDFLARE_API_TOKEN` | Secret (.env uniquement) |

**Comportements :**

- Flag off → `cloudflare_dns_disabled` (MI).
- Flag on, config incomplète → `cloudflare_dns_not_configured`.
- Dry-run → `succeeded` avec `execution_mode=dry_run`, `simulated=true`.
- Live + `Null*` gateway → `cloudflare_dns_protocol_pending`.
- Idempotence (HTTP) : enregistrement existant compatible → `idempotent_replay` ; conflit de contenu → `cloudflare_dns_conflict`.

**Production :** aucun appel Cloudflare par défaut ; pipeline s'arrête toujours sur **reserve** tant que les autres adaptateurs précédents restent non opérationnels.

**Limites :** pas de modification DNS réelle en TASK 358 ; activation future = brancher `HttpCloudflareDnsGateway` + revue sécurité + token vault.

---

## Adapter Database o2switch — TASK 359

**Contrat :** `ClientDatabaseAdapter` → step `database` (`DatabaseProvisioningStep`).

**Responsabilité :** création / vérification d'une base MySQL dédiée à l'installation (nom, hôte logique, idempotence). **Hors scope :** DNS, hébergement, déploiement, migrations applicatives Gestion, admin, modules, abonnements.

**Protocole retenu :** o2switch mutualisé s'appuie sur **cPanel** ; pas d'API o2switch propriétaire documentée pour MySQL. Mécanisme officiel visé : **cPanel UAPI** module `Mysql` — `list_databases`, `create_database` sur `https://{cpanel_host}:2083/execute/Mysql/...` avec en-tête `Authorization: cpanel {username}:{api_token}` ([documentation cPanel API](https://api.docs.cpanel.net/)). Implémentation HTTP : `CpanelUapiO2SwitchDatabaseGateway` (**non enregistrée** en production).

**Chaîne :** `DatabaseProvisioningStep` → `O2SwitchDatabaseAdapter` → `O2SwitchDatabaseGateway` → (`NullO2SwitchDatabaseGateway` par défaut → `o2switch_database_protocol_pending`).

**Nommage :** `O2SwitchDatabaseNaming` — identifiant MySQL (max **64 octets**, règle MySQL), préfixe compte cPanel (`PROVISIONING_O2SWITCH_DATABASE_NAME_PREFIX`), ou `installation.database_name` si déjà renseigné et compatible avec le préfixe ; sinon suffixe déterministe `gest_{subdomain}_{installation_id}`.

**Configuration (`config/provisioning.php`) :**

| Variable | Rôle |
|----------|------|
| `PROVISIONING_O2SWITCH_DATABASE_ENABLED` | Feature flag ( défaut `false` ) |
| `PROVISIONING_O2SWITCH_DATABASE_DRY_RUN` | Simulation sans réseau |
| `PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID` | Référence compte (partagée hosting) |
| `PROVISIONING_O2SWITCH_DATABASE_NAME_PREFIX` | Préfixe cPanel (ex. `cpuser_`) |
| `PROVISIONING_O2SWITCH_MYSQL_HOST_LOGICAL` | Hôte logique retourné (ex. `localhost`) |
| `PROVISIONING_O2SWITCH_CPANEL_HOST` | Hôte cPanel pour UAPI (live) |
| `PROVISIONING_O2SWITCH_API_TOKEN` | Token API cPanel (secret) |
| `PROVISIONING_O2SWITCH_CPANEL_USERNAME` | Utilisateur cPanel UAPI (secret) |

**Comportements :**

- Flag off → `o2switch_database_disabled` (MI).
- Flag on, config incomplète → `o2switch_database_not_configured`.
- Dry-run → `succeeded` avec `execution_mode=dry_run`, `simulated=true` — **aucune base créée**.
- Live + `Null*` gateway → `o2switch_database_protocol_pending`.
- Idempotence (UAPI fake) : base existante conforme → `idempotent_replay` ; `database_name` attendu absent → `o2switch_database_incompatible`.

**Limites TASK 359 :** pas de création d'utilisateur MySQL, pas de `set_privileges`, pas de persistance de mot de passe — à cadrer avant activation réelle. Pas de base réelle tant que le gateway HTTP n'est pas enregistré explicitement.

**Production :** aucun appel o2switch/cPanel par défaut ; pipeline s'arrête toujours sur **reserve** (`Unavailable*` / flags off).

---

## Adapter Deploy o2switch — TASK 360

**Contrat :** `ApplicationDeployAdapter` → step `deploy` (`DeployProvisioningStep`).

**Responsabilité :** récupérer / déployer le code **Gestion** sur l'hébergement (répertoire applicatif, révision Git, idempotence). **Hors scope :** DNS, hosting, base, `.env`, Composer, npm, migrations, admin, modules, abonnements.

**Dépôt Gestion inspecté (lecture seule) :** [https://github.com/mkddanfakha/Gestion.git](https://github.com/mkddanfakha/Gestion.git) — branche `main` ; Laravel 12 / PHP ^8.2 ; `composer.json` + `package.json` ; build Vite (`npm run build`) ; document root Laravel `public/`. Aucune modification du dépôt distant.

**Mécanismes étudiés :** SSH + `git`, SFTP/FTP, API o2switch propriétaire (non documentée), **cPanel Git Version Control** via **UAPI** (aligné TASK 359). SFTP/SSH restent possibles en runbook manuel mais ne sont pas branchés ici.

**Protocole retenu :** cPanel UAPI module `Git` — `retrieve`, `create`, `update` sur `https://{cpanel_host}:2083/execute/Git/...` (auth `cpanel {username}:{api_token}`). Implémentation : `CpanelGitUapiO2SwitchDeployGateway` (**non enregistrée** en production). Déploiement par commit SHA complet via branche/tag reste à affiner lors de l'activation (limites UAPI branche vs commit).

**Chaîne :** `DeployProvisioningStep` → `O2SwitchDeployAdapter` → `O2SwitchDeployGateway` → (`NullO2SwitchDeployGateway` → `o2switch_deploy_protocol_pending`).

**Version / révision :** `O2SwitchDeployRevision` — priorité `ProvisioningRun.target_commit` (SHA 40), puis `target_version`, puis `PROVISIONING_GESTION_GIT_DEFAULT_REF` (**vide par défaut** — pas de `main` implicite).

**Chemins :** `O2SwitchDeployPathResolver` — base absolue `PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE` + segment `mkd_gestion/installation_{id}` ou `externalReferences.deploy_relative_path` validé (pas de `..`, pas de chemin dérivé du seul subdomain).

**Configuration :** flags `PROVISIONING_O2SWITCH_DEPLOY_*`, `PROVISIONING_GESTION_GIT_REPOSITORY_URL`, secrets cPanel réutilisés (TASK 359).

**Comportements :** flag off → `o2switch_deploy_disabled` ; config incomplète → `o2switch_deploy_not_configured` ; chemin invalide → `o2switch_deploy_invalid_path` ; dry-run → `succeeded` simulé ; live + Null → `o2switch_deploy_protocol_pending`.

**Idempotence (UAPI fake) :** même référence déployée → `idempotent_replay` ; référence différente → `update` ; dépôt illisible → `o2switch_deploy_inconsistent`. Pas de `rm -rf` distant.

**Limites TASK 360 :** aucun déploiement réel ; pas de clé SSH en dépôt ; création utilisateur Git privé / token GitHub à cadrer avant production.

---

## Adapter Environment o2switch — TASK 361

**Contrat :** `EnvironmentConfigurationAdapter` → step `environment` (`EnvironmentProvisioningStep`).

**Responsabilité :** préparer / mettre à jour le `.env` **Gestion** sur l'hébergement client (variables Laravel, idempotence, jamais d'exposition du fichier ou des secrets dans les résultats). **Hors scope :** DNS, hosting, base, deploy code, Composer, npm, migrations, admin, modules.

**Dépôt Gestion (lecture seule) :** `.env.example` public — Laravel 12, PHP ^8.2, MySQL commenté en dev (`sqlite` par défaut en exemple) ; production mutualisée = `mysql` + `APP_URL` HTTPS ; session/queue/cache `database` ; Vite build requis en amont (steps `dependencies` / `build`).

**Variables gérées au provisioning (whitelist) :** `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `DB_*` (connection/host/port/database/username/password), `LOG_LEVEL`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE`, `FILESYSTEM_DISK`, `BROADCAST_CONNECTION`, `MAIL_MAILER`. Autres clés Gestion (AWS, backup, Redis, etc.) **non** injectées automatiquement — restent défaut Laravel ou futur runbook.

**Protocole étudié :** SSH/SFTP (runbook o2switch), Git seul (TASK 360), cPanel **Fileman UAPI** (`get_file_content`, `save_file_content`) — retenu pour cohérence cPanel UAPI (TASK 359). Implémentation : `CpanelFileUapiO2SwitchEnvironmentGateway` (**non enregistrée** en production).

**Chaîne :** `EnvironmentProvisioningStep` → `O2SwitchEnvironmentAdapter` → `O2SwitchGestionEnvBuilder` → `O2SwitchEnvironmentGateway` → (`NullO2SwitchEnvironmentGateway` → `o2switch_environment_protocol_pending`).

**APP_KEY :** sentinel `base64:PROVISIONING_APP_KEY_PENDING` dans le contenu futur ; métadonnée `app_key_state=pending_generation` — **aucune** génération réelle en TASK 361.

**DB :** `database_name` / `database_host` depuis `Installation` ; `database_username` via `externalReferences.database_username` ; mot de passe = sentinel vault (`PROVISIONING_DB_PASSWORD_PENDING`) — jamais dans metadata (`db_auth_state=pending_vault`). Pas de credentials Control Center.

**APP_URL :** `O2SwitchGestionAppUrlResolver` — `installation.domain` validé → `https://{domain}` ; sinon `https://{subdomain}.{PROVISIONING_GESTION_APP_BASE_DOMAIN}`.

**Chemins :** `{deployment_root}/mkd_gestion/installation_{id}/.env` ou `externalReferences.deploy_relative_path` (même resolver que deploy).

**Configuration :** `PROVISIONING_O2SWITCH_ENVIRONMENT_*`, `PROVISIONING_GESTION_APP_BASE_DOMAIN`, `PROVISIONING_GESTION_APPLICATION_ENV`, `PROVISIONING_GESTION_APPLICATION_DEBUG`.

**Comportements :** flag off → `o2switch_environment_disabled` ; incomplet → `o2switch_environment_not_configured` ; clé libre → `o2switch_environment_forbidden_key` ; APP_URL invalide → `o2switch_environment_invalid_app_url` ; dry-run → `succeeded` simulé ; live + Null → `o2switch_environment_protocol_pending`.

**Idempotence :** empreinte non sensible (`configuration_fingerprint`) ; fichier identique → `idempotent_replay` ; divergence sur APP_KEY/DB_PASSWORD réels → `o2switch_environment_sensitive_conflict`.

**Limites :** aucun `.env` réel écrit ; pas de `php artisan key:generate` distant ; activation future = vault mots de passe + gateway Fileman + revue sécurité.

---

## Adapter Dependencies o2switch — TASK 362

**Contrat :** `DependencyInstallationAdapter` → step `dependencies` (`DependenciesProvisioningStep`).

**Responsabilité :** installer / valider les dépendances **runtime et build** de Gestion sur le répertoire déployé. **Hors scope :** deploy Git, `.env`, migrations, build Vite (step `build`), admin, modules.

**Gestion (lecture seule GitHub) :**

| Couche | Mécanisme retenu | Lockfile |
|--------|------------------|----------|
| PHP | `composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev` | `composer.lock` |
| Node | `npm ci` (package manager npm, lock présent) | `package-lock.json` |

PHP **^8.2** (`composer.json`). Node requis avant l'étape `build` (Vite). Pas de `composer update` / `npm install` en provisioning.

**Protocole étudié :** SSH (runbook o2switch), terminal cPanel — **aucun** endpoint d'exécution de commande documenté de façon exploitable dans le dépôt. **Retenu :** port `O2SwitchDependenciesGateway` + `NullO2SwitchDependenciesGateway` → `o2switch_dependencies_protocol_pending`. Activation future = SSH/runbook validé (hors TASK 362).

**Chaîne :** `DependenciesProvisioningStep` → `O2SwitchDependenciesAdapter` → `O2SwitchDependenciesCommandPlanResolver` → gateway.

**Configuration :** `PROVISIONING_O2SWITCH_DEPENDENCIES_*`, racine partagée `PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE`, contraintes `config/provisioning.php` → `gestion.php_version_minimum`, `node_dependencies_required`.

**Comportements :** flag off → `o2switch_dependencies_disabled` ; incomplet → `o2switch_dependencies_not_configured` ; PHP incompatible (`externalReferences.reported_php_version`) → `o2switch_dependencies_runtime_incompatible` ; dry-run → `succeeded` simulé (`operations_planned`: `composer_install`, `npm_ci`).

**Idempotence (future) :** empreinte lockfiles (`lock_fingerprint`) ; dépendances conformes → `idempotent_replay` ; pas de réparation via `update`/`npm install`.

**Sécurité :** argv fixes sans secret ; résultats = libellés d'opération, jamais commandes complètes ni credentials.

**Limites TASK 362 :** aucune commande Composer/npm réelle sur o2switch.

---

## Adapter Build o2switch — TASK 363

**Contrat :** `ApplicationBuildAdapter` → step `build` (`BuildProvisioningStep`).

**Responsabilité :** exécuter le **build frontend Vite** de Gestion après deploy, environment et dependencies (TASK 362). **Hors scope :** `npm ci`, Composer, migrations, admin, modules.

**Commande identifiée (Gestion `package.json`) :** script `"build": "vite build"` → **`npm run build`** à la racine du dépôt déployé. Pas de `npm run dev`, pas de `build:ssr` en provisioning standard.

**Prérequis :** `node_modules` via `npm ci` (step dependencies) ; `.env` Gestion distant (step environment) avec variables **VITE_* publiques** — ex. `VITE_APP_NAME` (`.env.example` Gestion). Aucune injection de secrets (APP_KEY, DB_PASSWORD, tokens) dans le build.

**Artefacts attendus :** répertoire `public/build/`, fichier **`public/build/manifest.json`** (Vite Laravel).

**Protocole :** SSH/runbook o2switch — pas d'API d'exécution documentée dans le dépôt → `NullO2SwitchBuildGateway` → `o2switch_build_protocol_pending`.

**Configuration :** `PROVISIONING_O2SWITCH_BUILD_*`, empreinte `build_fingerprint` (lock + script + cible manifest + révision run).

**Comportements :** flag off → `o2switch_build_disabled` ; incomplet → `o2switch_build_not_configured` ; VITE publiques manquantes (live) → `o2switch_build_vite_env_missing` via `externalReferences.vite_public_env_keys_present` ; dry-run → `succeeded` simulé.

**Idempotence (future) :** manifest + fingerprint cohérents → `idempotent_replay` ; pas de suppression aveugle de `public/build`.

**Limites TASK 363 :** aucun `npm run build` réel sur o2switch.

---

## Adapter Migrate o2switch — TASK 364

**Contrat :** `DatabaseMigrationAdapter` → step `migrate` (`MigrateProvisioningStep`).

**Responsabilité :** exécuter **`php artisan migrate --force`** sur le dépôt Gestion déployé, en ciblant **uniquement** la base MySQL liée à l'`Installation` du run (champs `database_name` / `database_host`). **Hors scope :** seed, modules, admin, `migrate:fresh` / `refresh` / `reset`, `db:wipe`.

**Commande identifiée (Gestion) :** script Composer `setup` et production Laravel 12 → **`php artisan migrate --force`** (confirmation `--force` requise en production). État futur via table `migrations` / `php artisan migrate:status` (non exécuté en TASK 364).

**Prérequis :** step `environment` (`.env` distant avec `DB_*` — secrets jamais lus ni loggés par l'adapter) ; identifiant MySQL non secret via `externalReferences.database_username` ou `gestion_environment_prepared`.

**Chaîne installation → base :** `installation_id` → `Installation.database_name` + `Installation.database_host` → `migration_target_fingerprint`. Aucune surcharge via références externes divergentes.

### Protection contre la migration de la mauvaise base

- Cible **uniquement** depuis l'`Installation` persistée du contexte.
- Rejet si `database_name` / `database_host` manquants, run clos (`succeeded` / `cancelled`), ou nom présent dans `provisioning.security.forbidden_migration_database_names` (base Control Center).
- Rejet si `externalReferences` tente un autre `installation_id` ou un `database_name` / `database_host` incohérent.
- Jamais de lecture de `config/database.php` Control Center dans l'adapter pour choisir une cible ; liste d'interdiction alimentée au niveau config uniquement.
- Le gateway ne reçoit pas de paramètre permettant de choisir une autre installation.

**Protocole :** SSH / terminal cPanel — non branché → `NullO2SwitchMigrateGateway` → `o2switch_migrate_protocol_pending`.

**Configuration :** `PROVISIONING_O2SWITCH_MIGRATE_*`, empreinte `migration_fingerprint`.

**Comportements :** flag off → `o2switch_migrate_disabled` ; incomplet → `o2switch_migrate_not_configured` ; cible invalide → `o2switch_migrate_database_target_invalid` ; Environment non confirmé (live) → `o2switch_migrate_environment_not_ready` ; dry-run → `succeeded` simulé sans connexion distante ni migration.

**Idempotence (future) :** table `migrations` Laravel ; rejeu sur base à jour → succès / `idempotent_replay` ; échec partiel ou schéma incompatible → intervention manuelle — **jamais** de commande destructive pour « remettre à zéro ».

**Sécurité :** argv fixes sans secret ; credentials distants via environnement Gestion uniquement ; résultats = hôte/nom de base autorisés, fingerprint, état migration — jamais mot de passe, `APP_KEY`, tokens.

**Limites TASK 364 :** aucune migration réelle sur o2switch ni sur une base Gestion / Control Center.

---

## Adapter Storage o2switch — TASK 365

**Contrat :** `StorageSetupAdapter` → step `storage` (`StorageProvisioningStep`).

**Responsabilité :** préparer le **filesystem Laravel Gestion** après deploy / environment / dependencies / build / migrate : répertoires inscriptibles, lien `public/storage` si requis, **sans** cache Laravel (TASK 366).

**Filesystem Gestion (inspection) :**
- Disque par défaut : `local` (`FILESYSTEM_DISK`) → `storage/app/private` (pièces jointes, previews, restore temp).
- Disques applicatifs : `public` (`storage/app/public`), **`media`** (racine `public/storage` — Spatie Media Library, logos société).
- Disque **`s3`** : backups offsite (`BACKUP_DISKS`) — **identifié seulement** ; variables AWS/R2 requises ; **non configuré** par provisioning.

**`storage:link` :** **oui** — `config/filesystems.php` définit `public/storage` → `storage/app/public` ; le disque `media` lit/écrit via `public/storage`. Commande planifiée : **`php artisan storage:link`**. Idempotence future : lien correct → replay ; lien incompatible ou répertoire réel → `manual_intervention_required` ; jamais d’écrasement silencieux.

**Répertoires inscriptibles (relatifs racine app) :** `storage/app`, `storage/app/private`, `storage/app/public`, `storage/framework/*`, `storage/logs`, `bootstrap/cache`.

**Permissions :** politique `platform_user_writable_no_world_chmod` — pas de `chmod 777` ; dépendance utilisateur système o2switch documentée.

### Protection des chemins entre installations

- Racine = résolution deploy (`deployment_root_base` + segment `mkd_gestion/installation_{id}`).
- Rejet si segment non lié à `installation_id`, refs externes divergentes, ou chemin sous `provisioning.security.forbidden_storage_absolute_path_prefixes` (Control Center `base_path()`).

**Protocole :** SSH / terminal — `NullO2SwitchStorageGateway` → `o2switch_storage_protocol_pending`.

**Configuration :** `PROVISIONING_O2SWITCH_STORAGE_*`, empreinte `storage_fingerprint`.

**Comportements :** flag off → `o2switch_storage_disabled` ; incomplet → `o2switch_storage_not_configured` ; chemin invalide → `o2switch_storage_deployment_path_invalid` ; prérequis live → `gestion_deploy_filesystem_ready` ; dry-run → `succeeded` simulé.

**Limites TASK 365 :** aucun `storage:link`, chmod, mkdir ou symlink réel sur une installation.

---

## Adapter Cache o2switch — TASK 366

**Contrat :** `CacheWarmupAdapter` → step `cache` (`CacheProvisioningStep`).

**Responsabilité :** préchauffer le **cache Laravel production** Gestion après migrate + storage : `.env` distant, filesystem `bootstrap/cache` et `storage/framework/cache` déjà préparés.

**Store réel (Gestion `.env.example`) :** `CACHE_STORE=database` (défaut `config/cache.php`) ; `SESSION_DRIVER=database` ; `QUEUE_CONNECTION=database`. Tables `cache` / `cache_locks` via migration **`0001_01_01_000001_create_cache_table.php`** (step migrate — non rejouée ici).

### Commandes de cache autorisées

Ordre planifié :

1. `php artisan config:cache`
2. `php artisan view:cache`
3. `php artisan event:cache`

**Exclues (justification) :**

| Commande | Raison |
|----------|--------|
| `route:cache` | Nombreuses closures dans `routes/web.php` (routes `/dev/*`) |
| `optimize` | Agrégat masquant des opérations à contrôler séparément |
| `optimize:clear` / `cache:clear` | Potentiellement destructif / hors provisioning neuf |
| `migrate:*` / `db:wipe` | Hors scope cache |

**Prérequis live :** `gestion_cache_warmup_ready` ou (`gestion_storage_step_ready` + `gestion_environment_prepared`) ; si store `database` → `gestion_migrate_step_ready` ou `gestion_cache_database_tables_ready`.

**Protocole :** SSH / terminal — `NullO2SwitchCacheGateway` → `o2switch_cache_protocol_pending`.

**Configuration :** `PROVISIONING_O2SWITCH_CACHE_*`, empreinte `cache_fingerprint`.

**Idempotence (future) :** caches cohérents → `idempotent_replay` ; `route:cache` incompatible → `manual_intervention_required` ; pas de `optimize:clear` automatique.

**Limites TASK 366 :** aucune commande Artisan cache réelle sur o2switch.

---

## Adapter Admin — TASK 367

**Contrat :** `AdminBootstrapAdapter::bootstrapAdmin()` → step `admin` (`AdminProvisioningStep`).

### Inspection Gestion (résumé)

| Élément | Constat |
|---------|---------|
| Auth | Laravel Fortify (login, reset password, 2FA) — **inscription publique désactivée** |
| Modèle | `App\Models\User` — champs `name`, `email` (unique), `password`, `role`, `is_active` |
| Admin | `users.role === 'admin'` (`User::ROLE_ADMIN`) — **pas Spatie** |
| Permissions | Modèle `Permission` + pivot `user_permissions` ; catalogue `PermissionSeeder` |
| Création | UI admin `UserController@store` (session authentifiée) ou procédure manuelle |
| Artisan | `user:set-role`, `user:check-role` — **utilisateur déjà existant** |
| Protection | `AdminProtectionService` — dernier admin actif non démotivable |

**Identité idempotente :** email normalisé (unique DB).

**Données obligatoires côté Gestion :** name, email, password (hash), role, is_active ; permissions optionnelles.

**Automatisation provisioning :** aucune commande Artisan sûre de **création** du premier admin → plan Artisan **vide** ; identifiants **jamais** dans metadata (canal sécurisé `gestion_admin_secure_delivery_ready` en live).

**Opérations logiques planifiées (dry-run) :** vérification admin existant, création via canal applicatif sécurisé, rôle admin, permissions catalogue.

**Protocole :** `NullO2SwitchAdminGateway` → `o2switch_admin_protocol_pending`.

**Configuration :** `PROVISIONING_O2SWITCH_ADMIN_*` ; refs `gestion_admin_bootstrap_email`, `gestion_admin_bootstrap_name`.

**Limites TASK 367 :** aucun utilisateur réel créé ; repository Gestion non modifié.

---

## Adapter Modules — TASK 368

**Contrat :** `InstallationModulesAdapter::configureModules()` → step `modules` (`ModulesProvisioningStep`).

**Inspection Gestion (lecture seule) :**

| Question | Réponse |
|----------|---------|
| Table `modules` / liaison installation | **Non** |
| Feature flags produit | **Non** |
| Permissions = modules activables | **Non** — `PermissionCatalog::modules()` = domaines RBAC (regroupement UI) |
| Configuration par entreprise pour toggles | **Non** identifié |
| Abonnement / licence modules | **Non** dans Gestion |
| Admin activer/désactiver fonctionnalités | **RBAC** (`user_permissions`) + UI admin ; pas de toggle module distant |
| Package code | `NotificationCenter` — toujours enregistré via `bootstrap/providers.php` (classe **A**) |

**Classifications :**

- **A** — fonctionnalité native / package déployé (Notification Center).
- **B** — domaines RBAC (`backups`, `sales`, …) — contrôlés par permissions, pas par « module produit ».
- **C** — définitions permissions synchronisables (`rbac:sync-permission-catalog`).
- **D** — aucun module réellement activable/désactivable à distance identifié.
- **E/F** — attribution permissions et création admin : intervention manuelle / UI.

**Catalogue technique Control Center :** `config('provisioning.gestion.modules_catalog')` — ne duplique pas le catalogue commercial CC (`Module` / `InstallationModule`).

**Sélection modules provisioning :** uniquement `ProvisioningContext.externalReferences['gestion_modules_requested']` (liste d’ids catalogue). Absence = plan vide + `catalog_decision_required` en dry-run.

**Mécanisme d’activation distant :** pas de protocole o2switch ; sync RBAC live nécessite confirmation phrase (`rbac:sync-permission-catalog --confirm=…`) → `modules_provisioning_artisan_steps` **vide** en production.

**Dry-run :** `PROVISIONING_O2SWITCH_MODULES_ENABLED=true` + config compte/racine + `DRY_RUN=true` → `succeeded`, `execution_mode=dry_run`, plan Artisan `--status` / `--dry-run` si modules activables demandés.

**Production par défaut :** flag `false` → `o2switch_modules_disabled` ; gateway `NullO2SwitchModulesGateway` → `o2switch_modules_protocol_pending` si live atteint.

**Idempotence :** replay même empreinte plan → `idempotent_replay` (fake gateway / futur protocole) ; pas de transaction globale distante.

**Échec partiel :** modules indépendants non garantis — traiter module par module côté futur gateway ; sync RBAC Gestion = transaction locale (commande existante).

**Sécurité :** metadata sans secrets ; allowlist Artisan ; interdits migrate:fresh, db:wipe, cache:clear, eval, shell libre.

**Limites TASK 368 :** Gestion non modifié ; aucune installation réelle ; décision catalogue CC↔Gestion encore requise pour lier `InstallationModule` commercial au provisioning.

---

## Adapter Health — TASK 369

**Contrat :** `ApplicationHealthAdapter::checkHealth()` → step `health` (`HealthProvisioningStep`).

**Inspection Gestion :**

| Contrôle | Source | Automatisable à distance |
|----------|--------|---------------------------|
| `/up` (Laravel 12 health route) | `bootstrap/app.php` `health: '/up'` | Oui (HTTP, sans auth) — **A/B/D** |
| `/login` reachable | `routes/web.php` + Fortify | Plan HTTP — **I** (200/302, pas d’auth) |
| `migrate:status` | Artisan Laravel | Oui (SSH futur) — **E** |
| `rbac:sync-permission-catalog --status` | Gestion | Oui (lecture seule) — **K** readiness |
| `public/build/manifest.json` | Vite build | Fichier relatif — **H** |
| `public/storage` | storage:link | Fichier relatif — **G** |
| Notification `/api/notifications/settings/health` | Module NotificationCenter | **Non** pour provisioning (auth admin) |

**Health vs readiness :**

- **Health** (`scope=health`) : application, DB (via `/up`), migrations, storage, cache/env refs, build, session/queue refs.
- **Readiness** (`scope=readiness`) : admin bootstrap ref, catalogue RBAC status — remise client, pas seulement HTTP 200.

**Lien `InstallationReadinessPersistenceAdapter` :** décision **A + D** — l’adapter Health produit un **diagnostic technique** uniquement. Les steps `mark_deployed`, `mark_verified`, `mark_ready` restent sur `InstallationReadinessPersistenceAdapter` (Unavailable en prod) ; le health **n’écrit pas** sur `Installation` et **n’appelle pas** readiness directement. Un futur orchestrateur pourra utiliser le résultat health comme **entrée** de `mark_verified` / `mark_ready`, sans fusion des responsabilités.

**Ordre déterministe :** définitions triées par `order` dans `config('provisioning.gestion.health_check_definitions')` avec `depends_on` explicites.

**Protocole :** `NullO2SwitchHealthGateway` → `o2switch_health_protocol_pending`.

**Dry-run :** `PROVISIONING_O2SWITCH_HEALTH_*` ; retourne `checks_planned` sans requête réelle.

**Sécurité :** pas de `.env`, tokens, cookies ; métadonnées filtrées ; allowlist Artisan readonly.

**Limites TASK 369 :** Gestion non modifié ; aucun health check réel exécuté tant que gateway null.

---

## Audit E2E du provisioning — TASK 370

**Périmètre :** chaîne Installation → `ProvisioningRunFactory` → `ProvisioningRun` / `ProvisioningRunStep` → `ProvisioningPipeline` / `ProvisioningPersistedRunOrchestrator` → 18 steps → adapters → readiness → résultats.

**Environnement nominal :** base MySQL `mkdpro_control_provisioning_test` + `LocalProvisioningInfrastructureBundle` (injection explicite test) — **aucun** o2switch / Cloudflare / SSH / HTTP externe (`Http::fake()`).

**Scénario nominal :** Client + Installation → factory (18 steps pending) → `runPersisted` → run `succeeded`, 18 steps `succeeded`, ordre opérations locales conforme, markers readiness `deployed` / `verified` / `ready`.

**Échecs :** data provider sur steps critiques (reserve → mark_deployed) + décorateur test `ReadinessFailOnMarkerAdapter` pour `mark_verified` / `mark_ready` ; persistence des steps précédents (ex. reserve OK si database échoue).

**Retry :** `ProvisioningRunFactory` — nouveau run `pending`, `retry_of_run_id` après failed / manual ; refus si succeeded / pending / running.

**Concurrence / crash :** reprise sur tests TASK 356 (`lockForUpdate`, `InvalidProvisioningRunTransition`) + throwable masqué (`ProvisioningExecutionSafetyTest` / E2E validate).

**Production safety :** bindings prod → validate → reserve → `manual_intervention_required` ; Null gateways + readiness Unavailable.

**Audit (TASK 370) :** création demande via `InstallationController::storeProvisioningRun` ; lacune step/run corrigée en TASK 371.

**HTTP E2E :** POST execute avec bundle local (succès) vs bindings prod (MI reserve) — routes existantes uniquement.

**Tests :** `ProvisioningEndToEndTest`, `ProvisioningEndToEndArchitectureTest` (`--filter=ProvisioningEndToEnd`).

**Limites :** pas de double-exécution parallèle réelle MySQL dans PHPUnit ; concurrence vérifiée par contrat + transitions ; pas de modification métier Subscription/Payment/Client.

---

## Traçabilité des exécutions de provisioning — TASK 371

**Mécanisme :** `AuditLog` existant via `ProvisioningExecutionAuditService` (pas de second journal). Filtrage central `ProvisioningAuditPayloadSanitizer` + `ProvisioningContext::isForbiddenSecretKey` ; aucune recopie aveugle des `metadata` adaptateur.

**Déclenchement :**

| Zone | Événements |
|------|------------|
| `InstallationController::storeProvisioningRun` | `provisioning.request_created`, `provisioning.retry_created` (si `retry_of_run_id`) |
| `ProvisioningRunController::execute` (refus avant/après orchestrateur) | `provisioning.execution_rejected` |
| `ProvisioningPersistedRunOrchestrator` | `provisioning.started`, `provisioning.step_*`, `provisioning.completed` / `provisioning.failed` / `provisioning.manual_intervention_required` |

**Convention d’actions (alignée ADR-335 + extensions) :** `provisioning.request_created`, `provisioning.retry_created`, `provisioning.started`, `provisioning.step_started`, `provisioning.step_succeeded`, `provisioning.step_skipped`, `provisioning.step_failed`, `provisioning.step_manual_intervention_required`, `provisioning.completed`, `provisioning.failed`, `provisioning.manual_intervention_required`, `provisioning.execution_rejected`.

**Données enregistrées (non sensibles) :** `provisioning_run_id`, `installation_id`, `client_id` si disponible, `step_key`, `step_order`, statuts avant/après, `trigger`, `retry_of_run_id`, `error_code`, résumés / messages opérateur filtrés, `output_summary` allowlist (pas de blob metadata adaptateur).

**Interdit :** mots de passe, tokens, clés API, `APP_KEY`, `DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, cookies, sessions, clés privées, contenu `.env`, credentials.

**Ordre :** transition + persistance (`ProvisioningRunStateService` / `ProvisioningRunStepStateService`) **puis** écriture audit. Pas de transaction globale sur le pipeline.

**Échec d’audit :** `try/catch` dans `ProvisioningExecutionAuditService` → `Log::warning('provisioning_audit_write_failed', …)` sans secret ; **l’état provisioning déjà persisté n’est pas annulé**.

**Retry :** chaîne reconstructible via `provisioning.retry_created` (`prior_provisioning_run_id`) + `retry_of_run_id` sur `provisioning.request_created`.

**Manual intervention :** actions run/step dédiées (`provisioning.manual_intervention_required`, `provisioning.step_manual_intervention_required`), distinctes de `provisioning.failed`.

**Throwable :** `ProvisioningExecutionDiagnostics` pour persistance step/run ; audit reprend codes/messages filtrés, pas la stack brute.

**Concurrence :** un seul `provisioning.started` par run (claim `lockForUpdate` inchangé).

**UI :** `AuditLogs/Index.vue` inchangée (modal détail) ; `AuditLogAdminPresentation` enrichi pour sujets Run / étape et résumé `provisioning.*`.

**Tests :** `ProvisioningAuditTrailTest`, `ProvisioningAuditTrailArchitectureTest` (`--filter=ProvisioningAuditTrail`).

**Volontairement absent :** audit readiness `installation.deployed|verified|ready` (hors exécution run), queue de replay audit, nouvelles routes HTTP, scheduler provisioning.

**Production safety :** couche audit sans adapter ; bindings Null inchangés (validate → reserve → MI).

---

## Ce que ce pipeline ne fait pas

- Pas de SSH, pas de connexion o2switch, pas de DNS réel.
- Pas de création de base externe, pas d'installation MKD-Pro réelle, pas de déploiement.
- Pas de secrets (Vault, mots de passe, tokens) dans Context / Result.
- Pas de job asynchrone, pas de commande artisan de provisioning déclenchée par l'orchestrateur.
- Pas de modification automatique des pages Clients ; la fiche Installation expose la **création de demande** pending (TASK 349) et un lien de **consultation** read-only (TASK 350), sans lancer le pipeline.
- Pas de remplacement des services d'état : `ProvisioningRunStateService` et `ProvisioningRunStepStateService` restent la couche persistance / transitions.

---

## Références

- Spécification : `control-center-provisioning-specification.md`
- Data model : `control-center-provisioning-data-model.md`
- ADR : `adr/ADR-335-provisioning-run-data-model.md`
- Runbook : `../operations/control-center-provisioning-runbook.md`
