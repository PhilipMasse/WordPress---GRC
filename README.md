# Gestion de la Relation Citoyenne (GRC) — Mairie de Berre-les-Alpes

Plugin WordPress de gestion de la relation citoyenne : signalements, demandes, rendez-vous, démarches administratives, avec API REST pour application mobile (Android/iOS).

## Prérequis

- WordPress 7.0+
- PHP 8.1+ (testé sur PHP 8.5)
- Extension `sodium` (incluse nativement en PHP 7.2+)
- MySQL/MariaDB

## Installation

1. Copier le dossier `gestion-relation-citoyenne` dans `wp-content/plugins/`.
2. **Avant d'activer**, ajouter dans `wp-config.php` :

```php
define( 'GRC_ENCRYPTION_KEY', 'CLE_GENEREE_EN_BASE64' );
define( 'GRC_JWT_SECRET', 'CHAINE_ALEATOIRE_LONGUE' );
```

Pour générer une clé de chiffrement valide :

```php
echo base64_encode( random_bytes( 32 ) );
```

3. Activer le plugin depuis l'administration WordPress. Les tables sont créées automatiquement.

## Sécurité

- Toutes les données personnelles (nom, prénom, email, téléphone) sont chiffrées en base via `libsodium` (XChaCha20-Poly1305).
- Un hash HMAC-SHA256 est stocké en parallèle de l'email/téléphone pour permettre la recherche sans déchiffrement de masse.
- Journalisation (audit log) de tous les accès/modifications sur les fiches citoyennes.
- Purge RGPD automatique (anonymisation) configurable, 3 ans par défaut après clôture d'une demande.
- Rate limiting sur les endpoints publics de l'API REST.
- **`GRC_ENCRYPTION_KEY` et `GRC_JWT_SECRET` ne doivent jamais être committées sur GitHub.**

## API REST

Namespace : `/wp-json/grc/v1/`

| Endpoint | Méthode | Auth | Description |
|---|---|---|---|
| `/auth/login` | POST | Non | Connexion, retourne access_token (JWT) + refresh_token |
| `/auth/refresh` | POST | Non | Renouvelle l'access_token |
| `/auth/logout` | POST | JWT | Révoque le refresh token |
| `/demandes/public-submit` | POST | Non | Créer un signalement (compte ou invité) |
| `/demandes/guest-lookup` | POST | Non | Suivre une demande en mode invité (numéro + email) |
| `/demandes` | GET | JWT | Liste des demandes (agents/élus) |
| `/demandes/{id}` | GET | JWT | Détail d'une demande |
| `/demandes/{id}/statut` | POST | JWT | Changer le statut d'une demande |
| `/mes-demandes` | GET | JWT | Demandes du citoyen connecté |
| `/rdv/creneaux` | GET | Non | Liste des créneaux disponibles |
| `/rdv` | POST | JWT | Réserver un rendez-vous |

## Déploiement / Versioning

Mise à jour automatique via Plugin Update Checker, sur le même modèle que le plugin Simple Page Builder :

1. Push du code sur la branche `main` (Code tab GitHub).
2. Création d'une Release GitHub taguée (ex : `v0.2.0`) → déclenche l'auto-update sur les instances WordPress.

## État d'avancement

Voir `CHANGELOG.md` pour le détail des modules implémentés vs à venir.
