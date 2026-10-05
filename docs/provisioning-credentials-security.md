# Provisioning Credentials Security

Architecture de gestion des credentials pour l’activation future du provisioning MKD-Pro Control Center — **TASK 374**.

**Périmètre :** inventaire code existant, stratégie de stockage, least privilege, rotation, sanitization, audit/UI. **Aucun** secret réel, **aucun** appel externe, **aucune** activation de gateway production dans le cadre de cette tâche.

---

## 1. Scope

Couvert :

- Credentials consommés ou prévus par les gateways/adapters provisioning **déjà présents** dans le dépôt ;
- Séparation configuration / secrets ;
- Chemin credential → gateway → adapter → step → run → audit → UI ;
- Mécanisme central `App\Support\Provisioning\ProvisioningSecretSanitizer` (TASK 374).

Hors scope (documenté comme futur / non requis aujourd’hui) :

- Interface d’administration des credentials ;
- Secret store externe (Vault, cloud KMS) ;
- Implémentation SSH distante (dependencies, build, migrate, etc.) ;
- Gateway cPanel « hosting » HTTP (seul `NullO2SwitchHostingGateway` existe).

---

## 2. Credential inventory

Inventaire **strictement** dérivé du code (`config/provisioning.php`, gateways, classes `*Configuration`).

| Nom logique (config) | Variable `.env` | Service | Portée | Privilèges (cible activation) | Durée de vie | Emplacement actuel | Consommateur(s) | Global vs installation |
|----------------------|-----------------|---------|--------|-------------------------------|--------------|-------------------|-----------------|------------------------|
| `cloudflare_api_token` | `PROVISIONING_CLOUDFLARE_API_TOKEN` | Cloudflare API v4 | Compte / zone DNS | Lecture zone ; list/create DNS records sur la zone configurée (Bearer token Cloudflare — permissions à restreindre au minimum DNS sur la zone) | Token Cloudflare (rotation côté Cloudflare) | `config('provisioning.secrets.cloudflare_api_token')` ← `.env` | `CloudflareDnsConfiguration::apiToken()`, `HttpCloudflareDnsGateway` | **Global** infrastructure Control Center |
| `o2switch_api_token` | `PROVISIONING_O2SWITCH_API_TOKEN` | cPanel UAPI (o2switch) | Compte cPanel | UAPI réellement appelées : **Mysql** (`list_databases`, `create_database`), **Git** (`retrieve`, `create`, `update`), **Fileman** (`get_file_content`, `save_file_content`) — token API cPanel limité à ces modules | Token cPanel (révocable dans le panel) | `config('provisioning.secrets.o2switch_api_token')` | `CpanelUapiO2SwitchDatabaseGateway`, `CpanelGitUapiO2SwitchDeployGateway`, `CpanelFileUapiO2SwitchEnvironmentGateway` ; vérification `isLiveReady()` sur plusieurs `O2Switch*Configuration` | **Global** (un compte o2switch Control Center) |
| `o2switch_cpanel_username` | `PROVISIONING_O2SWITCH_CPANEL_USERNAME` | cPanel UAPI | Compte cPanel | Identifiant du compte pour en-tête `Authorization: cpanel {user}:{token}` — pas un secret au sens cryptographique, mais **identifiant sensible** (ne pas journaliser) | Compte panel | `config('provisioning.secrets.o2switch_cpanel_username')` | Gateways cPanel ci-dessus + `isLiveReady()` | **Global** |
| `o2switch_ssh_username` | `PROVISIONING_O2SWITCH_SSH_USERNAME` | SSH (futur) | Compte SSH panel | **Non consommé** par une gateway dans le dépôt ; prévu pour exécution distante éventuelle (composer, npm, artisan) | N/A tant que SSH non implémenté | `config('provisioning.secrets.o2switch_ssh_username')` | Aucune classe production (référence test deploy uniquement) | **Global** (si un jour SSH) |

**Non inventoriés ici (absents du contrat provisioning secrets) :** mot de passe MySQL client Gestion, `APP_KEY` Gestion, clés privées SSH — ils ne doivent **pas** transiter par `ProvisioningContext` ; le builder `.env` Gestion produit des valeurs côté Fileman sans les persister dans le run.

**État implémentation :** production lie **Null** gateways ; tokens lus uniquement si une implémentation HTTP/UAPI est enregistrée **et** flags `enabled` + `isLiveReady()`.

---

## 3. Configuration vs secrets

### Configuration (non secret, `config/provisioning.php` + `.env` non sensibles)

