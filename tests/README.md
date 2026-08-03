# Tests automatisés — GRC Citoyenne

Suite de tests unitaires PHPUnit couvrant la logique la plus sensible du plugin :
chiffrement des données personnelles, authentification JWT, captcha anti-robot,
et génération des numéros citoyens.

## Portée

Ces tests sont des **tests unitaires en isolation** : ils ne nécessitent ni base de
données MySQL, ni installation WordPress complète. `tests/bootstrap.php` fournit
des substituts minimalistes aux quelques fonctions WordPress utilisées par les
classes testées (`home_url()`, `wp_json_encode()`, `wp_rand()`, `wp_salt()`,
`WP_Error`).

**Volontairement hors de portée** de cette suite : tout ce qui nécessite `$wpdb`
(réservation de créneaux, endpoints REST complets, requêtes en base). Les tester
correctement demanderait une véritable installation WordPress de test
(`wp-phpunit` + MySQL), ce qui dépasse le cadre d'un simple `phpunit` autonome.
Si une telle installation devient disponible (ex: environnement CI avec MySQL),
ce serait la prochaine étape naturelle pour couvrir `GRC_Creneaux_Generator`,
les endpoints `class-grc-rest-*.php`, et `class-grc-activator.php`.

## Classes couvertes

| Classe | Ce qui est testé |
|---|---|
| `GRC_Encryption` | Aller-retour chiffrement/déchiffrement, non-déterminisme du chiffrement, déterminisme du hash de recherche (insensible casse/espaces), robustesse face à des données corrompues |
| `GRC_JWT` | Émission/vérification, expiration, rejet d'un token falsifié (signature invalide), rejet d'un token signé avec un autre secret |
| `GRC_Captcha` | Génération, bonne/mauvaise réponse, token falsifié, token malformé |
| `GRC_Citoyen_Helper` | Formatage du numéro citoyen, extraction depuis une saisie libre, réciprocité numero()/parse_numero() |

## Installation et exécution

### Option 1 — PHPUnit via le gestionnaire de paquets système (recommandé, le plus simple)

Sur un serveur Debian/Ubuntu (comme votre hébergement probable) :

```bash
sudo apt-get install phpunit
cd /chemin/vers/gestion-relation-citoyenne
phpunit
```

### Option 2 — PHPUnit via Composer

```bash
cd /chemin/vers/gestion-relation-citoyenne
composer require --dev phpunit/phpunit ^10
vendor/bin/phpunit
```

### Résultat attendu

```
PHPUnit 9.6.17 by Sebastian Bergmann and contributors.

.............................                                     29 / 29 (100%)

Time: 00:00.007, Memory: 6.00 MB

OK (29 tests, 54 assertions)
```

## Quand exécuter ces tests

- Avant toute release, en particulier après une modification touchant au
  chiffrement (`class-grc-encryption.php`), à l'authentification
  (`class-grc-jwt.php`), ou au captcha (`class-grc-captcha.php`)
- Ces trois classes sont les plus sensibles du plugin : une régression
  silencieuse y aurait un impact direct sur la sécurité (fuite de données
  personnelles, contournement d'authentification, ou blocage total des
  connexions/inscriptions)
