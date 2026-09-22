# Scheduler Laravel en production — MKD-Pro Control Center

Documentation d’exploitation pour la synchronisation planifiée du cycle de vie des abonnements.  
**Aucune configuration cron n’est appliquée par ce document** : il décrit l’état du code et ce qui restera à faire côté serveur.

---

## 1. Commande métier planifiée

Le Control Center enregistre la tâche suivante :

```bash
php artisan subscriptions:sync-lifecycle
```

Cette commande orchestre l’appel à `SubscriptionService::syncLifecycle()` pour les abonnements concernés (voir section 7).

La planification est définie dans le code :

```php
// routes/console.php
Schedule::command('subscriptions:sync-lifecycle')->daily();
```

---

## 2. Fréquence Laravel actuelle

| Aspect | Détail |
|--------|--------|
| **Fréquence** | **Quotidienne** (`->daily()`), expression cron équivalente `0 0 * * *` (minuit). |
| **Fuseau horaire** | Les heures d’exécution du scheduler Laravel suivent **`config('app.timezone')`**. |
| **Valeur dans le dépôt** | Dans `config/app.php` : `'timezone' => 'UTC'` (valeur par défaut du fichier de configuration, **non modifiée** par cette documentation). |
| **Production** | Un fichier `.env` peut définir `APP_TIMEZONE` ; sans l’éditer ici, retenir que l’heure « minuit » planifiée est **minuit dans le fuseau effectivement chargé** au runtime (`php artisan schedule:list` affiche « Next Due » selon ce fuseau). |

Vérification locale typique :

```bash
php artisan schedule:list
```

Exemple de sortie attendue (libellés selon locale CLI) :

```text
0 0 * * * php artisan subscriptions:sync-lifecycle
```

---

## 3. Mécanisme Laravel Scheduler

Laravel **ne lance pas** le scheduler en arrière-plan de lui-même. Il **enregistre** des tâches ; c’est l’environnement d’exécution qui doit **interroger** le scheduler régulièrement.

En production, le serveur (ou un orchestrateur) doit exécuter **au minimum une fois par minute** :

```bash
php artisan schedule:run
```

`schedule:run` évalue quelles tâches sont dues **à cette minute** et lance celles qui le sont (ici, une fois par jour à 00:00 dans le fuseau applicatif).

Sans cet appel périodique, **`subscriptions:sync-lifecycle` ne s’exécutera pas**, même si `routes/console.php` contient `->daily()`.

---

## 4. Configuration serveur attendue (documentation uniquement)

Lors d’une future mise en production, il faudra configurer un **cron système** (ou équivalent : systemd timer, panel hébergeur, CI scheduler) qui invoque `schedule:run` **chaque minute**.

Exemple **documentaire** (chemin fictif — **ne pas utiliser tel quel**) :

```cron
* * * * * cd /chemin/vers/MKD-Pro_Control_Center && php artisan schedule:run >> /dev/null 2>&1
```

Points à adapter **ultérieurement** sur le vrai serveur :

- chemin absolu du projet ;
- binaire PHP CLI (`php` ou chemin complet) ;
- utilisateur système sous lequel tourne le cron (permissions fichiers, `.env`) ;
- redirection des logs (`>> /var/log/...` plutôt que `/dev/null` en prod si traçabilité requise).

**Cette étape de documentation ne configure aucun cron réel.**

---

## 5. Commande de vérification

Lister les tâches enregistrées et la prochaine échéance :

```bash
php artisan schedule:list
```

Options utiles (Laravel) : `--timezone=`, `--next`, `--json` (selon version).

---

## 6. Commande métier manuelle

Un administrateur peut lancer la synchronisation **hors planification** (maintenance, test après déploiement) :

```bash
php artisan subscriptions:sync-lifecycle
```

Options globales Artisan :

- `-q` / `--quiet` : masque le détail par abonnement, affiche le résumé ;
- code de sortie `0` si aucune erreur sur un abonnement, `1` si au moins une erreur a été comptée.

Aide :

```bash
php artisan subscriptions:sync-lifecycle --help
```

---

## 7. Comportement de `subscriptions:sync-lifecycle`

Rappel du comportement implémenté (couche orchestration uniquement) :

| Règle | Détail |
|-------|--------|
| **Abonnements traités** | Statuts `active` et `grace_period` uniquement. |
| **Ignorés** | `suspended`, `terminated` (non chargés inutilement pour ce traitement). |
| **Erreurs** | Une `SubscriptionLifecycleException` (ou autre erreur) sur un abonnement **n’arrête pas** le lot ; compteur d’erreurs + message ; les autres abonnements continuent. |
| **Code retour** | `SUCCESS` si zéro erreur ; `FAILURE` si au moins une erreur. |
| **Idempotence** | Repasser sur le même état (ex. déjà en `grace_period` sans nouvelle échéance) ne provoque pas de transition incohérente ; le service lifecycle est conçu pour être rejouable. |
| **Volume** | Traitement par paquets (`chunkById`) pour limiter la mémoire. |

La logique métier des transitions reste dans **`SubscriptionService::syncLifecycle()`** ; la commande ne duplique pas les règles de verrouillage (`lockForUpdate` côté service).

---

## 8. Déploiement actuel

> **Le scheduler Laravel est configuré dans le code**, via `Schedule::command('subscriptions:sync-lifecycle')->daily()` dans `routes/console.php`, **mais aucun cron de production n’est configuré par cette étape** (ni par ce dépôt documentation seul).

Tant qu’aucun mécanisme n’appelle `php artisan schedule:run` chaque minute en production, la planification quotidienne **n’a pas d’effet** sur l’environnement concerné.

---

## 9. Procédure future (checklist ops)

À réaliser **ultérieurement**, hors scope de la simple documentation :

1. **Choisir l’environnement de production** (serveur, panel, conteneur, etc.).
2. **Déterminer le chemin réel** du dépôt MKD-Pro Control Center sur ce serveur.
3. **Vérifier PHP CLI** : `php -v`, `php artisan --version` depuis ce chemin, extensions requises (DB, etc.).
4. **Vérifier le fuseau** effectif : `config('app.timezone')` / `APP_TIMEZONE` pour comprendre à quelle heure locale tombe le `0 0 * * *`.
5. **Configurer le cron** (ou alternative) : une entrée `* * * * *` → `schedule:run`.
6. **Vérifier l’enregistrement** : `php artisan schedule:list`.
7. **Vérifier les exécutions** : logs Laravel (`storage/logs`), sortie cron, monitoring éventuel du code retour de la commande métier.
8. **Test contrôlé** : exécution manuelle `subscriptions:sync-lifecycle` sur staging ; puis attendre une fenêtre planifiée ou utiliser `php artisan schedule:test` (si disponible sur la version Laravel) selon les pratiques de l’équipe.

---

## Références dans le dépôt

| Élément | Fichier |
|---------|---------|
| Planification | `routes/console.php` |
| Commande | `app/Console/Commands/SyncSubscriptionLifecycle.php` |
| Cycle de vie | `app/Services/SubscriptionService.php` |
| Fuseau par défaut (config) | `config/app.php` → `timezone` |
| Statuts installation vs abonnement | `docs/architecture/status-lifecycle.md` |

---

## Périmètre

Ce document est **purement opérationnel / informatif**. Il ne modifie pas le code, `.env`, cron serveur, base de données ni applications MKD-Pro Gestion clientes.
