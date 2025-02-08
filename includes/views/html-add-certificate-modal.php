<?php
/**
 * Add certificate modal template.
 *
 * @version 7.0.1
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<script type="text/html" id="tmpl-sst-modal-add-certificate">
    <div class="wc-backbone-modal">
        <div class="wc-backbone-modal-content sst-certificate-modal-content woocommerce">
            <section class="wc-backbone-modal-main" role="main">
                <header class="wc-backbone-modal-header">
                    <h1><?php esc_html_e( 'Add certificate', 'simple-sales-tax' ); ?></h1>
                    <button class="modal-close modal-close-link">
                        &times;
                        <span class="screen-reader-text">
                            <?php esc_html_e( 'Close modal panel', 'simple-sales-tax' ); ?>
                        </span>
                    </button>
                </header>
                <article>
                    <form action="" method="post">
                        <?php
                        wc_get_template(
                            'html-certificate-form.php',
                            array(),
                            'sst/',
                            SST()->path( 'includes/views/' )
                        );
                        ?>

                        <input type="hidden" name="CertificateID" value="{{{ data.CertificateID }}}">
                    </form>
                </article>
                <footer>
                    <div class="inner">
                        <button id="btn-ok" class="button alt">
                            <?php esc_html_e( 'Add certificate', 'simple-sales-tax' ); ?>
                        </button>
                    </div>
                </footer>
            </section>
        </div>
    </div>
    <div class="wc-backbone-modal-backdrop modal-close"></div>
</script>
