# Activation production — rappels d’abonnement (Task 290)

**Cette tâche prépare l’activation mais n’active pas les rappels.**

Guide opérationnel pour une **future** mise en service manuelle sur le serveur de production MKD-Pro Control Center. Aucune étape ci-dessous ne doit être interprétée comme une activation automatique via le dépôt Git.

Références techniques : Tasks 281–287 (pipeline), Task 288 (validation préprod automatisée), Task 289 (validation SMTP réelle scénario E — distincte et préalable recommandée).

---

## 1. Prérequis

- Application déployée sur l’environnement **production** identifié (contrôle `APP_ENV`, URL, base dédiée).
- Pipeline rappels déjà déployé (commande `subscriptions:process-reminders`, scheduler, canal email).
- Validation préprod Task 288 **réussie** (invariants).
- Scénario E Task 289 **VALIDÉ** sur préproduction avec SMTP réel (recommandé avant production).
- Accès sécurisé aux variables d’environnement serveur (pas de secrets dans Git).
- Fuseau applicatif **UTC** (`config/app.php`) — aligné avec la détection des seuils.
- Cron / orchestrateur exécutant `php artisan schedule:run` **chaque minute**.

---

## 2. Variables d’environnement (production)

### Valeurs par défaut du dépôt (ne pas modifier dans Git)

| Variable | Défaut versionné |
|----------|------------------|
| `SUBSCRIPTION_REMINDERS_ENABLED` | `false` |
| `SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED` | `false` |
| `SUBSCRIPTION_REMINDER_NOTIFICATION_CHANNEL` | `prepared` |
| `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` | *(vide)* |

Source : `config/subscriptions.php`, `.env.example`.

### Valeurs cibles après activation complète (serveur uniquement)

À définir **uniquement** dans le fichier `.env` (ou gestionnaire de secrets) du serveur production — **jamais** dans le dépôt :

| Variable | Valeur cible (production) |
|----------|---------------------------|
| `SUBSCRIPTION_REMINDERS_ENABLED` | `true` |
| `SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED` | `true` |
| `SUBSCRIPTION_REMINDER_NOTIFICATION_CHANNEL` | `email` |
| `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` | *adresse de supervision MKD-Pro* |

L’adresse de supervision est une boîte **interne** (ops / supervision), pas une adresse client finale. Ne pas la committer, ne pas la documenter avec une valeur réelle dans Git.

---

## 3. Configuration SMTP (production)

Prérequis côté serveur (placeholders — valeurs réelles **uniquement** dans l’environnement) :

```env
MAIL_MAILER=smtp
MAIL_HOST=<fourni par l'hébergeur>
MAIL_PORT=<fourni par l'hébergeur>
MAIL_USERNAME=<secret serveur>
MAIL_PASSWORD=<secret serveur>
MAIL_ENCRYPTION=<tls ou ssl selon fournisseur>
MAIL_FROM_ADDRESS=<adresse expéditrice autorisée>
MAIL_FROM_NAME=<nom affiché MKD-Pro>
```

- Ne jamais versionner mot de passe, clé API ou token.
- Ne pas modifier `.env.example` avec des credentials production.
- Après changement `.env` : `php artisan config:clear` puis `php artisan config:cache` si la production utilise le cache de config.

---

## 4. Ordre d’activation (progressif)

| Étape | Action |
|-------|--------|
| **0** | Confirmer qu’il s’agit bien du serveur **production** MKD-Pro Control Center (URL, base, procédure interne). |
| **1** | Configurer SMTP (section 3) et vérifier l’expéditeur autorisé. |
| **2** | Définir `SUBSCRIPTION_REMINDER_ADMIN_EMAIL` (supervision) — seul cette variable rappel peut être renseignée en amont. |
| **3** | Laisser `SUBSCRIPTION_REMINDERS_ENABLED=false` et `SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED=false` ; contrôler chargement config. |
| **4** | Tester le mailer de façon **contrôlée** si un outil interne existe (sinon : valider SMTP en préprod Task 289). |
| **5** | Activer **détection seule** : `SUBSCRIPTION_REMINDERS_ENABLED=true`, notifications **restent** `false`. Exécuter `php artisan subscriptions:process-reminders --json` : rappels `detected`, **aucun** email. |
| **6** | Activer **notifications** : `SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED=true`. |
| **7** | Confirmer `SUBSCRIPTION_REMINDER_NOTIFICATION_CHANNEL=email`. |
| **8** | Première exécution surveillée (section 6) — fenêtre de maintenance ou horaire à faible charge si possible. |

Ne jamais sauter l’étape 5 : elle permet de vérifier persistance idempotente sans envoi externe.

---

## 5. Vérifications avant activation du canal email

Checklist (à cocher sur le serveur) :

- [ ] SMTP fonctionnel (preuve Task 289 ou test contrôlé équivalent).
- [ ] Adresse de supervision renseignée et correcte.
- [ ] Aucun secret dans Git / dépôt déployé.
- [ ] `php artisan schedule:list` contient `subscriptions:process-reminders`.
- [ ] `withoutOverlapping()` actif sur `process-reminders` et `renew-with-credit`.
- [ ] Defaults du dépôt inchangés (`false` / `prepared` dans le code source).
- [ ] Configuration Laravel rechargée (`config:clear` / `config:cache` selon usage prod).
- [ ] Renouvellement automatique et lifecycle : **indépendants** (pas de modification requise).

