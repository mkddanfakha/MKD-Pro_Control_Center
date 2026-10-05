# Provisioning Infrastructure Preflight

Préflight **non destructif** avant activation des adapters provisioning réels — **TASK 375**.

---

## 1. Purpose

Vérifier, sans provisioning ni effet de bord :

- que la configuration (hôtes, zones, identifiants présents) est suffisante ;
- que les APIs Cloudflare et cPanel UAPI sont **joignables** ;
- que des opérations **read-only** documentées réussissent lorsque la sonde est configurée.

Le préflight **n’active aucun** flag `PROVISIONING_*_ENABLED`.

---

## 2. Scope

| Inclus | Exclus |
|--------|--------|
| Cloudflare DNS (zone + liste DNS) | Création/modification DNS |
| cPanel Mysql `list_databases` | `create_database` |
| cPanel Git `retrieve` (sonde) | `create` / `update` repo |
| cPanel Fileman `get_file_content` (sonde) | `save_file_content` |
| SSH (statut informatif) | Gateways SSH, hosting HTTP |
| Agrégat global + commande Artisan | Étapes `provisioning.step_*`, UI dashboard |

---

## 3. Checks

Orchestrateur : `App\Services\Provisioning\ProvisioningInfrastructurePreflight`.

| Clé | Classe | Capacité testée |
|-----|--------|-----------------|
| `cloudflare_dns` | `CloudflareDnsPreflightCheck` | `dns_zone_and_records_read` |
| `o2switch_database` | `O2SwitchDatabasePreflightCheck` | `mysql_list_databases` |
| `o2switch_git` | `O2SwitchGitPreflightCheck` | `git_retrieve_read_only` |
| `o2switch_fileman` | `O2SwitchFilemanPreflightCheck` | `fileman_get_file_content_read_only` |
| `o2switch_ssh` | `O2SwitchSshPreflightCheck` | `remote_shell` (informatif) |

Contrat : `App\Contracts\Provisioning\Infrastructure\InfrastructurePreflightCheck`.

---

## 4. Cloudflare

Opérations autorisées :

1. `GET /zones/{id}` ou `GET /zones?name=…` (résolution zone) ;
2. `GET /zones/{id}/dns_records?per_page=1` (lecture DNS).

Interdit : POST/PATCH/DELETE sur les records.

Configuration requise : token + (`zone_id` ou `zone_name`).

---

## 5. o2switch cPanel

Authentification : en-tête `Authorization: cpanel {username}:{api_token}` (lecture config uniquement, jamais loggé).

**Database :** `GET …/execute/Mysql/list_databases`.

---

## 6. Git

**Read-only :** `GET …/execute/Git/retrieve` avec `root` = `PROVISIONING_PREFLIGHT_GIT_PROBE_ROOT`.

Sans chemin de sonde → état `protocol_pending` (capacité non vérifiable sans effet de configuration ops).

---

## 7. Fileman

**Read-only :** `GET …/execute/Fileman/get_file_content` avec `dir` + `file` de sonde (`PROVISIONING_PREFLIGHT_FILEMAN_PROBE_*`).

Sans sonde → `protocol_pending`.

Jamais `save_file_content`.

---

## 8. SSH

Aucune gateway SSH réelle → `unsupported` / `not_required` (`o2switch_ssh_not_required`). N’influence pas le global readiness.

---

## 9. Feature flags

Chaque résultat expose :

- `provisioning_feature_enabled` — valeur du flag domaine (`enabled` dans config) ;
- `configured_and_reachable` — true uniquement si l’état du check est `ready`.

Le préflight s’exécute **même si** les flags sont à `false`.

---

## 10. Credentials

Secrets : `.env` → `config('provisioning.secrets.*')`.

Filtrage : `ProvisioningSecretSanitizer` sur messages et diagnostics.

---

## 11. Result states

États par check : `ready`, `not_configured`, `authentication_failed`, `forbidden`, `unreachable`, `protocol_pending`, `unsupported`, `failed`.

Global : `ready` | `not_ready`.

Global `ready` **uniquement** si les quatre services obligatoires sont `ready` :

- `cloudflare_dns`, `o2switch_database`, `o2switch_git`, `o2switch_fileman`.

---

## 12. Security

- Aucun secret dans `InfrastructurePreflightCheckResult::toPublicArray()` ;
- Messages opérateur validés à la construction ;
- Diagnostics sanitizés ;
- Tests `Http::fake()` sans réseau réel.

---

## 13. Non-destructive guarantees

Aucun appel create/update/delete provisioning. Seuls GET (Cloudflare, UAPI list/retrieve/get_file_content) lorsque configuré.

---

## 14. Production activation workflow

1. Renseigner `.env` (secrets + config + sondes Git/Fileman si souhaité).
2. `php artisan provisioning:infrastructure-preflight` (manuel).
3. Corriger états `not_configured` / auth / forbidden.
4. Global `ready` → prérequis infra read-only OK ; activation adapters/flags = tâches séparées.

**Audit :** pas d’événement `infrastructure.preflight` par défaut (manuel uniquement ; audit optionnel futur).

**UI :** pas de page dédiée TASK 375 ; commande Artisan = point d’accès minimal.

---

## 15. Open limitations

- Git/Fileman : `protocol_pending` sans chemins de sonde ops.
- Hosting cPanel : non couvert (gateway Null).
- Dépendances/build/migrate distants : non préflightés (SSH non implémenté).
- Permissions token cPanel/Cloudflare : validation réelle sur environnement o2switch/Cloudflare requise.
