# Prompt Claude Code — Plugin « Factur-X for WooCommerce »

> **Comment l'utiliser** : après avoir installé l'environnement (section 2 ci-dessous), ouvre le dossier vide du plugin (`factur-x-for-woocommerce/`) dans ton terminal, lance Claude Code (`claude`), et copie-colle l'intégralité de ce document comme premier message. Sauvegarde-le aussi en parallèle sous le nom `CLAUDE.md` à la racine du plugin — Claude Code le relira automatiquement à chaque nouvelle session pour conserver le contexte sans que tu aies à le retaper.

---

## 1. Contexte du projet

Je suis Mathis. Je débute en développement WordPress / WooCommerce et en PHP. J'ai une expérience de programmation en MQL5 (famille C, langage de scripting MetaTrader), donc la syntaxe ne me pose pas de problème, mais je ne connais ni l'écosystème WordPress, ni les conventions WooCommerce, ni les pièges classiques du dev plugin. Je code avec ton aide en pair-programming. Je travaille en français, en mode asynchrone, sans prise de parole publique. L'objectif est un revenu récurrent à long terme.

Je construis un **plugin WordPress freemium pour la facturation électronique française conforme à la réforme 2026-2028** : génération de factures **Factur-X** (PDF/A-3 + XML CII embarqué, profil EN 16931) à partir des commandes WooCommerce, avec à terme connecteurs vers les **Plateformes Agréées** (PA, ex-PDP) accréditées par la DGFiP pour le routage automatique des factures.

