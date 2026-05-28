<?php
/**
 * Default invoice template — HTML rendered by TCPDF.
 *
 * Variables in scope (passed by PdfRenderer):
 *   @var array     $seller         (company_name, siret, vat, address, postal_code, city, country, ape, legal_mentions)
 *   @var array     $buyer          (name, siret, vat, address_lines[], postal_code, city, country, is_b2b)
 *   @var array     $invoice        (number, issue_date_display, due_date_display, currency_symbol)
 *   @var array     $lines          [ { name, sku, quantity, unit_price, vat_rate, line_total } ]
 *   @var array     $tax_breakdown  [ { rate, basis, tax } ]
 *   @var array     $totals         (line_total, tax_total, grand_total, due_payable, payment_method_title)
 *
 * Constraints:
 *   - TCPDF understands a subset of HTML/CSS: tables, basic colors, font-size,
 *     font-weight, text-align, padding, margin (limited), border. Avoid flexbox,
 *     grid, position:absolute beyond TCPDF's quirks, or external stylesheets.
 *   - All output MUST be escaped via esc_html(); the template is rendered server-side
 *     into a string buffered then passed to writeHTML().
 *
 * @package Mathis\FacturX\WooCommerce
 */

defined('ABSPATH') || exit;

?>
<style>
    body { font-family: helvetica, sans-serif; font-size: 9pt; color: #222; }
    h1 { font-size: 18pt; color: #2271b1; margin: 0; }
    h2 { font-size: 11pt; color: #2271b1; margin: 10px 0 4px 0; }
    table { border-collapse: collapse; width: 100%; }
    .header-bar { width: 100%; }
    .header-bar td { vertical-align: top; }
    .header-bar .seller { width: 60%; }
    .header-bar .invoice-meta { width: 40%; text-align: right; }
    .invoice-number { font-size: 14pt; font-weight: bold; color: #2271b1; }
    .label { color: #888; font-size: 8pt; }
    .parties { margin-top: 18px; width: 100%; }
    .parties td { vertical-align: top; width: 50%; padding-right: 8px; }
    .party-box { background-color: #f7f7f9; padding: 8px; border-left: 3px solid #2271b1; }
    .lines { margin-top: 14px; }
    .lines th, .lines td { padding: 6px 8px; border-bottom: 1px solid #ddd; }
    .lines th { background-color: #2271b1; color: #fff; text-align: left; font-weight: bold; font-size: 9pt; }
    .lines td.num, .lines th.num { text-align: right; }
    .lines td.center, .lines th.center { text-align: center; }
    .totals { width: 50%; margin-top: 10px; margin-left: 50%; }
    .totals td { padding: 4px 8px; }
    .totals .grand { background-color: #2271b1; color: #fff; font-weight: bold; }
    .footer { margin-top: 30px; font-size: 8pt; color: #666; border-top: 1px solid #ddd; padding-top: 8px; }
</style>

<!-- =============================================================
     Header: seller block (left) + invoice meta (right)
     ============================================================= -->
<table class="header-bar">
    <tr>
        <td class="seller">
            <h1><?php echo esc_html($seller['company_name'] ?: '—'); ?></h1>
            <?php if (!empty($seller['address'])) : ?>
                <?php echo nl2br(esc_html($seller['address'])); ?><br>
            <?php endif; ?>
            <?php echo esc_html($seller['postal_code']); ?> <?php echo esc_html($seller['city']); ?>
            <?php if (!empty($seller['country']) && $seller['country'] !== 'FR') : ?>
                — <?php echo esc_html($seller['country']); ?>
            <?php endif; ?>
            <br><br>
            <?php if (!empty($seller['siret'])) : ?>
                <span class="label">SIRET :</span> <?php echo esc_html($seller['siret']); ?><br>
            <?php endif; ?>
            <?php if (!empty($seller['vat'])) : ?>
                <span class="label">TVA intra :</span> <?php echo esc_html($seller['vat']); ?><br>
            <?php endif; ?>
            <?php if (!empty($seller['ape'])) : ?>
                <span class="label">APE :</span> <?php echo esc_html($seller['ape']); ?>
            <?php endif; ?>
        </td>
        <td class="invoice-meta">
            <span class="label"><?php esc_html_e('FACTURE', 'factur-x-for-woocommerce'); ?></span><br>
            <span class="invoice-number"><?php echo esc_html($invoice['number']); ?></span><br><br>
            <span class="label"><?php esc_html_e('Date d\'émission', 'factur-x-for-woocommerce'); ?></span><br>
            <?php echo esc_html($invoice['issue_date_display']); ?><br><br>
            <?php if (!empty($invoice['due_date_display'])) : ?>
                <span class="label"><?php esc_html_e('Échéance', 'factur-x-for-woocommerce'); ?></span><br>
                <?php echo esc_html($invoice['due_date_display']); ?>
            <?php endif; ?>
        </td>
    </tr>
</table>

<!-- =============================================================
     Buyer block + (optional) shipping reference
     ============================================================= -->
<table class="parties">
    <tr>
        <td>
            <h2><?php esc_html_e('Facturé à', 'factur-x-for-woocommerce'); ?></h2>
            <div class="party-box">
                <strong><?php echo esc_html($buyer['name'] ?: '—'); ?></strong><br>
                <?php foreach ($buyer['address_lines'] as $line) : ?>
                    <?php echo esc_html($line); ?><br>
                <?php endforeach; ?>
                <?php echo esc_html($buyer['postal_code']); ?> <?php echo esc_html($buyer['city']); ?>
                <?php if (!empty($buyer['country']) && $buyer['country'] !== 'FR') : ?>
                    — <?php echo esc_html($buyer['country']); ?>
                <?php endif; ?>
                <?php if ($buyer['is_b2b']) : ?>
                    <br><br>
                    <?php if (!empty($buyer['siret'])) : ?>
                        <span class="label">SIRET :</span> <?php echo esc_html($buyer['siret']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($buyer['vat'])) : ?>
                        <span class="label">TVA intra :</span> <?php echo esc_html($buyer['vat']); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </td>
        <td>
            <h2><?php esc_html_e('Mode de paiement', 'factur-x-for-woocommerce'); ?></h2>
            <div class="party-box">
                <?php echo esc_html($totals['payment_method_title']); ?>
            </div>
        </td>
    </tr>
</table>

<!-- =============================================================
     Line items
     ============================================================= -->
<table class="lines" cellspacing="0">
    <thead>
        <tr>
            <th><?php esc_html_e('Description', 'factur-x-for-woocommerce'); ?></th>
            <th class="num"><?php esc_html_e('Qté', 'factur-x-for-woocommerce'); ?></th>
            <th class="num"><?php esc_html_e('Prix unit. HT', 'factur-x-for-woocommerce'); ?></th>
            <th class="center"><?php esc_html_e('TVA', 'factur-x-for-woocommerce'); ?></th>
            <th class="num"><?php esc_html_e('Total HT', 'factur-x-for-woocommerce'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($lines as $line) : ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($line['name']); ?></strong>
                    <?php if (!empty($line['sku'])) : ?>
                        <br><span class="label">SKU : <?php echo esc_html($line['sku']); ?></span>
                    <?php endif; ?>
                </td>
                <td class="num"><?php echo esc_html((string) $line['quantity']); ?></td>
                <td class="num"><?php echo esc_html(number_format_i18n($line['unit_price'], 2)); ?> <?php echo esc_html($invoice['currency_symbol']); ?></td>
                <td class="center"><?php echo esc_html(number_format_i18n($line['vat_rate'], 2)); ?> %</td>
                <td class="num"><?php echo esc_html(number_format_i18n($line['line_total'], 2)); ?> <?php echo esc_html($invoice['currency_symbol']); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- =============================================================
     Totals (right-aligned via 50% left margin trick)
     ============================================================= -->
<table class="totals">
    <tr>
        <td><?php esc_html_e('Total HT', 'factur-x-for-woocommerce'); ?></td>
        <td class="num"><?php echo esc_html(number_format_i18n($totals['line_total'], 2)); ?> <?php echo esc_html($invoice['currency_symbol']); ?></td>
    </tr>
    <?php foreach ($tax_breakdown as $tax) : ?>
        <tr>
            <td><?php printf(esc_html__('TVA %s %%', 'factur-x-for-woocommerce'), esc_html(number_format_i18n($tax['rate'], 2))); ?></td>
            <td class="num"><?php echo esc_html(number_format_i18n($tax['tax'], 2)); ?> <?php echo esc_html($invoice['currency_symbol']); ?></td>
        </tr>
    <?php endforeach; ?>
    <tr class="grand">
        <td><strong><?php esc_html_e('Total TTC', 'factur-x-for-woocommerce'); ?></strong></td>
        <td class="num"><strong><?php echo esc_html(number_format_i18n($totals['grand_total'], 2)); ?> <?php echo esc_html($invoice['currency_symbol']); ?></strong></td>
    </tr>
    <tr>
        <td><?php esc_html_e('Montant dû', 'factur-x-for-woocommerce'); ?></td>
        <td class="num"><?php echo esc_html(number_format_i18n($totals['due_payable'], 2)); ?> <?php echo esc_html($invoice['currency_symbol']); ?></td>
    </tr>
</table>

<!-- =============================================================
     Footer: legal mentions from settings
     ============================================================= -->
<?php if (!empty($seller['legal_mentions'])) : ?>
    <div class="footer">
        <?php echo nl2br(esc_html($seller['legal_mentions'])); ?>
    </div>
<?php endif; ?>
