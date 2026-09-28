# Scheduler Laravel en production — MKD-Pro Control Center

Documentation d’exploitation pour la synchronisation planifiée du cycle de vie des abonnements (`active` → `grace_period` → `suspended` via `SubscriptionService::syncLifecycle()`).

**Ce dépôt Git ne configure aucun cron sur un serveur réel.** Il décrit le code applicatif et la procédure d’infrastructure à mettre en place en production (notamment sur un hébergement mutualisé type o2switch).

---

## A. Ce que fait Laravel (dans le code)

Le Control Center **enregistre** une tâche planifiée dans `routes/console.php` :

```php
Schedule::command('subscriptions:sync-lifecycle')->daily();
```

Cela signifie que Laravel **sait quand** la commande `subscriptions:sync-lifecycle` doit être exécutée : **une fois par jour** à **00:00** dans le fuseau `config('app.timezone')` (équivalent cron `0 0 * * *`).

Le fichier `routes/console.php` est chargé au démarrage de l’application via `bootstrap/app.php` (`commands: __DIR__.'/../routes/console.php'`).

La commande métier :

```bash
php artisan subscriptions:sync-lifecycle
```

orchestre l’appel à `SubscriptionService::syncLifecycle()` pour les abonnements en statut `active` ou `grace_period` (voir section 7).

**Important :** définir `->daily()` dans le code **ne lance pas** la commande automatiquement. Laravel attend qu’un processus externe invoque le scheduler (section B).

---

## B. Ce que doit faire le serveur de production

En production, le serveur (cron système, panel hébergeur, systemd timer, etc.) doit exécuter **régulièrement** :

```bash
php artisan schedule:run
```

**Méthode classique :** une entrée **cron système** qui s’exécute **chaque minute** depuis la racine du projet :

```cron
* * * * * cd /chemin/vers/MKD-Pro_Control_Center && php artisan schedule:run >> /dev/null 2>&1
```

Remplacez `/chemin/vers/MKD-Pro_Control_Center` par le chemin absolu réel sur le serveur (placeholder documentaire — **ne pas** réutiliser un chemin Windows ou un chemin inventé pour o2switch).

Points à adapter sur le serveur :

- chemin absolu du dépôt ;
- binaire PHP CLI (`php` ou chemin complet fourni par l’hébergeur) ;
- utilisateur sous lequel tourne le cron (permissions `.env`, `storage/`) ;
- redirection des logs en production (`>> /var/log/...` plutôt que `/dev/null` si traçabilité requise).

Tant qu’**aucun** mécanisme n’appelle `schedule:run` chaque minute, **`subscriptions:sync-lifecycle` ne s’exécutera pas**, même si `routes/console.php` contient `->daily()`.

---

## C. `schedule:run` n’est pas un service qui reste actif

```bash
php artisan schedule:run
```

est une **exécution ponctuelle** : Laravel vérifie quelles tâches sont **dues à cette minute**, lance celles qui le sont, puis **se termine**.

Ce n’est **pas** un daemon à laisser tourner en continu. C’est le **cron système** (ou équivalent) qui doit **relancer** `schedule:run` **chaque minute**.

Ne pas confondre :

| Commande | Rôle |
|----------|------|
| `php artisan schedule:run` | Vérification **ponctuelle** du scheduler (à répéter chaque minute via cron) |
| `php artisan subscriptions:sync-lifecycle` | **Synchronisation métier** du cycle de vie (planifiée **une fois par jour** via `->daily()`) |

---

## D. Fréquence réelle (chaîne complète)

Le lifecycle **n’est pas** exécuté chaque minute. Laravel **vérifie** chaque minute ; la commande lifecycle n’est **due qu’une fois par jour**.

```text
cron système (* * * * *)
    ↓
php artisan schedule:run   (chaque minute, exécution ponctuelle)
    ↓
Laravel évalue les tâches enregistrées
    ↓
subscriptions:sync-lifecycle   (uniquement quand l’heure planifiée est atteinte)
    ↓
Schedule::command(...)->daily()   (typiquement 00:00, fuseau applicatif)
    ↓
SubscriptionService::syncLifecycle()   (par abonnement éligible)
```

Mécanisme retenu par le projet (sans job ni queue dédiée) :

```text
scheduler Laravel
    ↓
subscriptions:sync-lifecycle
    ↓
SubscriptionService::syncLifecycle()
```

Aucun déclenchement depuis les contrôleurs HTTP admin : consulter le back-office **ne** synchronise **pas** le lifecycle.

---

## Configuration du cron en production (hébergement mutualisé / o2switch)

MKD-Pro Control Center est prévu pour un déploiement sur **o2switch** (ou hébergeur équivalent). **Ce dépôt ne contient pas** une capture d’écran ni une validation de l’interface actuelle du panel o2switch ; la procédure ci-dessous reste **générique**.

1. **Créer une tâche cron** dans le panel d’administration de l’hébergeur (ou via SSH crontab selon l’offre).
2. **Fréquence :** chaque minute (`* * * * *`).
3. **Commande :** exécuter `php artisan schedule:run` depuis la **racine** de l’application déployée, par exemple :

   ```bash
   cd /chemin/vers/MKD-Pro_Control_Center && php artisan schedule:run
   ```

   Utiliser le chemin absolu Linux indiqué par l’hébergeur pour le compte et le répertoire du site.