**Vision long terme** :
- **V0.1 (MVP, ce qu'on attaque maintenant)** : plugin gratuit publié sur WordPress.org. Génération automatique d'une facture Factur-X conforme sur le statut commande « completed », téléchargement depuis l'admin, envoi par email à la commande, validation SIREN/SIRET et TVA intra au checkout.
- **V0.5 (3-4 mois après)** : version Pro payante via Freemius (149-599 €/an). Routage vers les PA (Iopole, B2Brouter, Pennylane via API), dashboard de conformité, e-reporting B2C.
- **V1.0 (12 mois)** : multi-PA, réception de factures fournisseur, archivage légal eIDAS, conformité EU ViDA.

**Le contexte stratégique** : la réforme française e-invoicing est confirmée sans report — réception obligatoire pour toutes les entreprises FR au 1er sept. 2026, émission GE/ETI au 1er sept. 2026, émission TPE/PME au 1er sept. 2027. Le marché est captif (7 M+ entreprises FR concernées), aucun leader WordPress n'est installé sur ce créneau, plusieurs concurrents FR-natifs (e-facturX, FactureXPress, Meepha) sont tous en pré-lancement simultané en mai 2026. La fenêtre concurrentielle est de 12-18 mois. Cible publication V0.1 sur WordPress.org : **avant fin juin 2026**.

---

## 2. Logiciels à installer (avant la première session Claude Code)

Tout ce qui suit tourne sur Windows 11. Installer dans cet ordre :

### 2.1 Local (WP Engine) — environnement WordPress local

URL : https://localwp.com/

Télécharger l'installeur Windows, exécuter, accepter les défauts. Au premier lancement, Local va proposer de créer un site :

- **Site name** : `factur-x-dev`
- **Environment** : *Preferred* (laisse les versions par défaut WP/PHP/MySQL au plus récent)
- **WordPress username** : `admin` / mot de passe au choix / email factice

Après création, dans Local → onglet WP Admin, **installer et activer le plugin WooCommerce** depuis Extensions → Ajouter une extension → rechercher « WooCommerce » → Installer → Activer. Lancer le wizard de setup (peu importe les réponses, c'est juste pour avoir une boutique opérationnelle).

**Activer HPOS** (High-Performance Order Storage) : WooCommerce → Réglages → Avancé → Fonctionnalités → cocher « High-Performance order storage » → Enregistrer. *C'est non négociable pour un plugin moderne, on doit dev avec HPOS activé.*

### 2.2 VS Code + extensions

URL : https://code.visualstudio.com/

Extensions à installer (Ctrl+Shift+X dans VS Code, rechercher chaque nom) :
- **PHP Intelephense** (bmewburn) — complétion PHP indispensable
- **PHP Debug** (Xdebug, par Xdebug) — pour les breakpoints
- **WordPress Snippets** (wpcodevo) — snippets WP utiles
- **Prettier - Code formatter** — formatage auto
- **GitLens** — confort Git

### 2.3 Git for Windows

URL : https://git-scm.com/download/win

Installer avec les défauts. Configurer une fois pour toutes dans un terminal :
```
git config --global user.name "Mathis Deroy"
git config --global user.email "mathis.deroy@gmail.com"
```

### 2.4 Composer (PHP package manager)

URL : https://getcomposer.org/Composer-Setup.exe

Installer Composer-Setup.exe. Au cours de l'install, il demande où se trouve `php.exe` : pointer vers le PHP de Local, typiquement :
```
C:\Users\mathis.deroy\AppData\Local\Programs\Local\resources\extraResources\lightning-services\php-8.x.x+x\bin\win64\php.exe
```

Vérifier dans un nouveau terminal :
```
composer --version
```

### 2.5 WP-CLI (optionnel mais utile)

URL : https://wp-cli.org/

Téléchargement direct : https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

Sauvegarder le `.phar` dans un dossier dans le PATH (ex. `C:\bin\`) et créer un fichier `wp.bat` à côté contenant :
```
@echo off
php "%~dp0wp-cli.phar" %*
```

### 2.6 Création du dossier du plugin

Depuis Local, clic droit sur ton site → « Go to site folder ». Naviguer jusqu'à `app/public/wp-content/plugins/`. Créer un dossier vide :

```
factur-x-for-woocommerce/
```

Ouvrir ce dossier dans VS Code (`File → Open Folder` ou clic droit Windows → « Open with Code »).

Initialiser Git dans le terminal intégré de VS Code (Ctrl+`) :
```
git init
git branch -M main
```

### 2.7 Outils de validation Factur-X (à garder sous le coude pour tester)

- **Validateur officiel FNFE-MPE** (en ligne, drag & drop d'un PDF Factur-X) : https://services.fnfe-mpe.org/
- **XSD + Schematron officiels** à télécharger : https://fnfe-mpe.org/factur-x/ (pack Factur-X 1.08, gratuit)
- **veraPDF** (validateur PDF/A open source) : https://verapdf.org/ — pour valider la conformité PDF/A-3 hors ligne

---

## 3. Stack technique cible

| Élément | Version / choix |
|---|---|
| OS dev | Windows 11 |
| Env local | Local (WP Engine) |
| WordPress | 6.x (la dernière stable au moment du dev) |
| WooCommerce | 9.x ou 10.x, **avec HPOS activé** |
| PHP | **8.0 minimum, idéalement 8.1+** (les libs Factur-X récentes l'exigent) |
| MySQL | par défaut Local (8.x) |
| Composer | dernière version |
| Format cible | **Factur-X 1.08 profil EN 16931** |
| Lib XML + PDF/A-3 | **`atgp/factur-x`** (référence FR, 758K+ installs Packagist, MIT, mature) |
| Moteur de rendu PDF | **TCPDF** (`tecnickcom/tcpdf`, exemples Factur-X officiels dans le repo) |
| Tests | Manuel + Plugin Check Plugin + validateur FNFE-MPE en ligne |

**Pas de Composer requis côté utilisateur final** : le `vendor/` sera embarqué dans le zip du plugin (pratique standard pour les plugins WP qui utilisent Composer).

**Pas de Gutenberg / React au MVP V0.1.** L'UI admin sera classique (Settings API + metaboxes WooCommerce). Les blocks Gutenberg viendront éventuellement en V0.5+ pour la personnalisation de l'invoice template.

---

## 4. Périmètre V0.1 — strict, à ne pas dépasser

### Ce qui DOIT être livré

1. **Custom Post Type interne `mathisfx_invoice`** (privé, non-public) pour stocker les factures générées, indexées par order_id WooCommerce.
2. **Génération automatique** sur le hook `woocommerce_order_status_completed` d'une facture Factur-X 1.08 profil EN 16931 :
   - PDF/A-3 hybride avec XML CII embarqué (fichier embarqué nommé `factur-x.xml` via `/AFRelationship /Alternative`).
   - Métadonnées XMP déclarant le profil EN 16931 et la version Factur-X.
   - Validation interne avant sauvegarde (parser le XML, vérifier la structure).
3. **Numérotation séquentielle inviolable** des factures (format `FYYYY-NNNNNN`, ex. `F2026-000001`), stockée en option WP, jamais réinitialisable, jamais avec trou.
4. **Champs B2B au checkout WooCommerce** :
   - Case à cocher « Je commande pour mon entreprise » dans la section facturation.
   - Si cochée : champs SIREN/SIRET + TVA intracommunautaire + raison sociale.
   - Validation SIREN/SIRET via l'API publique INSEE Sirene (https://api.insee.fr/) avec récupération automatique du nom légal et de l'adresse.
   - Validation TVA intra via l'API VIES de la Commission européenne (https://ec.europa.eu/taxation_customs/vies/).
5. **Sauvegarde de tous les champs B2B dans le post meta de la commande** (compatible HPOS, donc via `OrderUtil` et `$order->update_meta_data()`, jamais `update_post_meta` direct).
6. **Page d'admin Settings** (sous WooCommerce → Réglages → Factur-X) avec :
   - Coordonnées légales du vendeur (raison sociale, SIRET, TVA intra, adresse, code APE)
   - Mentions légales personnalisables sur les factures
   - Préfixe et compteur de numérotation
   - Toggle « Génération automatique à completed »
7. **Téléchargement de la facture Factur-X** depuis :
   - L'écran d'édition de la commande dans l'admin WC (bouton + lien dans une metabox)
   - Une colonne « Facture » dans la liste des commandes
8. **Attachement automatique** de la facture Factur-X à l'email WooCommerce « Commande terminée » envoyé au client.
9. **Désinstallation propre** via `uninstall.php` : suppression des options du plugin, des post meta `mathisfx_*`, du compteur de numérotation. **Conservation des factures** dans `wp-content/uploads/factur-x/` (les fichiers PDF restent — c'est un document légal).
10. **Conformité HPOS déclarée** via `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__)`.
11. **i18n complète** : tous les textes affichés passent par `__()` / `_e()`, text domain `factur-x-for-woocommerce`. Fichier `.pot` généré dans `languages/`.
12. **`readme.txt` au format WordPress.org** dès le premier commit (même incomplet, pour s'habituer au format).

### Ce qui ne doit PAS être livré en V0.1

- Routage vers une PA (réservé V0.5 Pro)
- E-reporting B2C automatique (réservé V0.5 Pro)
- Avoirs / credit notes (réservé V0.2)
- Personnalisation visuelle de l'invoice template via Gutenberg (V0.5+)
- Multi-boutique / multi-entité (V1.0)
- Réception de factures fournisseur (V1.0)
- Archivage eIDAS qualifié (V1.0 option payante)
- Profil EXTENDED-CTC-FR (V0.5 pour le routage PA)
- Système de licence Freemius (V0.5 quand on lance le Pro)

---

## 5. Architecture des fichiers

```
factur-x-for-woocommerce/
├── factur-x-for-woocommerce.php          # Fichier principal, header WP + bootstrap
├── uninstall.php                          # Nettoyage à la désinstallation
├── readme.txt                             # Format WordPress.org
├── composer.json                          # Dépendances PHP
├── composer.lock
├── .gitignore                             # vendor/ exclu en dev, embarqué au build
├── includes/
│   ├── class-plugin.php                   # Classe principale, singleton, bootstrap
│   ├── class-activator.php                # Logique à l'activation du plugin
│   ├── class-deactivator.php              # Logique à la désactivation
│   ├── class-settings.php                 # Page de réglages (Settings API)
│   ├── class-invoice-post-type.php        # CPT mathisfx_invoice (privé)
│   ├── class-invoice-numbering.php        # Numérotation séquentielle
│   ├── class-invoice-generator.php        # Orchestrateur génération facture
│   ├── class-pdf-renderer.php             # Wrapper TCPDF (rendu PDF lisible)
│   ├── class-xml-builder.php              # Wrapper atgp/factur-x (XML CII + embed)
│   ├── class-siret-validator.php          # Appel API INSEE Sirene
│   ├── class-vies-validator.php           # Appel API VIES
│   ├── class-checkout-fields.php          # Ajout des champs B2B au checkout
│   ├── class-order-meta.php               # Sauvegarde/lecture meta commande (HPOS-compatible)
│   ├── class-admin-orders.php             # Colonne + actions dans la liste des commandes
│   ├── class-admin-order-metabox.php      # Metabox sur l'écran d'édition de commande
│   └── class-email.php                    # Attachement de la facture à l'email WC
├── templates/
│   └── invoice/
│       └── default.php                    # Template PHP du PDF lisible (rendu par TCPDF)
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── checkout.css
│   └── js/
│       └── checkout.js                    # JS léger pour le toggle case B2B
├── languages/
│   └── factur-x-for-woocommerce.pot       # Fichier de traduction modèle
└── vendor/                                # Composer (embarqué au build)
```

---

## 6. Conventions non négociables

### 6.1 Préfixage
- **Fonctions, hooks custom, options, transients, meta keys, classes CSS** : préfixe `mathisfx_` (raccourci de « Mathis Factur-X »).
- **Classes PHP** : namespace `Mathis\FacturX\WooCommerce\`.
- Sans préfixe : collisions garanties avec un autre plugin et plantage.

### 6.2 Sécurité (non négociable)
- **Sanitize les entrées** : `sanitize_text_field`, `esc_url_raw`, `sanitize_textarea_field`, `absint`, `wp_unslash` avant tout traitement de `$_POST`/`$_GET`.
- **Escape les sorties** : `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` selon le contexte. **Jamais** d'echo direct d'une variable.
- **Nonces** sur tous les formulaires admin (`wp_nonce_field` + `wp_verify_nonce`).
- **Capability checks** avant toute action sensible (`current_user_can('manage_woocommerce')` pour les actions admin du plugin).
- **Validation SIRET côté serveur** uniquement (jamais faire confiance à une validation JS côté client).
- **Pas de SQL brut** sans `$wpdb->prepare()`. Préférer `WP_Query` ou les classes WC (`wc_get_order`, `wc_get_orders`).

### 6.3 HPOS (High-Performance Order Storage)
- Déclaration de compatibilité **obligatoire** dans le bootstrap :
  ```php
  add_action('before_woocommerce_init', function() {
      if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
          \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
              'custom_order_tables', __FILE__, true
          );
      }
  });
  ```
- **Toute lecture/écriture de meta commande** passe par `$order->get_meta()` / `$order->update_meta_data()` / `$order->save()`. **Jamais** `get_post_meta($order_id, ...)` ou `update_post_meta($order_id, ...)` directement sur une commande WooCommerce — ça casse en HPOS.

### 6.4 Code style
- WordPress Coding Standards (PHPCS-friendly) : indentation tab, accolades K&R, snake_case pour fonctions/variables, PascalCase pour classes.
- Commentaires de code **en anglais** (norme WP), explications de fonctionnement à moi **en français**.

### 6.5 i18n
- Tout texte affiché passe par `__('...', 'factur-x-for-woocommerce')` ou `_e()`.
- Text domain = `factur-x-for-woocommerce`.
- Préparer un dossier `languages/` et générer un fichier `.pot` au moins en V0.1.

### 6.6 Assets
- Charger CSS et JS **uniquement via** `wp_enqueue_style` / `wp_enqueue_script` avec dépendances et versions. Jamais de `<script>` ou `<link>` en dur dans le HTML.
- Charger les assets de checkout uniquement sur la page checkout (`is_checkout()` ou `is_cart()` selon le besoin), pas partout.

### 6.7 Compatibilité
- **PHP 8.0 minimum** (les libs Factur-X récentes l'imposent ; et c'est largement adopté en 2026).
- **WordPress -2 à dernière version** (compatibilité descendante de 2 versions majeures).
- **WooCommerce -2 à dernière version**.
- Tester au minimum sur les thèmes **Storefront** et **Astra** (les deux thèmes WC les plus utilisés). Idéalement aussi **OceanWP** et **Flatsome**.

---

## 7. Dépendances PHP (à installer via Composer)

### `composer.json` initial à proposer

```json
{
    "name": "mathisderoy/factur-x-for-woocommerce",
    "description": "Génère des factures Factur-X conformes à la réforme française 2026 depuis WooCommerce",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.0",
        "atgp/factur-x": "^2.4",
        "tecnickcom/tcpdf": "^6.7"
    },
    "autoload": {
        "psr-4": {
            "Mathis\\FacturX\\WooCommerce\\": "includes/"
        }
    },
    "config": {
        "vendor-dir": "vendor",
        "optimize-autoloader": true,
        "platform": {
            "php": "8.0.0"
        }
    }
}
```

### Notes sur les libs
- **`atgp/factur-x`** est la référence française : génération XML, embed PDF/A-3, validation XSD. Très active (v2.4.1 nov. 2025). MIT. Voir : https://github.com/atgp/factur-x
- **`tecnickcom/tcpdf`** : pour le rendu PDF de base (la facture lisible humain). Le repo TCPDF contient des exemples Factur-X officiels. Voir : https://tcpdf.org/
- **Alternative à `atgp/factur-x`** : `horstoeko/zugferd` qui couvre tous les profils (MINIMUM → EXTENDED), API plus riche. À évaluer si on a besoin du profil EXTENDED en V0.5. Pour le MVP, `atgp/factur-x` suffit.

### Installation

Une fois le `composer.json` créé :
```
composer install
```

Et créer un `.gitignore` qui exclut `vendor/` pendant le dev (on le rebuild à la livraison) :
```
vendor/
composer.phar
*.log
.DS_Store
.idea/
.vscode/
```

---

## 8. Méthodes de test

### 8.1 Validation du Factur-X généré

**Test n° 1 — validateur officiel FNFE-MPE en ligne** :
- URL : https://services.fnfe-mpe.org/
- Drag & drop du PDF généré.
- Doit retourner « PDF valide Factur-X profil EN 16931 ».
- Si erreur XSD ou Schematron : le message est explicite, corriger en conséquence.

**Test n° 2 — validation locale du XML CII contre le XSD** :
- Télécharger le pack Factur-X 1.08 depuis https://fnfe-mpe.org/factur-x/ (zip avec XSD + Schematron + exemples).
- Extraire le `factur-x.xml` du PDF généré (avec `pdftk` ou manuellement).
- Valider via `xmllint` :
  ```
  xmllint --schema Factur-X_1.08_EN16931.xsd factur-x.xml --noout
  ```

**Test n° 3 — conformité PDF/A-3 via veraPDF** :
- URL : https://verapdf.org/
- Drag & drop du PDF généré.
- Vérifier qu'il est annoncé « valid PDF/A-3 ».

### 8.2 Validation du plugin côté WordPress.org

**Plugin Check Plugin (PCP)** — l'outil officiel WP.org :
- Installer depuis WordPress.org : https://wordpress.org/plugins/plugin-check/
- Activer dans Local, puis Outils → Plugin Check.
- Sélectionner notre plugin → Run Checks.
- **Zéro warning bloquant** avant chaque soumission WP.org.

### 8.3 Débogage WordPress

Dans `wp-config.php` du site Local (le fichier est dans `app/public/wp-config.php`), activer :
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);
```

Tous les warnings et erreurs PHP iront dans `wp-content/debug.log`. Le consulter régulièrement.

### 8.4 Tests scenarios manuels (à faire à chaque jalon)

Ces tests doivent tous passer avant de considérer la V0.1 livrable :

1. **Commande B2C simple** (sans cocher la case entreprise) : la facture Factur-X doit être générée, le XML doit contenir une ligne de TVA correcte, le PDF doit être lisible.
2. **Commande B2B avec SIRET valide** (cocher la case, saisir un vrai SIRET d'entreprise, ex. `42068884900014` qui est le SIRET de Pennylane) : validation OK, nom légal récupéré automatiquement, champs SIRET/TVA présents dans le XML CII.
3. **Commande B2B avec SIRET invalide** (ex. `00000000000000`) : validation rejette, message d'erreur clair, commande peut être passée mais sans validation B2B.
4. **Commande B2B avec TVA intra valide** (ex. `FR42424242424` pour un vrai numéro existant) : validation VIES OK.
5. **Commande B2B avec TVA intra invalide** (ex. `FR99999999999`) : validation VIES rejette, message clair.
6. **Commande multi-taux TVA** (un produit à 20 %, un à 5,5 %, un à 0 %) : XML CII doit contenir 3 lignes `<ram:ApplicableTradeTax>` distinctes, totaux corrects.
7. **Commande avec remise / code promo** : le total HT et la TVA doivent rester cohérents après remise.
8. **Commande à 0 €** (cas limite, ex. commande gratuite ou 100 % avec coupon) : la facture doit quand même être générée et valide.
9. **Numérotation séquentielle** : passer 5 commandes consécutives, vérifier que les numéros se suivent sans trou (`F2026-000001`, `F2026-000002`, etc.).
10. **Téléchargement depuis l'admin** : ouvrir une commande dans l'admin WC, cliquer sur le lien « Télécharger Factur-X », le PDF doit s'ouvrir.
11. **Email à la commande** : passer une commande, recevoir l'email « Commande terminée », vérifier que la pièce jointe Factur-X est bien présente.
12. **Désactivation puis réactivation** du plugin : aucune erreur dans le `debug.log`, les factures déjà générées doivent toujours être téléchargeables.
13. **Désinstallation complète** : options et meta supprimés, mais les fichiers PDF des factures déjà générées doivent rester dans `wp-content/uploads/factur-x/`.

### 8.5 Tests de compatibilité

- **HPOS activé** (cas par défaut) : tout doit fonctionner.
- **HPOS désactivé** (rétrocompatibilité ancien storage) : tout doit aussi fonctionner.
- **Thème Storefront** (par défaut WC) : checkout B2B s'affiche correctement.
- **Thème Astra** : idem.
- **Avec WooCommerce Subscriptions installé** (si on a une licence) : pas de conflit, pas de notice PHP.
- **Avec WP Overnight PDF Invoices installé** (le concurrent) : pas de conflit (les deux peuvent coexister sans casser).

### 8.6 Linter PHP

Installer PHP_CodeSniffer avec les WordPress Coding Standards :
```
composer global require "squizlabs/php_codesniffer=*"
composer global require wp-coding-standards/wpcs
phpcs --config-set installed_paths %USERPROFILE%/AppData/Roaming/Composer/vendor/wp-coding-standards/wpcs
```

Lancer le sniff régulièrement :
```
phpcs --standard=WordPress includes/
```

Cible : zéro warning sur les règles `WordPress.Security.*` et `WordPress.WP.*`.

---

## 9. Règles de collaboration avec moi

- **Réponds-moi en français.** Code et commentaires de code en anglais (norme WP), explications de fonctionnement en français.
- **Vérifie systématiquement les fonctions WordPress / WooCommerce** que tu utilises sur :
  - https://developer.wordpress.org/ pour les fonctions WP core
  - https://developer.woocommerce.com/ pour les hooks WC
  - https://woocommerce.github.io/code-reference/ pour les classes/méthodes WC
  
  Tu as tendance à inventer des fonctions WP qui n'existent pas, à proposer du code jQuery alors que WP a son propre wrapper, à utiliser `add_option` au lieu de `update_option`, à ignorer HPOS. Quand un doute existe, dis-le explicitement et propose de vérifier la doc avant d'écrire le code.
- **Code minimaliste et lisible avant tout.** Pas de sur-ingénierie, pas de patterns enterprise inutiles. C'est un MVP.
- **Explique brièvement chaque choix non évident** (en français) : pourquoi ce hook, pourquoi cette structure, pourquoi cette fonction.
- **Demande avant** d'ajouter une dépendance ou de prendre une décision d'architecture qui m'engagerait long terme.
- **Si je te demande un concept** (« c'est quoi un hook ? », « c'est quoi un nonce ? », « c'est quoi HPOS ? »), explique-le simplement en français, pas une copie de la doc.
- **Présente les changements** : quand tu modifies plusieurs fichiers, fais un récap en fin de réponse des fichiers touchés et de ce qui a changé.
- **Travail itératif** : ne livre pas tout d'un coup. À chaque étape majeure de la tâche d'amorce, on valide ensemble, on teste, on commit Git, on passe à la suivante.

---

## 10. Tâche d'amorce — séquence step by step

Exécute les étapes suivantes dans l'ordre. **À la fin de chaque étape, montre-moi ce qui a été fait, on teste ensemble, on commit Git, puis on passe à la suivante.**

### Étape 1 — Initialisation du squelette
1. Créer le fichier principal `factur-x-for-woocommerce.php` avec le header WP standard (Plugin Name, Description, Version 0.1.0, Author, License GPL-2.0+, Requires PHP 8.0, Requires WordPress 6.0, WC requires at least 9.0, WC tested up to 10.x, Text Domain `factur-x-for-woocommerce`).
2. Créer `composer.json` selon la section 7 et lancer `composer install`.
3. Créer `uninstall.php`, `readme.txt` (squelette), `.gitignore`.
4. Créer l'arborescence vide des dossiers `includes/`, `templates/invoice/`, `assets/css/`, `assets/js/`, `languages/`.
5. Dans le fichier principal : déclarer le namespace, charger `vendor/autoload.php`, déclarer la compatibilité HPOS, instancier la classe principale `Mathis\FacturX\WooCommerce\Plugin` au hook `plugins_loaded`.
6. Créer `includes/class-plugin.php` minimal (singleton, méthode `init()` qui ne fait rien encore).

**Test attendu** : le plugin s'active sans erreur dans WordPress, n'apparaît rien dans le `debug.log`.

### Étape 2 — Page de réglages
1. Créer `includes/class-settings.php` : page sous WooCommerce → Réglages → onglet « Factur-X » avec les champs vendeur (raison sociale, SIRET, TVA intra, adresse complète, code APE), les mentions légales, le préfixe de numérotation, et le toggle « Génération automatique à completed ».
2. Utiliser la Settings API native WordPress, jamais réinventer le formulaire.
3. Sanitization à la sauvegarde, nonces, capability check (`manage_woocommerce`).

**Test attendu** : je peux remplir mes coordonnées vendeur, sauvegarder, recharger, les valeurs sont conservées.

### Étape 3 — CPT facture + numérotation
1. Créer `includes/class-invoice-post-type.php` : enregistre le CPT `mathisfx_invoice` (privé, non-public, ne s'affiche pas en menu).
2. Créer `includes/class-invoice-numbering.php` : génère le prochain numéro de facture sous forme `F2026-000001` à partir d'une option WP `mathisfx_invoice_counter` incrémentée atomiquement (transient ou lock). Numéro jamais réinitialisé, jamais avec trou.

**Test attendu** : je peux appeler la méthode `get_next_invoice_number()` plusieurs fois, les numéros se suivent sans trou.

### Étape 4 — Champs B2B au checkout
1. Créer `includes/class-checkout-fields.php` : ajoute via `woocommerce_after_checkout_billing_form` une case à cocher « Je commande pour mon entreprise » + champs SIREN/SIRET, TVA intra, raison sociale (initialement cachés via CSS, montrés en JS quand la case est cochée).
2. Créer `assets/js/checkout.js` : toggle pur, pas de jQuery sauf si déjà chargé par WC.
3. Créer `includes/class-siret-validator.php` : appel API INSEE Sirene (clé API à demander gratuitement sur https://api.insee.fr/, à stocker en option dans les réglages). Validation côté serveur via AJAX au blur du champ SIRET.
4. Créer `includes/class-vies-validator.php` : appel API VIES (pas de clé requise, mais SOAP/REST à vérifier dans la doc actuelle). Validation côté serveur au blur du champ TVA intra.
5. Créer `includes/class-order-meta.php` : à la création de la commande (`woocommerce_checkout_create_order`), sauvegarde les champs B2B dans le meta de la commande via `$order->update_meta_data()` (HPOS-compatible).

**Test attendu** : passer une commande en cochant la case, avec un vrai SIRET (Pennylane : `42068884900014`), la raison sociale s'affiche automatiquement et la commande sauve tous les champs.

### Étape 5 — Génération Factur-X
1. Créer `templates/invoice/default.php` : template PHP du PDF lisible (HTML/PHP, mise en page facture classique avec logo vendeur, coordonnées vendeur, coordonnées client, lignes de produits, totaux HT/TVA/TTC, mentions légales).
2. Créer `includes/class-pdf-renderer.php` : wrapper TCPDF qui prend les données commande + template et produit un PDF/A-3.
3. Créer `includes/class-xml-builder.php` : wrapper `atgp/factur-x` qui construit le XML CII profil EN 16931 à partir des données commande (vendeur, acheteur, lignes, TVA par taux, totaux).
4. Créer `includes/class-invoice-generator.php` : orchestrateur. Hook sur `woocommerce_order_status_completed`. Appelle le PDF renderer + le XML builder, combine via `atgp/factur-x` en PDF/A-3 hybride, sauvegarde dans `wp-content/uploads/factur-x/{year}/{month}/{filename}.pdf`, crée un post du CPT `mathisfx_invoice` lié à l'order_id, sauvegarde le chemin du fichier en post meta.

**Test attendu** : passer une commande, status passe à completed, un PDF Factur-X est généré dans `wp-content/uploads/factur-x/`, validé par le validateur officiel FNFE-MPE (https://services.fnfe-mpe.org/) comme « PDF valide Factur-X profil EN 16931 ».

### Étape 6 — Admin (téléchargement + colonne + metabox)
1. Créer `includes/class-admin-orders.php` : ajoute une colonne « Facture » dans la liste des commandes (HPOS-compatible, via le hook `manage_woocommerce_page_wc-orders_custom_column`) avec un lien de téléchargement.
2. Créer `includes/class-admin-order-metabox.php` : metabox sur l'écran d'édition de commande avec bouton « Télécharger Factur-X » et bouton « Régénérer la facture » (avec nonce).

**Test attendu** : je peux télécharger une facture déjà générée depuis l'admin, et je peux régénérer une facture si besoin.

### Étape 7 — Email à la commande
1. Créer `includes/class-email.php` : hook sur `woocommerce_email_attachments` pour attacher la facture Factur-X à l'email « Commande terminée » envoyé au client.

**Test attendu** : passer une commande, recevoir l'email avec la facture en pièce jointe.

### Étape 8 — Finitions V0.1
1. Génération du fichier `.pot` dans `languages/` (via WP-CLI : `wp i18n make-pot . languages/factur-x-for-woocommerce.pot`).
2. Compléter `readme.txt` au format WordPress.org (Tags, Requires at least, Tested up to, Description, Installation, FAQ, Changelog, Screenshots).
3. Lancer le **Plugin Check Plugin** sur le plugin et corriger tous les warnings bloquants.
4. Vérifier les **13 tests scenarios manuels** de la section 8.4. Tous doivent passer.
5. Lancer **PHPCS WordPress Coding Standards** et corriger les warnings sécurité.
6. Commit final tag `v0.1.0` sur Git.

**Test attendu** : tous les critères de validation V0.1 (section 11) sont remplis.

---

## 11. Critères de validation V0.1 — à cocher avant de dire « c'est fini »

- [ ] Le plugin s'active sans erreur, rien dans `debug.log`.
- [ ] La page de réglages WooCommerce → Réglages → Factur-X est accessible et persistante.
- [ ] Les champs B2B au checkout s'affichent correctement quand on coche la case entreprise.
- [ ] La validation SIRET (API INSEE) récupère bien le nom légal et l'adresse.
- [ ] La validation TVA intra (API VIES) fonctionne.
- [ ] Passer une commande déclenche la génération automatique d'un PDF Factur-X dans `wp-content/uploads/factur-x/`.
- [ ] **Le PDF généré est validé par le validateur officiel FNFE-MPE comme « valid Factur-X EN 16931 ».**
- [ ] **Le PDF est validé par veraPDF comme « valid PDF/A-3 ».**
- [ ] La numérotation séquentielle fonctionne sans trou.
- [ ] Le bouton de téléchargement dans l'admin WC fonctionne.
- [ ] L'email « Commande terminée » contient bien la facture Factur-X en pièce jointe.
- [ ] Les 13 tests scenarios manuels (section 8.4) passent tous.
- [ ] HPOS activé : tout fonctionne. HPOS désactivé : tout fonctionne aussi.
- [ ] Le Plugin Check Plugin retourne zéro warning bloquant.
- [ ] PHPCS WordPress Coding Standards retourne zéro warning sécurité.
- [ ] `readme.txt` au format WP.org est complet.
- [ ] Le fichier `.pot` est généré dans `languages/`.
- [ ] La désinstallation supprime bien les options/meta mais conserve les PDF générés.
- [ ] Tag Git `v0.1.0` posé.

**Quand tous les critères sont cochés, on est prêts à soumettre le plugin à WordPress.org.**

---

## Si une question essentielle n'a pas de réponse claire dans ce prompt, **pose-la-moi avant de coder.**
