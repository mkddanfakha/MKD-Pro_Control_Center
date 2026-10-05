# Validation préproduction — rappels d’échéance (Task 288)

Document de validation **factuelle** du pipeline Tasks 281–287 avant toute activation permanente en production. Aucune activation production n’est réalisée par cette tâche.

## Environnement analysé (session de validation)

| Élément | Valeur observée |
|---------|-----------------|
| `APP_ENV` | `local` (validation technique automatisée + CLI locale) |
| `APP_DEBUG` | activé en local |
| `APP_URL` | `http://localhost:8000` |
| Fuseau | `UTC` (`config/app.php`) |
| Base de données (workstation) | driver `mysql` (PHPUnit utilise SQLite/MySQL selon `phpunit.xml`) |
| Mailer par défaut | `log` (`config/mail.php`) |
| SMTP réel | **non utilisé** pour cette validation |
| Réception email réelle | **non prouvée** (mailer `log` / `Mail::fake()` en CI) |

Variables rappels (dépôt / `.env.example`, **sans secrets**) :

| Variable | Défaut dépôt |
|----------|----------------|
| `SUBSCRIPTION_REMINDERS_ENABLED` | `false` |
| `SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED` | `false` |
| `SUBSCRIPTION_REMINDER_NOTIFICATION_CHANNEL` | `prepared` |
| `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` | vide |

**Données :** uniquement jeux **synthétiques** (`RefreshDatabase`, clients/installations/abonnements de test). Aucune subscription client production modifiée.

## Activation progressive (rappel)

| Niveau | Configuration | Effet |
|--------|---------------|--------|
| 1 | `SUBSCRIPTION_REMINDERS_ENABLED=true` | Détection + persistance |
| 2 | + `SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED=true` | Sender |
| 3 | + `channel=email` + `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` | Email Laravel |

Les défauts versionnés restent **désactivés** ; le go-live production est une décision séparée.

## Scénarios A → N

### A — Tout désactivé

- Config : rappels et notifications `false`.
- Commandes : `subscriptions:process-reminders --dry-run` puis exécution réelle.
- **Résultat attendu / test auto :** aucun `SubscriptionReminder`, aucun email, Subscription/Payment inchangés.

### B — Détection seule

- Rappels `true`, notifications `false`, canal `prepared`.
- **Résultat :** rappel `detected`, `sent_at` null, pas d’email ; 2ᵉ run → `already_recorded`.

### C — Email activé, admin absent

- Notifications `true`, canal `email`, `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` vide.
- **Résultat :** rappel créé, sender → `unavailable`, statut `failed`, aucun Mail ; **pas de retry** au run suivant.

### D — Email configuré (mode test)

- Adresse de test **hors dépôt** (ex. boîte préprod dédiée).
- Mailer `log` ou `Mail::fake()` : valider sujet, destinataire, montant (`Subscription.amount`), contenu, **absence de secrets / liens de paiement**.
- **Ne pas** présenter comme réception SMTP réelle si mailer = `log`.

### E — Email réel préproduction

- **Applicable uniquement** si SMTP préprod contrôlé est configuré sur le serveur (hors scope de la validation automatisée locale).
- Non exécuté dans cette session : mailer local = `log`.

#### Task 289 — validation SMTP réelle (scénario E)

| Critère | État (session Task 289) |
|---------|-------------------------|
| Environnement | Workstation **local** (`APP_ENV=local`), **pas** une préproduction serveur dédiée |
| Mailer effectif | **`log`** — pas de transport SMTP réel actif |
| Credentials SMTP | Non configurés localement (`mail_username` / mot de passe SMTP absents) |
| `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` | Non renseigné dans l’environnement courant |
| Exécution `process-reminders` avec envoi réel | **Non réalisée** (conditions scénario E non remplies) |
| Réception email réelle | **Aucune preuve** — scénario **non simulé** |
| **Conclusion scénario E** | **EN ATTENTE** |

**Procédure à suivre sur une vraie préproduction** (hors dépôt Git) :

1. Serveur préprod isolé, `APP_ENV` ≠ `production`.
2. `MAIL_MAILER=smtp` + hôte/port fournis par variables d’environnement serveur uniquement.
3. Adresse de test dédiée préprod dans `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` (jamais versionnée).
4. Activer temporairement niveaux 1–3 (rappels + notifications + canal `email`).
5. Subscription **synthétique** (seuil J-1 ou J0) → `php artisan subscriptions:process-reminders`.
6. Contrôler boîte de test, idempotence (2 runs), dry-run sur 2ᵉ subscription synthétique.
7. Remettre les flags préprod selon politique interne ; **ne pas** changer les défauts du dépôt.

Ne jamais consigner adresse de test, mot de passe SMTP ni contenu d’email dans Git.

### F — Idempotence

- Après succès email : 2ᵉ et 3ᵉ `process-reminders` → un seul envoi, `sent_at` stable, `already_recorded`.

### G — Dry-run avec email activé

- Aucune écriture DB, aucun email ; indication canal / `central_admin` dans la sortie CLI.

### H — Quatre seuils (7 / 3 / 1 / 0)

- Titres TASK 283, date UTC `current_period_end`, montant = `Subscription.amount`.

### I — États terminaux

- `sent` / `failed` / `detected` déjà enregistrés → **aucun** nouvel envoi (TASK 285).

### J — Isolation renouvellement crédit

- `process-reminders` ne consomme pas de crédit, ne modifie pas les périodes, ne crée pas de Payment.
- `renew-with-credit --dry-run` ne crée pas de rappel.

### K — Isolation lifecycle

- `sync-lifecycle` et `process-reminders` restent indépendants ; le traitement rappels ne modifie pas `grace_period_ends_at`, `suspended_at`, `terminated_at`.

### L — Filtres & JSON

- `--installation`, `--subscription`, `--json`, `--dry-run --json` : périmètre et JSON stables.

### M — Scheduler

- `php artisan schedule:list` : `sync-lifecycle`, `renew-with-credit`, `process-reminders` — quotidien, `process-reminders` avec `withoutOverlapping()`, timezone applicative UTC.

### N — Sécurité contenu email

- Pas de mot de passe, token, credentials, Wave, Orange Money, lien de paiement ; contenu commercial minimal (installation, échéance, montant, message seuil).

## Commandes de référence

```bash
php artisan subscriptions:process-reminders --dry-run
php artisan subscriptions:process-reminders --json
php artisan subscriptions:process-reminders --installation=ID --subscription=ID
php artisan subscriptions:reminder-audit --dry-run
php artisan subscriptions:reminder-preview {reminder_id}
php artisan schedule:list
```

## Tests automatisés (reproductibilité)

Fichier : `tests/Feature/SubscriptionReminderPreprodValidationTest.php`

```bash
php artisan test --filter=SubscriptionReminderPreprodValidation
php artisan test --filter=SubscriptionReminder
```

Couvre les invariants A–N sans SMTP réel (`Mail::fake()`).

## Limites de cette validation

- Environnement principal : **local / CI** avec données synthétiques.
- **Pas** de preuve de délivrabilité SMTP production.
- Scénario E réservé à une préproduction serveur avec SMTP dédié et adresse de test documentée **hors Git**.

## État final

- Pipeline validé techniquement : détection → persistance → notification → email (simulé) → `sent`/`failed`.
- Idempotence, absence de retry auto, isolation lifecycle/renouvellement : **conformes**.
- Défauts dépôt : rappels et notifications **désactivés** ; canal `prepared`.

**Activation production (préparation uniquement)** : voir [subscription-reminders-production-activation.md](./subscription-reminders-production-activation.md) (Task 290).