Exemples déjà présents :

- Flags : `PROVISIONING_*_ENABLED`, `PROVISIONING_*_DRY_RUN` ;
- Cloudflare : `zone_name`, `zone_id`, `record_type`, `record_content` ;
- o2switch : `cpanel_host`, `deployment_root_base`, `account_logical_id`, `database_name_prefix`, `mysql_host_logical`, `gestion_git_repository_url`, `default_git_ref`, `gestion_app_base_domain`, chemins health Gestion, etc.

Ces valeurs peuvent apparaître dans `safePublicMetadata()`, `output_summary` (clés allowlistées), audit **après** sanitization des chaînes.

### Secrets

- `PROVISIONING_CLOUDFLARE_API_TOKEN`
- `PROVISIONING_O2SWITCH_API_TOKEN`
- `PROVISIONING_O2SWITCH_CPANEL_USERNAME` (identifiant d’auth — traité comme secret opérationnel)
- `PROVISIONING_O2SWITCH_SSH_USERNAME` (réservé futur ; pas de clé privée dans config actuelle)

**Interdits** dans :

- `ProvisioningContext` (`configuration`, `externalReferences`) — clés filtrées via `ProvisioningContext::isForbiddenSecretKey` ;
- `InfrastructureAdapterResult` (`operatorMessage`, clés metadata/output) ;
- `ProvisioningPipelineResult` / messages persistés ;
- `ProvisioningRun` / `ProvisioningRunStep` (pas de colonnes credentials) ;
- `AuditLog` payloads provisioning (`ProvisioningAuditPayloadSanitizer`) ;
- Réponses Inertia admin ;
- Logs applicatifs provisioning (messages opérateur génériques côté gateways).

**Déjà implémenté :** lecture secrets uniquement via `config('provisioning.secrets.*')` dans gateways/configuration ; jamais copiés dans le run.

---

## 4. Storage strategy

### Options analysées

| Option | Sécurité | Simplicité | Rotation | Sauvegarde / restauration | Exposition app | o2switch / déploiement actuel |
|--------|----------|------------|----------|---------------------------|----------------|-------------------------------|
| **A. Secrets uniquement `.env`** | Dépend du serveur (permissions fichier, pas de VCS) | Élevée — pattern Laravel standard | Remplacement `.env` + reload PHP-FPM / queue workers | Sauvegarder `.env` hors git de façon chiffrée ; restauration manuelle | Uniquement en mémoire processus PHP | Compatible hébergement mutualisé o2switch |
| **B. Secrets chiffrés en base (`Crypt`)** | Meilleure si DB mieux protégée que disque | Complexité clé `APP_KEY`, migrations, UI rotation | Rotation = ré-encrypt + déploiement | DB backup inclut ciphertext — **APP_KEY** requis | Risque fuite via dump SQL si `APP_KEY` compromise | Possible mais peu utile avec un seul compte infra |
| **C. Secret store externe** | Fort si bien opéré | Ops lourd (réseau, auth store, HA) | Native selon produit | Dépend du store | SDK + cache — surface élargie | Souvent **non** disponible / overkill sur o2switch mutualisé |
| **D. Combinaison `.env` + B** | Utile multi-compte / multi-zone | Complexité intermédiaire | Par enregistrement DB | Mixed | Deux chemins de lecture | Pertinent seulement si plusieurs comptes o2switch ou tokens par zone |

### Stratégie cible (Control Center)

**Recommandation architecture actuelle : option A** pour l’activation initiale production (un compte o2switch, une zone Cloudflare, un déploiement Control Center classique Laravel).

- Configuration non secrète reste dans `config/provisioning.php` + `.env` public-safe ;
- Secrets **uniquement** dans `.env` (ou fichier inclus hors git sur le serveur), jamais commités ;
- **Ne pas** introduire de secret store externe sans exigence multi-tenant / multi-compte ;
- **Option D** : décision ouverte si plusieurs comptes o2switch ou tokens Cloudflare par zone client deviennent nécessaires (voir §14).

Réutilisation Laravel existante : `env()` → config cache en production ; chiffrement Laravel (`Crypt`) disponible mais **non requis** pour la première activation.

---

## 5. Credential scope

| Credential | Portée |
|------------|--------|
| Cloudflare API token | **Global** infrastructure — zone(s) définies par config (`zone_id` / `zone_name`), pas par `ProvisioningRun` |
| o2switch API token + cpanel username | **Global** — compte panel du Control Center |
| SSH username (futur) | **Global** compte SSH panel — non utilisé |
| Identifiants par installation (DB user/password Gestion, etc.) | **Générés / écrits** sur l’hébergement client via UAPI/Fileman ; **non** stockés dans le run CC ; le run ne porte que `database_name`, chemins logiques, `installation_id`, références DNS |