**Interdit :** `php artisan migrate:fresh`, toute commande destructive sur la base production.

---

## 6. Première exécution surveillée

Après activation complète (étapes 5–7), **une** exécution manuelle recommandée avant de s’appuyer uniquement sur le scheduler :

```bash
php artisan subscriptions:process-reminders --json
```

Examiner le JSON :

| Clé | Attente |
|-----|---------|
| `enabled` | `true` |
| `notifications_enabled` | `true` |
| `evaluated` | nombre d’abonnements parcourus |
| `newly_detected` | nouveaux rappels du run |
| `already_recorded` | rappels déjà connus |
| `sent` | envois réussis du run |
| `failed` | échecs (ex. admin email absent — ne doit pas arriver si étape 2 OK) |
| `ignored` | rappels existants non renenvoyés |
| `errors` | **0** en conditions normales |
| `duration_ms` | ordre de grandeur acceptable |

Consulter les logs Laravel (`subscription_reminders.process.completed`, `subscription_reminder.email.sent`, absence d’erreurs SMTP).

**Task 290 ne demande pas d’exécuter cette activation maintenant** — documenter la procédure pour l’équipe ops.

---

## 7. Contrôle d’idempotence

Immédiatement après la première exécution réussie :

```bash
php artisan subscriptions:process-reminders --json
php artisan subscriptions:process-reminders --json
```

Attendu :

- `newly_detected` = 0 ;
- `sent` = 0 ;
- `already_recorded` ≥ nombre de rappels dus ;
- **aucun** second email pour le même seuil ;
- `sent_at` inchangé sur les rappels déjà `sent`.

---

## 8. Surveillance (post-activation)

Indicateurs à suivre avec les outils **existants** (logs, JSON CLI, requêtes DB read-only) — **pas** de nouveau module de monitoring :

- rappels détectés / persistés (`detected`) ;
- rappels envoyés (`sent`) ;
- rappels en échec (`failed`) — investiguer si admin email ou SMTP ;
- `errors` dans le résumé CLI ;
- doublons : une ligne logique par `(subscription, type, threshold, scheduled_for)` ;
- absence d’erreurs SMTP répétées ;
- absence de secrets dans les logs (jamais logger mot de passe / token).

Commandes utiles :

```bash
php artisan subscriptions:process-reminders --json
php artisan subscriptions:reminder-audit --json
```

---

## 9. Procédure de désactivation (rollback)

Sans supprimer l’historique `subscription_reminders` :

1. **Urgence envoi** : `SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED=false`
   → détection/persistance possible si rappels restent activés, **plus aucun** envoi.

2. **Arrêt complet** : `SUBSCRIPTION_REMINDERS_ENABLED=false`
   → plus de détection ni persistance ni envoi via `process-reminders`.

Recharger la config (`config:clear` / cache). Ne pas modifier Payment, Subscription, ni supprimer les lignes de rappel.

---

## 10. Critères « activation opérationnelle »

L’activation production peut être considérée **opérationnelle** lorsque :

1. Étape 5 validée (détection sans email).
2. Étape 8 validée (premier run `sent` / `failed` cohérent, `errors` = 0).
3. Idempotence confirmée (section 7).
4. Scheduler actif (`schedule:run` + entrée quotidienne `process-reminders`).
5. Aucun impact sur renouvellement crédit ni lifecycle (isolation Tasks 288/289).
6. Scénario E Task 289 **VALIDÉ** en préprod (SMTP réel) ou équivalent documenté.

---

## 11. Rappels légaux / process

- L’activation est **manuelle** sur le serveur par une personne autorisée.
- Le dépôt Git reste avec fonctionnalité **désactivée** par défaut.
- Aucun email réel ne doit être envoyé lors de la préparation Task 290.
- La validation SMTP production reste distincte (Task 289 en préprod, puis fenêtre de go-live contrôlée).

---

## Interface administrative (Task 291)

Route authentifiée **`/subscription-reminders`** — liste **read-only** des `SubscriptionReminder` (filtres, pagination, détail modal).

- Permet de **surveiller** les rappels avant/après activation.
- **Aucun** envoi d’email, **aucun** retry, **aucune** action depuis l’UI.

### Autorisation (Task 292)

- Accès réservé aux utilisateurs authentifiés du Control Center (`auth` + Gate `accessControlCenter` + `SubscriptionReminderPolicy::viewAny`).
- Aujourd’hui, tout compte utilisateur provisionné correspond à un accès CC ; le Gate pourra être durci sans changer la page.
- Utilisateur non authentifié → redirection login ; accès refusé par politique → **403**.
- Aucune action d’envoi depuis cette interface.
- L’activation production reste indépendante (variables serveur).
- Le scénario SMTP réel (Task 289) reste à valider sur préproduction avant go-live email.

## Liens

- [Validation préprod (Task 288)](./subscription-reminders-preprod-validation.md)
- [Renouvellement crédit — validation préprod](./automatic-credit-renewal-preprod-validation.md)
- Architecture : `docs/architecture/subscription-model.md`