4. **Ne pas** planifier `subscriptions:sync-lifecycle` directement dans le cron à la place de `schedule:run` : Laravel centralise toutes les tâches planifiées via `schedule:run` ; ajouter d’autres `Schedule::` dans le futur resterait cohérent.

5. **Après configuration :** suivre la section « Vérification production » ci-dessous.

---

## Vérification production

Checklist pour confirmer que le déclenchement automatique est **enregistré dans le code** et que le **serveur** exécute bien le scheduler.

### 1. Le scheduler connaît la tâche

```bash
php artisan schedule:list
```

Résultat attendu (libellés selon locale CLI) : une ligne du type :

```text
0 0 * * * php artisan subscriptions:sync-lifecycle
```

avec une indication « Next Due » cohérente avec le fuseau (`php artisan about` ou `config('app.timezone')`).

### 2. La commande existe

```bash
php artisan subscriptions:sync-lifecycle --help
```

Doit afficher la description : synchronisation du cycle de vie des abonnements actifs et en période de grâce.

### 3. Exécution manuelle (diagnostic ou rattrapage uniquement)

```bash
php artisan subscriptions:sync-lifecycle
```

**Ne pas** présenter cette commande comme devant être lancée **manuellement tous les jours** en production normale. L’exécution automatique quotidienne passe par **cron → `schedule:run` → `->daily()`**. L’appel manuel sert au **diagnostic**, après déploiement sur staging, ou **rattrapage** si une fenêtre planifiée a été manquée.

Options utiles : `-q` / `--quiet` (résumé seul) ; code de sortie `0` si aucune erreur par abonnement, `1` si au moins une erreur.

### 4. Le serveur exécute bien le scheduler

Sur le serveur de production, vérifier que le cron (ou timer) appelle **`php artisan schedule:run`** chaque minute — par exemple via les logs cron de l’hébergeur, un fichier de log dédié, ou un test contrôlé à une minute où une tâche test est due.

**Note :** exécuter `schedule:run` une fois à la main en SSH **prouve** que la commande fonctionne ; cela **ne remplace pas** le cron minute par minute pour les jours suivants.

---

## Fréquence Laravel (référence)

| Aspect | Détail |
|--------|--------|
| **Fréquence lifecycle** | **Quotidienne** (`->daily()`), cron `0 0 * * *`. |
| **Fuseau horaire** | `config('app.timezone')` / variable d’environnement `APP_TIMEZONE`. |
| **Valeur par défaut dans le dépôt** | `config/app.php` : `UTC` (sauf surcharge `.env`). |

---

## Comportement de `subscriptions:sync-lifecycle`

| Règle | Détail |
|-------|--------|
| **Abonnements traités** | Statuts `active` et `grace_period` uniquement. |
| **Ignorés** | `suspended`, `terminated`. |
| **Erreurs** | Une erreur sur un abonnement **n’arrête pas** le lot ; compteur + audit ; les autres continuent. |
| **Code retour** | `SUCCESS` si zéro erreur ; `FAILURE` si au moins une erreur. |
| **Idempotence** | Rejouable sans transition incohérente (logique dans le service). |
| **Volume** | `chunkById(100)`. |

La logique des transitions reste dans **`SubscriptionService::syncLifecycle()`** ; la commande ne duplique pas les règles de verrouillage (`lockForUpdate` côté service).

---

## Déploiement et limites du dépôt Git

| Élément | État |
|---------|------|
| Planification dans le code | Oui — `routes/console.php` |
| Cron serveur versionné | **Non** — configuration **infrastructure**, hors dépôt |
| Garantie d’exécution automatique | **Non** — dépend du cron / panel sur le serveur de production |

---

## Checklist ops (mise en production)

1. Choisir l’environnement (serveur, panel o2switch, conteneur, etc.).
2. Déterminer le chemin réel : `/chemin/vers/MKD-Pro_Control_Center` sur le serveur Linux.
3. Vérifier PHP CLI : `php -v`, `php artisan --version` depuis ce répertoire.
4. Vérifier le fuseau effectif pour l’heure du `daily()`.
5. Configurer le cron **chaque minute** → `schedule:run`.
6. `php artisan schedule:list` sur le serveur.
7. Surveiller logs Laravel / cron / code retour de la commande métier.
8. Test contrôlé : `subscriptions:sync-lifecycle` sur staging ; optionnel `php artisan schedule:test` selon la version Laravel et les pratiques de l’équipe.

---

## Références dans le dépôt

| Élément | Fichier |
|---------|---------|
| Planification | `routes/console.php` |
| Chargement console | `bootstrap/app.php` |
| Commande | `app/Console/Commands/SyncSubscriptionLifecycle.php` |
| Cycle de vie (métier) | `app/Services/SubscriptionService.php` |
| Fuseau par défaut | `config/app.php` → `timezone` |
| Statuts installation vs abonnement | `docs/architecture/status-lifecycle.md` |

---

## Périmètre

Ce document est **opérationnel / informatif**. Il ne modifie pas le code métier du lifecycle, `.env`, cron serveur, base de données ni applications MKD-Pro Gestion clientes.