Le `ProvisioningRun` référence le **contexte métier** (installation, subdomain, target_commit) — **pas** les secrets d’infrastructure.

---

## 6. Least privilege

### Cloudflare (`HttpCloudflareDnsGateway`)

Appels : `GET /zones`, `GET/POST .../dns_records`.

Minimum attendu token Cloudflare :

- Permission **Zone → DNS → Edit** (ou équivalent restrictif) **sur la zone cible uniquement** ;
- Pas besoin Workers, Firewall rules, Account settings.

### o2switch cPanel UAPI

| Gateway | UAPI / opérations code | Minimum permissions token |
|---------|------------------------|---------------------------|
| `CpanelUapiO2SwitchDatabaseGateway` | Mysql `list_databases`, `create_database` | MySQL : création/liste bases (pas DROP arbitraire hors scope provisioning) |
| `CpanelGitUapiO2SwitchDeployGateway` | Git `retrieve`, `create`, `update` | Git Version Control sur chemins sous `deployment_root_base` |
| `CpanelFileUapiO2SwitchEnvironmentGateway` | Fileman `get_file_content`, `save_file_content` | Lecture/écriture fichier `.env` cible uniquement (chemin résolu par adapter) |

**Git vs Fileman :** le code utilise le **même** `o2switch_api_token` + `o2switch_cpanel_username`. Séparation en deux tokens = possible côté cPanel si l’UI le permet ; **non implémenté** dans le code (un seul couple token/user).

### SSH

**Non requis à ce stade** — aucune gateway SSH ; étapes dependencies/build/migrate/storage/cache/admin/modules/health utilisent `NullO2Switch*Gateway` pour l’exécution distante.

### Hosting

**Non requis** — pas de gateway HTTP hosting ; protocole panel ouvert (TASK 372).

---

## 7. Rotation

Comportement **attendu** (aligné sur codes existants) :

| Événement | Comportement provisioning | Statut run / step | Retry |
|-----------|---------------------------|-------------------|-------|
| Token expiré / révoqué (401/403 API) | Gateways renvoient messages génériques (`*_access_denied`, `cloudflare_dns_*`) **sans** token | `manual_intervention_required` (cPanel auth) ou `failed` retryable (erreurs transientes list/create) | **Oui** après mise à jour `.env` et nouveau run/retry — PHP recharge config au prochain request/worker |
| Token remplacé | Ancien token inutilisable côté API ; pas de cache credential dans le run | N/A | Retry run après rotation |
| Indisponibilité temporaire (timeout, 5xx) | `failed` + `retryable: true` où applicable | `failed` run si step failed terminal | Retry run |
| Credential manquant | `manual_intervention_required` (`*_not_configured`) | MI | Après configuration `.env` |

**Éviter réutilisation ancien credential :** ne pas persister le secret ; rotation = changement `.env` uniquement — les processus long-lived (queue) doivent être redémarrés après rotation en production.

---

## 8. Failure handling

- **401/403 cPanel :** `InfrastructureAdapterResult::manualInterventionRequired` avec codes `o2switch_*_access_denied` — message opérateur fixe, pas de corps HTTP dans le message.
- **Cloudflare :** échecs list/create → messages génériques ; pas de sérialisation de la réponse API dans `operatorMessage`.
- **Exceptions pipeline :** `ProvisioningExecutionDiagnostics::safeOperatorMessage` → `ProvisioningSecretSanitizer::safeOperatorMessageFromThrowable`.
- **Run/step `error_message` :** messages adaptateur contrôlés ; throwables sanitizés.

Statuts : auth/config → **manual_intervention_required** ; erreurs transientes → **failed** retryable selon `InfrastructureAdapterResult`.

---

## 9. Sanitization

**Implémenté (TASK 374) — point central :**

`App\Support\Provisioning\ProvisioningSecretSanitizer`

- Fragments sensibles (password, bearer, authorization, cpanel, api_key, …) ;
- URLs avec userinfo (`https://user:pass@host`) ;
- `sanitizeArrayForExposure`, `redactStringForExposure`, `assertSafeAdapterOperatorMessage`.

**Délégation :**

