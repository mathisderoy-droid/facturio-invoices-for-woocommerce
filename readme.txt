=== Factur-X for WooCommerce ===
Contributors: mathisderoy
Tags: woocommerce, factur-x, facturation electronique, pdf invoice, e-invoicing
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Génère des factures Factur-X conformes à la réforme française 2026 depuis vos commandes WooCommerce (PDF/A-3 hybride + XML CII embarqué, profil EN 16931).

== Description ==

Plugin pour les boutiques WooCommerce françaises devant se conformer à la réforme de la facturation électronique 2026-2027. Génère automatiquement, sur le statut « commande terminée », une facture Factur-X 1.08 profil EN 16931 — un PDF/A-3 hybride qui contient à la fois un PDF lisible pour les humains et un XML CII structuré pour les machines, conforme à la norme européenne EN 16931 et reconnu par toutes les Plateformes Agréées (PA) accréditées par la DGFiP.

**Fonctionnalités V0.1 (gratuites)**

* Génération automatique de factures Factur-X conformes EN 16931 au statut « terminée » de la commande
* Validation SIREN / SIRET au checkout via l'API INSEE Sirene (récupération automatique du nom légal et de l'adresse)
* Validation TVA intracommunautaire via l'API VIES de la Commission européenne
* Numérotation séquentielle inviolable (format FYYYY-NNNNNN, sans trou, sans réinitialisation)
* Téléchargement des factures depuis l'admin WooCommerce
* Attachement automatique de la facture à l'email de commande terminée envoyé au client
* Compatible HPOS (High-Performance Order Storage)
* Conformité loi française anti-fraude 2018 (mentions légales obligatoires sur facture)
* Internationalisation : tous les textes traduisibles via le text domain factur-x-for-woocommerce

**À venir en version Pro (V0.5)**

* Routage automatique vers les Plateformes Agréées DGFiP (Iopole, B2Brouter, Pennylane)
* Profil EXTENDED-CTC-FR
* Dashboard de conformité
* E-reporting B2C automatique
* Avoirs (credit notes) Factur-X

== Installation ==

1. Téléverser le dossier `factur-x-for-woocommerce` dans `wp-content/plugins/` ou installer via Extensions → Ajouter une extension.
2. Activer l'extension dans le menu Extensions de WordPress.
3. Configurer les coordonnées légales du vendeur dans WooCommerce → Réglages → Factur-X.

== Frequently Asked Questions ==

= Le plugin fonctionne-t-il sans WooCommerce ? =

Non. Factur-X for WooCommerce est une extension WooCommerce et requiert WooCommerce 9.0 ou supérieur.

= Le plugin est-il compatible avec HPOS (High-Performance Order Storage) ? =

Oui, dès la V0.1. Le plugin déclare sa compatibilité via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)` et utilise exclusivement l'API objet `$order->get_meta()` / `$order->update_meta_data()` pour lire et écrire les metas de commande.

= Les factures générées sont-elles légalement valides en France ? =

Les factures suivent le format Factur-X 1.08 profil EN 16931, accepté par toutes les Plateformes Agréées de la DGFiP. Le PDF est conforme PDF/A-3 (ISO 19005-3) et le XML CII est validé contre les XSD officiels FNFE-MPE. Pour le routage effectif vers une PA (obligatoire à partir du 1er septembre 2026 pour la réception et selon la taille d'entreprise pour l'émission), la V0.5 Pro proposera des connecteurs directs.

== Changelog ==

= 0.1.0 =
* Version initiale (MVP gratuit).
* Génération automatique de Factur-X 1.08 profil EN 16931 au statut commande terminée.
* Champs B2B au checkout avec validation SIREN/SIRET (INSEE) et TVA intra (VIES).
* Numérotation séquentielle inviolable.
* Téléchargement et envoi par email de la facture.
* Compatibilité HPOS.

== Upgrade Notice ==

= 0.1.0 =
Version initiale.
