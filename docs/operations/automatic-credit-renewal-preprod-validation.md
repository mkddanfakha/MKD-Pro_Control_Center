# Validation préproduction — renouvellement automatique par crédit (Task 278)

Procédure pour activer **`SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=true`** uniquement sur un **environnement dédié** (préproduction), jamais en modifiant la valeur par défaut du dépôt Git (`false`).

## Prérequis environnement

| Contrôle | Attendu |
|----------|---------|
| `APP_ENV` | **≠** `production` (ex. `staging`, `preprod`, `local` avec base isolée) |
| Base de données | Instance **séparée** de la production client |
| Données | Jeux de test ou copies anonymisées — **pas** de consommation sur crédit client réel sans accord |
| Dépôt Git | `config/subscriptions.php` conserve `env(..., false)` — **ne pas committer** `true` |

Si **seule** une base de production existe : **ne pas** exécuter `subscriptions:renew-with-credit` sans `--dry-run`. Documenter l’absence de préproduction.

## Audit avant activation (Task 280)

Photographie **read-only** de l’état des abonnements (aucune consommation, aucun audit créé) :

```bash
php artisan subscriptions:automatic-renewal-audit
php artisan subscriptions:automatic-renewal-audit --eligible-only
php artisan subscriptions:automatic-renewal-audit --json
php artisan subscriptions:automatic-renewal-audit --installation=ID --subscription=ID
```

Différence avec le dry-run :

| Commande | Rôle |
|----------|------|
| `subscriptions:automatic-renewal-audit` | État actuel + éligibilité (`assessEligibility`) |
| `subscriptions:renew-with-credit --dry-run` | Simulation d’exécution (Payment FIFO, période simulée) |

Séquence recommandée avant activation :

1. `php artisan subscriptions:automatic-renewal-status`
2. `php artisan subscriptions:automatic-renewal-audit`
3. `php artisan subscriptions:renew-with-credit --dry-run`
4. Validation humaine
5. Activation éventuelle ultérieure (hors dépôt Git)

## Étape 1 — Dry-run obligatoire

Sur l’environnement contrôlé, définir la variable **uniquement** dans l’environnement (fichier `.env` local serveur, **non versionné**) :

```bash
SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=true
```

Puis :

```bash
php artisan subscriptions:renew-with-credit --dry-run
```

Capturer la sortie CLI. Vérifier : évalués, simulations, Payment FIFO, période suivante, **aucune** ligne `subscription_payment_consumptions`, **aucun** audit `subscription.credit_consumed`.

## Étape 2 — Relevé avant consommation réelle

Pour chaque abonnement de test : noter IDs, statuts, périodes, Payments et crédits restants (voir checklist Task 278 §4).

## Étape 3 — Exécution réelle (préprod uniquement)

```bash
php artisan subscriptions:renew-with-credit
```

Vérifier : 1 mois / abonnement / run, idempotence sur second run immédiat, audits `renewal_trigger=automatic_credit_renewal`, **aucun** Payment créé.

## Étape 4 — Scheduler

```bash
php artisan schedule:list
```

Confirmer `subscriptions:sync-lifecycle` et `subscriptions:renew-with-credit` distincts. Le scheduler **n’utilise pas** `--dry-run`.

## Validation automatisée (CI / local)

La suite `SubscriptionAutomaticCreditRenewalPreprodValidationTest` reproduit les scénarios A–G en **SQLite mémoire** avec `enabled=true` — référence technique lorsqu’aucun serveur de préproduction n’est disponible.

## Après validation

Remettre `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=false` sur l’environnement ou le laisser à `true` **uniquement** sur la préprod selon décision d’exploitation — **jamais** dans le dépôt Git par défaut.

---

## Procédure d’activation contrôlée en production (Task 279)

**Ne jamais committer `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=true` dans le dépôt.**

1. **Vérifier** `APP_ENV` et la base de données ciblée (instance production confirmée).
2. **Vérifier** qu’un backup récent existe.
3. **Vérifier** que la configuration est actuellement **disabled** (`php artisan subscriptions:automatic-renewal-status`).
4. **Exécuter** `php artisan subscriptions:automatic-renewal-status` (lecture seule).
5. **Activer** temporairement `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=true` **uniquement** dans le `.env` serveur (non versionné).
6. **Obligatoire** : `php artisan subscriptions:renew-with-credit --dry-run`.
7. **Examiner** évalués, simulations, Payment source, périodes simulées, motifs d’exclusion.
8. **Confirmer** : aucun Payment créé, aucune `SubscriptionPaymentConsumption`, aucun audit `subscription.credit_consumed` réel.
9. **Validation humaine explicite** avant toute exécution réelle.
10. **Première exécution réelle** : `php artisan subscriptions:renew-with-credit` (avertissement production affiché si `APP_ENV=production`).
11. **Contrôle immédiat** : renouvellements, erreurs, IDs abonnements, Payments, consommations, périodes, audits ; pas de nouveau Payment ; `Subscription.amount` / `offer_version_id` inchangés.
12. **Conserver** les logs de cette première exécution.

### Rollback (désactivation)

1. Remettre `SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED=false` dans l’environnement.
2. Vérifier : `php artisan subscriptions:automatic-renewal-status` → `Automatic : DISABLED`.
3. Optionnel : `php artisan subscriptions:renew-with-credit --dry-run` (doit rester sans consommation si disabled).

La désactivation **n’annule pas** les consommations historiques déjà enregistrées (`SubscriptionPaymentConsumption`, audits passés).