- `ProvisioningAuditPayloadSanitizer` → sanitizer ;
- `InfrastructureAdapterResult::guardMessage` ;
- `ProvisioningExecutionDiagnostics` ;
- `ProvisioningRunAdminPresentation::filterForbiddenKeys` (chaînes) ;
- `CapacityReservationContract::sanitizePublicOutputSummary`.

**Recommandé :** toute nouvelle persistance ou audit provisioning doit passer par ce sanitizer (ou `ProvisioningContext` pour les clés).

**Tests :** `ProvisioningSecretSanitizationTest`, `ProvisioningCredentialSecurityTest`, suites audit/execution existantes.

---

## 10. Audit and logs

Chemin :

1. Credential lu en mémoire dans gateway (non loggé).
2. Adapter mappe vers `InfrastructureAdapterResult` (garde messages/clés).
3. Step → orchestrateur → `ProvisioningRunStep.output_summary` / `error_message`.
4. `ProvisioningExecutionAuditService::safeRecord` → `ProvisioningAuditPayloadSanitizer`.
5. UI : `AuditLogAdminPresentation` masque clés sensibles ; `ProvisioningRunAdminPresentation` redacte valeurs.

**Invariant :** aucun secret dans `AuditLog.new_values` / `old_values` pour actions `provisioning.*` (tests TASK 371 + 374).

Échec écriture audit : log warning `provisioning_audit_write_failed` sans payload secret.

---

## 11. UI exposure

**État actuel :**

- `ProvisioningRuns/Show` : metadata brutes non exposées ; `output_summary` via `safePublicSummary` ;
- `AuditLogs` : sanitization clés + payloads provisioning pré-filtrés ;
- Installation show/edit : pas de champs credentials provisioning ;
- Pas d’UI de gestion des tokens.

**Besoin futur documenté :** interface admin optionnelle pour **statut** des credentials (configuré oui/non, date rotation) **sans afficher** les valeurs — **non implémentée** (invariant sécurité actuel suffisant via `.env` + ops).

---

## 12. Backup and restore considerations

- **`.env` production :** sauvegarde chiffrée hors dépôt ; restauration coordonnée avec rotation tokens cPanel/Cloudflare ;
- **Base Control Center :** ne contient **pas** les tokens provisioning ; backup DB seul ne suffit pas pour l’infra ;
- **`APP_KEY` Laravel :** si un jour secrets chiffrés en DB (option B/D), backup DB **requiert** `APP_KEY` identique ;
- **Audit logs :** ne doivent pas contenir de secrets — safe pour archivage conforme à la sanitization.

---

## 13. Production activation prerequisites

Avant branchement `HttpCloudflareDnsGateway` / gateways cPanel :

1. `.env` production : tokens + config zone/host/paths (sans commit) ;
2. Feature flags **par domaine** activés explicitement (`PROVISIONING_*_ENABLED`) — hors scope TASK 374 ;
3. Enregistrement gateways live dans `ProvisioningServiceProvider` — **non fait** ;
4. Validation live o2switch/Cloudflare (TASK 372) ;
5. Vérifier least privilege tokens panel/Cloudflare ;
6. Procédure rotation documentée ops (reload workers après changement `.env`) ;
7. Exécuter suites `ProvisioningCredential` + `Provisioning` après toute modification gateways.

---

## 14. Open decisions

| Sujet | Statut |
|-------|--------|
| Secret store externe vs `.env` seul | **Décision : `.env` pour phase 1** ; réévaluer si multi-compte |
| Deux tokens cPanel (Git vs Fileman) | **Ouvert** — code actuel : un token |
| SSH + clé privée pour composer/npm/artisan | **Ouvert / non implémenté** — username seul dans config |
| Gateway hosting cPanel | **Protocole à définir** (TASK 372) |
| Chiffrement secrets en DB | **Non requis** phase 1 |
| Permissions exactes token cPanel o2switch | **Validation réelle** sur panel o2switch requise |
| Permissions token Cloudflare | **Validation réelle** dans dashboard Cloudflare |
| Contenu `.env` Gestion écrit via Fileman | **Ne jamais** auditer/logguer le corps — whitelist builder existante |

---

## Références code

- `config/provisioning.php` — sections `secrets`, `cloudflare`, `o2switch`
- Gateways : `HttpCloudflareDnsGateway`, `CpanelUapiO2SwitchDatabaseGateway`, `CpanelGitUapiO2SwitchDeployGateway`, `CpanelFileUapiO2SwitchEnvironmentGateway`
- Sanitizer : `app/Support/Provisioning/ProvisioningSecretSanitizer.php`
- Readiness : `docs/provisioning-production-readiness.md`
