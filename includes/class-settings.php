<?php
/**
 * Settings page — adds a "Factur-X" tab to WooCommerce → Settings.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

use WC_Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce settings page for Factur-X plugin configuration.
 *
 * Three sub-sections rendered as horizontal links at the top of the tab:
 *   - Seller details  (legal entity info that goes into every invoice)
 *   - Invoicing       (numbering scheme + auto-generation toggle + legal text)
 *   - Integrations    (third-party API keys; INSEE Sirene for now)
 *
 * Every option ID is prefixed `mathisfx_` so uninstall.php can wipe them
 * in one pass.
 */
final class Settings extends WC_Settings_Page {

	/**
	 * Constructor — registers the tab inside WooCommerce → Settings.
	 *
	 * The parent constructor wires the woocommerce_settings_tabs_* filters
	 * automatically using $this->id and $this->label.
	 */
	public function __construct() {
		$this->id    = 'facturx';
		$this->label = __( 'Factur-X', 'factur-x-for-woocommerce' );
		parent::__construct();

		// Custom server-side sanitizers for fields that need more than wc_clean().
		add_filter(
			'woocommerce_admin_settings_sanitize_option_mathisfx_seller_siret',
			array( $this, 'sanitize_siret' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_mathisfx_seller_vat',
			array( $this, 'sanitize_vat' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_mathisfx_logo_attachment_id',
			array( $this, 'sanitize_attachment_id' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_mathisfx_primary_color',
			array( $this, 'sanitize_color' ),
			10,
			3
		);
		// The "Prochain numéro de facture" field is virtual: instead of being
		// stored as its own option, its save handler writes the real (per-year)
		// invoice counter and returns null so nothing is persisted for it.
		add_filter(
			'woocommerce_admin_settings_sanitize_option_mathisfx_invoice_next_number',
			array( $this, 'apply_invoice_next_number' ),
			10,
			3
		);

		// Custom WC settings field type for the Media Library image picker.
		add_action( 'woocommerce_admin_field_mathisfx_media_image', array( $this, 'render_media_image_field' ) );

		// Enqueue the picker JS + WP color picker on our Settings page only.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Sub-sections of the Factur-X tab.
	 *
	 * Empty-string key is the default section shown when the tab is clicked.
	 */
	public function get_sections(): array {
		$sections = array(
			''             => __( 'Coordonnées vendeur', 'factur-x-for-woocommerce' ),
			'invoicing'    => __( 'Facturation', 'factur-x-for-woocommerce' ),
			'appearance'   => __( 'Apparence', 'factur-x-for-woocommerce' ),
			'integrations' => __( 'Intégrations', 'factur-x-for-woocommerce' ),
		);

		return apply_filters( 'mathisfx_settings_sections', $sections );
	}

	/**
	 * Build the field array for the currently displayed section.
	 *
	 * Overrides WC_Settings_Page::get_settings_for_section_core() — the
	 * filtered, public-facing get_settings_for_section() wraps this method
	 * and adds the standard woocommerce_get_settings_{id} filter.
	 *
	 * @param string $current_section Section ID (empty for default).
	 */
	protected function get_settings_for_section_core( $current_section ): array {
		switch ( $current_section ) {
			case 'invoicing':
				$settings = $this->get_invoicing_settings();
				break;
			case 'appearance':
				$settings = $this->get_appearance_settings();
				break;
			case 'integrations':
				$settings = $this->get_integrations_settings();
				break;
			default:
				$settings = $this->get_seller_settings();
				break;
		}

		return apply_filters(
			'mathisfx_settings_fields',
			$settings,
			$current_section
		);
	}

	/**
	 * Appearance fields (logo + primary color).
	 *
	 * The logo is stored as a WP Media Library attachment id (`mathisfx_logo_attachment_id`)
	 * so we can resolve it to a local file path at render time — required by
	 * TCPDF in PDF/A-3 mode (no external HTTP fetches allowed).
	 *
	 * The primary color replaces every hardcoded `#2271b1` in the invoice
	 * template via a CSS variable substitution at render time.
	 */
	private function get_appearance_settings(): array {
		return array(
			array(
				'title' => __( 'Apparence de la facture', 'factur-x-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Personnalisez l\'identité visuelle de vos factures Factur-X. Ces réglages s\'appliquent immédiatement aux prochaines factures générées.', 'factur-x-for-woocommerce' ),
				'id'    => 'mathisfx_appearance_section',
			),
			array(
				'title'   => __( 'Logo', 'factur-x-for-woocommerce' ),
				'desc'    => __( 'Choisissez une image depuis votre Médiathèque. Format recommandé : JPG ou PNG opaque (sans transparence), ratio paysage, hauteur ≤ 80 px à l\'impression.', 'factur-x-for-woocommerce' ),
				'id'      => 'mathisfx_logo_attachment_id',
				'type'    => 'mathisfx_media_image',
				'default' => 0,
			),
			array(
				'title'    => __( 'Couleur principale', 'factur-x-for-woocommerce' ),
				'desc'     => __( 'Couleur des titres, du bandeau de tableau et du total TTC. Choisissez une couleur foncée — le texte par-dessus est blanc.', 'factur-x-for-woocommerce' ),
				'desc_tip' => true,
				'id'       => 'mathisfx_primary_color',
				'type'     => 'color',
				'default'  => '#2271b1',
				'css'      => 'width:80px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'mathisfx_appearance_section',
			),
		);
	}

	/**
	 * Seller / legal entity fields (default section).
	 */
	private function get_seller_settings(): array {
		return array(
			array(
				'title' => __( 'Coordonnées légales du vendeur', 'factur-x-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __(
					'Ces informations apparaissent sur chaque facture Factur-X générée. La raison sociale, le SIRET et l\'adresse sont obligatoires pour une facture conforme à la réforme française 2026.',
					'factur-x-for-woocommerce'
				),
				'id'    => 'mathisfx_seller_section',
			),
			array(
				'title'    => __( 'Raison sociale', 'factur-x-for-woocommerce' ),
				'id'       => 'mathisfx_seller_company_name',
				'type'     => 'text',
				'desc'     => __( 'Nom légal complet de l\'entité émettrice.', 'factur-x-for-woocommerce' ),
				'desc_tip' => true,
				'default'  => '',
				'css'      => 'min-width:350px;',
			),
			array(
				'title'             => __( 'SIRET', 'factur-x-for-woocommerce' ),
				'id'                => 'mathisfx_seller_siret',
				'type'              => 'text',
				'desc'              => __( '14 chiffres, sans espace (les espaces saisis seront supprimés à la sauvegarde).', 'factur-x-for-woocommerce' ),
				'desc_tip'          => true,
				'default'           => '',
				'css'               => 'width:200px;',
				'custom_attributes' => array(
					'maxlength'   => '17', // Allow spaces during typing; sanitizer strips them.
					'inputmode'   => 'numeric',
					'placeholder' => '00000000000000',
				),
			),
			array(
				'title'             => __( 'Numéro de TVA intracommunautaire', 'factur-x-for-woocommerce' ),
				'id'                => 'mathisfx_seller_vat',
				'type'              => 'text',
				'desc'              => __( 'Format français : FR suivi de 11 caractères.', 'factur-x-for-woocommerce' ),
				'desc_tip'          => true,
				'default'           => '',
				'css'               => 'width:200px;',
				'custom_attributes' => array(
					'placeholder' => 'FR00000000000',
				),
			),
			array(
				'title'    => __( 'Code APE / NAF', 'factur-x-for-woocommerce' ),
				'id'       => 'mathisfx_seller_ape_code',
				'type'     => 'text',
				'desc'     => __( 'Format INSEE : 4 chiffres + 1 lettre (ex. 6202A).', 'factur-x-for-woocommerce' ),
				'desc_tip' => true,
				'default'  => '',
				'css'      => 'width:120px;',
			),
			array(
				'title'    => __( 'Adresse', 'factur-x-for-woocommerce' ),
				'id'       => 'mathisfx_seller_address',
				'type'     => 'textarea',
				'desc'     => __( 'Numéro, rue, complément éventuel.', 'factur-x-for-woocommerce' ),
				'desc_tip' => true,
				'default'  => '',
				'css'      => 'min-width:350px; min-height:60px;',
			),
			array(
				'title'   => __( 'Code postal', 'factur-x-for-woocommerce' ),
				'id'      => 'mathisfx_seller_postal_code',
				'type'    => 'text',
				'default' => '',
				'css'     => 'width:100px;',
			),
			array(
				'title'   => __( 'Ville', 'factur-x-for-woocommerce' ),
				'id'      => 'mathisfx_seller_city',
				'type'    => 'text',
				'default' => '',
				'css'     => 'min-width:220px;',
			),
			array(
				'title'   => __( 'Pays', 'factur-x-for-woocommerce' ),
				'id'      => 'mathisfx_seller_country',
				'type'    => 'select',
				'options' => WC()->countries->get_countries(),
				'default' => 'FR',
				'class'   => 'wc-enhanced-select',
				'css'     => 'min-width:200px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'mathisfx_seller_section',
			),
		);
	}

	/**
	 * Invoicing fields (numbering scheme + auto-generation + legal mentions).
	 */
	private function get_invoicing_settings(): array {
		return array(
			array(
				'title' => __( 'Numérotation et génération', 'factur-x-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'La numérotation séquentielle est inviolable et ne doit jamais comporter de trou (obligation légale française).', 'factur-x-for-woocommerce' ),
				'id'    => 'mathisfx_invoicing_section',
			),
			array(
				'title'    => __( 'Préfixe de numérotation', 'factur-x-for-woocommerce' ),
				'id'       => 'mathisfx_invoice_prefix',
				'type'     => 'text',
				'desc'     => __( 'Lettres au début du numéro. Ex. « F » donne « F2026-000001 ».', 'factur-x-for-woocommerce' ),
				'desc_tip' => true,
				'default'  => 'F',
				'css'      => 'width:80px;',
			),
			array(
				'title'             => __( 'Padding du compteur', 'factur-x-for-woocommerce' ),
				'id'                => 'mathisfx_invoice_number_padding',
				'type'              => 'number',
				'desc'              => __( 'Nombre de chiffres du compteur, complété par des zéros à gauche (6 = "000001").', 'factur-x-for-woocommerce' ),
				'desc_tip'          => true,
				'default'           => '6',
				'custom_attributes' => array(
					'min' => '4',
					'max' => '10',
				),
				'css'               => 'width:80px;',
			),
			array(
				'title'   => __( 'Réinitialisation annuelle du compteur', 'factur-x-for-woocommerce' ),
				'desc'    => __( 'Le compteur repart à 1 le 1er janvier (recommandé en France).', 'factur-x-for-woocommerce' ),
				'id'      => 'mathisfx_invoice_reset_yearly',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'             => __( 'Prochain numéro de facture', 'factur-x-for-woocommerce' ),
				'id'                => 'mathisfx_invoice_next_number',
				'type'              => 'number',
				'desc'              => __( 'Numéro de la prochaine facture (saisissez seulement le chiffre : le préfixe et l\'année sont ajoutés automatiquement, ex. « F2026-000248 »). À ne modifier que pour reprendre une numérotation existante, par exemple lors d\'une migration depuis un autre outil. ⚠ N\'indiquez jamais un numéro inférieur ou égal au dernier déjà émis : cela créerait des doublons interdits.', 'factur-x-for-woocommerce' ),
				'default'           => (string) ( InvoiceNumbering::get_current_counter_value() + 1 ),
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'css'               => 'width:120px;',
			),
			array(
				'title'   => __( 'Génération automatique', 'factur-x-for-woocommerce' ),
				'desc'    => __( 'Générer la facture Factur-X automatiquement lors d\'un changement de statut de la commande.', 'factur-x-for-woocommerce' ),
				'id'      => 'mathisfx_auto_generate',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( 'Statut déclencheur', 'factur-x-for-woocommerce' ),
				'desc'     => __( 'Statut WooCommerce qui déclenche la génération.', 'factur-x-for-woocommerce' ),
				'desc_tip' => true,
				'id'       => 'mathisfx_auto_generate_status',
				'type'     => 'select',
				'options'  => array(
					'processing' => __( 'En cours (dès paiement reçu) — recommandé pour services et produits numériques', 'factur-x-for-woocommerce' ),
					'completed'  => __( 'Terminée (après livraison ou expédition) — recommandé pour produits physiques', 'factur-x-for-woocommerce' ),
				),
				'default'  => 'completed',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width:420px;',
			),
			array(
				'title'    => __( 'Mentions légales', 'factur-x-for-woocommerce' ),
				'id'       => 'mathisfx_legal_mentions',
				'type'     => 'textarea',
				'desc'     => __( 'Mentions ajoutées en pied de chaque facture (capital social, RCS, TVA non applicable art. 293 B, etc.).', 'factur-x-for-woocommerce' ),
				'desc_tip' => true,
				'default'  => '',
				'css'      => 'min-width:500px; min-height:120px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'mathisfx_invoicing_section',
			),
		);
	}

	/**
	 * Third-party API integrations.
	 */
	private function get_integrations_settings(): array {
		return array(
			array(
				'title' => __( 'API INSEE Sirene', 'factur-x-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s = URL INSEE */
					__( 'Demandez une clé API gratuite sur %s. Utilisée pour la validation SIRET au checkout et la récupération automatique de la raison sociale.', 'factur-x-for-woocommerce' ),
					'<a href="https://api.insee.fr/" target="_blank" rel="noopener noreferrer">api.insee.fr</a>'
				),
				'id'    => 'mathisfx_integrations_insee_section',
			),
			array(
				'title'    => __( 'Clé API INSEE', 'factur-x-for-woocommerce' ),
				'id'       => 'mathisfx_insee_api_key',
				'type'     => 'password',
				'desc'     => __( 'Laissez vide pour désactiver la validation SIRET en ligne (validation locale du format uniquement).', 'factur-x-for-woocommerce' ),
				'desc_tip' => true,
				'default'  => '',
				'css'      => 'min-width:400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'mathisfx_integrations_insee_section',
			),
		);
	}

	/**
	 * Strip non-digits from SIRET before save.
	 *
	 * Allows the user to type "123 456 789 00012" — we keep only the digits.
	 *
	 * @param mixed  $value     Sanitized value about to be saved.
	 * @param array  $option    The option definition array.
	 * @param mixed  $raw_value Raw POSTed value.
	 */
	public function sanitize_siret( $value, $option = array(), $raw_value = '' ): string {
		return preg_replace( '/\D+/', '', (string) $value );
	}

	/**
	 * Uppercase + strip spaces from VAT number.
	 *
	 * The actual format validation against country rules happens in Étape 4
	 * via the VIES API; here we just normalize.
	 *
	 * @param mixed  $value     Sanitized value about to be saved.
	 * @param array  $option    The option definition array.
	 * @param mixed  $raw_value Raw POSTed value.
	 */
	public function sanitize_vat( $value, $option = array(), $raw_value = '' ): string {
		return strtoupper( preg_replace( '/\s+/', '', (string) $value ) );
	}

	/**
	 * Make sure the attachment ID is a positive int that actually points
	 * to an image. Returns 0 on any mismatch (treated as "no logo").
	 */
	public function sanitize_attachment_id( $value, $option = array(), $raw_value = '' ): int {
		$id = absint( $value );
		if ( $id === 0 ) {
			return 0;
		}
		if ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $id ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * Validate hex color, fall back to the brand default on bad input.
	 */
	public function sanitize_color( $value, $option = array(), $raw_value = '' ): string {
		$clean = sanitize_hex_color( (string) $value );
		return $clean ?: '#2271b1';
	}

	/**
	 * Save handler for the virtual "Prochain numéro de facture" field.
	 *
	 * Instead of persisting an option, it writes the real (per-year) invoice
	 * counter so the next issued number matches what the merchant typed. A
	 * forward-only guard refuses any value at or below the last issued number
	 * (which would create duplicate, illegal invoice numbers) and surfaces a
	 * clear admin error. Returning null tells WC to NOT store this field.
	 *
	 * @param mixed $value     Cleaned value (unused; we read the raw POST).
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Raw POSTed value.
	 * @return null Always null → WC skips storing this virtual field.
	 */
	public function apply_invoice_next_number( $value, $option = array(), $raw_value = '' ) {
		$requested_next = absint( $raw_value );
		if ( $requested_next < 1 ) {
			return null; // Empty/invalid input: leave the counter untouched.
		}

		$last_issued = InvoiceNumbering::get_current_counter_value();

		// Unchanged value (field still shows last_issued + 1): nothing to do.
		if ( $requested_next === $last_issued + 1 ) {
			return null;
		}

		if ( ! InvoiceNumbering::is_acceptable_next_number( $requested_next, $last_issued ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: requested next number, 2: last issued number. */
					__( 'Numéro de facture inchangé : le prochain numéro (%1$d) doit être strictement supérieur au dernier numéro déjà émis (%2$d), sinon des factures porteraient le même numéro (interdit).', 'factur-x-for-woocommerce' ),
					$requested_next,
					$last_issued
				)
			);
			return null;
		}

		// Forward move: apply it (atomic write that never lowers the counter).
		InvoiceNumbering::set_current_counter_value( $requested_next - 1 );
		\WC_Admin_Settings::add_message(
			sprintf(
				/* translators: %d = the next invoice number now configured. */
				__( 'Prochain numéro de facture réglé sur %d.', 'factur-x-for-woocommerce' ),
				$requested_next
			)
		);
		return null;
	}

	/**
	 * Render the custom Media Library picker field for the logo.
	 *
	 * Invoked by WC_Admin_Settings::output_fields() when it encounters a
	 * field of type 'mathisfx_media_image'. Outputs an HTML row that fits
	 * inside the standard WC settings <table>.
	 *
	 * @param array $value Field definition (id, title, desc, default).
	 */
	public function render_media_image_field( array $value ): void {
		$field_id    = isset( $value['id'] ) ? (string) $value['id'] : '';
		$title       = isset( $value['title'] ) ? (string) $value['title'] : '';
		$description = isset( $value['desc'] ) ? (string) $value['desc'] : '';
		$current_id  = (int) get_option( $field_id, 0 );
		$thumb_url   = ( $current_id > 0 ) ? wp_get_attachment_image_url( $current_id, 'thumbnail' ) : '';

		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $title ); ?></label>
			</th>
			<td class="forminp">
				<div class="mathisfx-media-picker" data-field="<?php echo esc_attr( $field_id ); ?>">
					<input
						type="hidden"
						id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $field_id ); ?>"
						value="<?php echo esc_attr( (string) $current_id ); ?>"
					/>
					<div class="mathisfx-media-preview" style="margin-bottom:8px;min-height:84px;">
						<?php if ( $thumb_url !== '' ) : ?>
							<img src="<?php echo esc_url( $thumb_url ); ?>" style="max-width:160px;max-height:80px;border:1px solid #ddd;padding:4px;background:#fff;" />
						<?php endif; ?>
					</div>
					<button type="button" class="button mathisfx-media-choose">
						<?php
						echo $current_id > 0
							? esc_html__( 'Changer le logo', 'factur-x-for-woocommerce' )
							: esc_html__( 'Choisir un logo', 'factur-x-for-woocommerce' );
						?>
					</button>
					<button
						type="button"
						class="button mathisfx-media-remove"
						style="<?php echo $current_id > 0 ? '' : 'display:none;'; ?>"
					>
						<?php esc_html_e( 'Retirer', 'factur-x-for-woocommerce' ); ?>
					</button>
					<?php if ( $description !== '' ) : ?>
						<p class="description"><?php echo wp_kses_post( $description ); ?></p>
					<?php endif; ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Enqueue WP Media (modal), color picker, and our JS — only when the
	 * user is actually on WooCommerce → Settings → Factur-X.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( $hook !== 'woocommerce_page_wc-settings' ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab inspection
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		if ( $tab !== 'facturx' ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		wp_enqueue_script(
			'mathisfx-admin-settings',
			MATHISFX_PLUGIN_URL . 'assets/js/admin-settings.js',
			array( 'jquery', 'wp-color-picker' ),
			MATHISFX_VERSION,
			true
		);

		wp_localize_script(
			'mathisfx-admin-settings',
			'mathisfxAdminSettings',
			array(
				'mediaTitle'  => __( 'Choisir un logo', 'factur-x-for-woocommerce' ),
				'mediaButton' => __( 'Utiliser cette image', 'factur-x-for-woocommerce' ),
			)
		);
	}
}
