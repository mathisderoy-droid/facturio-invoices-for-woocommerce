=== Factur-X for WooCommerce ===
Contributors: mathisderoy
Tags: factur-x, facturation électronique, woocommerce, e-invoicing, france
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Génère automatiquement des factures Factur-X (PDF/A-3 + XML EN 16931) conformes à la réforme française 2026 depuis vos commandes WooCommerce.

== Description ==

**Factur-X for WooCommerce** transforme chaque commande WooCommerce en une facture **Factur-X** conforme : un PDF lisible par un humain, avec à l'intérieur un fichier XML structuré (profil EN 16931) que les logiciels comptables et l'administration fiscale peuvent lire automatiquement. C'est le format pivot retenu par la France pour la réforme de la facturation électronique de 2026-2027.

Le plugin est pensé pour les boutiques WooCommerce françaises qui vendent à des professionnels (B2B) et doivent émettre des factures électroniques conformes, sans changer de logiciel comptable.

= Fonctionnalités de la version gratuite (V0.1) =

* **Génération automatique** d'une facture Factur-X profil **EN 16931** au passage de la commande au statut « en cours » ou « terminée » (au choix).
* **PDF/A-3 hybride** : le XML CII (`factur-x.xml`) est embarqué dans le PDF, avec les métadonnées XMP Factur-X et la relation `/Alternative` exigées par la norme.
* **Validation des numéros professionnels au paiement** :
  * SIRET vérifié en direct via l'**API INSEE Sirene** (récupération automatique de la raison sociale).
  * Numéro de **TVA intracommunautaire** vérifié via le service **VIES** de la Commission européenne.
* **Numérotation séquentielle inviolable** (format `F2026-000001`), sans trou ni doublon, conforme à l'obligation légale française.
* **Champs B2B au checkout** : case « Je commande pour mon entreprise » + raison sociale, SIRET, TVA, code APE.
* **Logo et couleur principale** personnalisables sur la facture.
* **Téléchargement** de la facture depuis la liste des commandes et l'écran d'édition de commande, avec possibilité de **régénération**.
* **Envoi automatique** de la facture en pièce jointe de l'e-mail de commande adressé au client.
* **Compatible HPOS** (High-Performance Order Storage) de WooCommerce.
* **Interface en français**, prête pour la traduction (fichier `.pot` fourni).

= Ce que la version gratuite ne fait pas (encore) =

* Routage automatique vers une **Plateforme Agréée (PA / ex-PDP)** — prévu pour la version Pro.
* **E-reporting B2C** automatique — prévu pour la version Pro.
* Avoirs / factures d'acompte, profil EXTENDED-CTC-FR, multi-boutique — prévus pour les versions ultérieures.

= Validation de conformité =

Les factures générées sont conçues pour passer le **validateur officiel FNFE-MPE** (https://services.fnfe-mpe.org/) : PDF/A-3 valide, XMP valide, XML valide contre le XSD et le Schematron EN 16931.

== Installation ==

1. Installez et activez le plugin depuis l'écran « Extensions » de WordPress.
2. Activez **WooCommerce** s'il ne l'est pas déjà.
3. Rendez-vous dans **WooCommerce → Réglages → Factur-X**.
4. Renseignez vos **coordonnées vendeur** : raison sociale, SIRET, TVA intracommunautaire, adresse, code APE, mentions légales.
5. Dans l'onglet **Intégrations**, collez votre **clé API INSEE Sirene** (gratuite, à demander sur https://portail-api.insee.fr/) pour activer la validation SIRET au paiement.
6. Dans l'onglet **Apparence**, choisissez votre logo et votre couleur principale.
7. Choisissez le **statut déclencheur** de la génération automatique (« en cours » ou « terminée »).

C'est prêt : chaque nouvelle commande atteignant ce statut génère sa facture Factur-X.

== Frequently Asked Questions ==

= Ai-je besoin d'une clé API pour que le plugin fonctionne ? =

Le plugin génère des factures Factur-X conformes sans aucune clé. La clé **INSEE Sirene** (gratuite) sert uniquement à vérifier les SIRET en direct au paiement et à pré-remplir la raison sociale. Sans elle, seul le format du SIRET est contrôlé localement.

= Le plugin route-t-il mes factures vers une Plateforme Agréée ? =

Pas dans la version gratuite. La V0.1 produit la facture Factur-X conforme et l'archive. Le routage vers les Plateformes Agréées (Iopole, B2Brouter, Pennylane…) est prévu pour la version Pro.

= Mes factures déjà générées sont-elles supprimées si je désinstalle le plugin ? =

Non. La désinstallation supprime les réglages et les métadonnées du plugin, mais **conserve les fichiers PDF** dans `wp-content/uploads/factur-x/` car ce sont des documents légaux à archiver.

= Le plugin est-il compatible avec le stockage haute performance des commandes (HPOS) ? =

Oui, la compatibilité HPOS est déclarée et toutes les lectures/écritures de commande passent par l'API WooCommerce.

= La validation de TVA affiche « service indisponible », est-ce un bug ? =

Non. Le service VIES de l'Union européenne (et particulièrement le registre français) limite le nombre de requêtes simultanées et tombe régulièrement. Le plugin réessaie automatiquement, et la commande n'est jamais bloquée : seul le contrôle de format local est requis.

== Screenshots ==

1. Réglages des coordonnées vendeur (WooCommerce → Réglages → Factur-X).
2. Champs B2B et validation SIRET en direct au paiement.
3. Facture Factur-X générée (PDF lisible + XML embarqué).
4. Colonne « Facture » et téléchargement depuis la liste des commandes.

== Changelog ==

= 0.1.0 =
* Version initiale.
* Génération automatique de factures Factur-X profil EN 16931 (PDF/A-3 + XML CII embarqué).
* Validation SIRET (INSEE Sirene) et TVA intracommunautaire (VIES) au paiement.
* Numérotation séquentielle inviolable.
* Champs B2B au checkout, compatibles HPOS.
* Logo et couleur personnalisables.
* Téléchargement et régénération depuis l'admin, pièce jointe e-mail.

== Upgrade Notice ==

= 0.1.0 =
Version initiale du plugin.
