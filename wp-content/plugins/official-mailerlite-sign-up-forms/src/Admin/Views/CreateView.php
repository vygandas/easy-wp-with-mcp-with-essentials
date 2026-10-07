<?php

namespace MailerLiteForms\Admin\Views;

use MailerLiteForms\Helper;
use MailerLiteForms\Models\MailerLiteWebForm;

class CreateView
{

    /**
     * Constructor
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public function __construct($webforms)
    {
        $this->view($webforms);
    }

    /**
     * Output view
     *
     * @access      private
     * @return      void
     * @since       1.5.0
     */
    private function view($webforms)
    {

        ?>

        <div class="wrap columns-2 dd-wrap">
            <h1><?php echo __( 'Create new signup form', 'mailerlite' ); ?></h1>
            <h2 class="title"><?php echo __( 'Form type', 'mailerlite' ); ?></h2>

            <div class="metabox-holder has-right-sidebar">
                <?php new SidebarView(); ?>
                <div id="post-body">
                    <div id="post-body-content">

                        <form action="<?php echo admin_url( 'admin.php?page=mailerlite_main&view=create&noheader=true' ); ?>"
                              method="post">
                            <?php Helper::nonce_field(); ?>
                            <div class="inside">

                                <div class="mailerlite-list">

                                    <div class="plugin-card">
                                        <div class="plugin-card-top">

                                            <label for="form_type_custom" class="selectit">
                                                <input id="form_type_custom" type="radio" name="form_type" value="1"
                                                       onclick="embedFormSelected(true)"
                                                       checked="checked">
                                                <?php echo __( 'Custom signup form', 'mailerlite' ); ?>
                                                <p>
                                                    <img class="mailerlite-icon"
                                                         src="<?php echo MAILERLITE_PLUGIN_URL ?>/assets/image/custom_form.png">
                                                </p>
                                                <p class="description">
                                                    <?php _e( 'Create a custom form with different fields and interest groups directly in WordPress.',
                                                        'mailerlite' ); ?>
                                                </p>
                                            </label>

                                        </div>
                                    </div>
                                    <div class="plugin-card">
                                        <div class="plugin-card-top">

                                            <?php
                                            $embed_button_webforms = [];

                                            /** @var MailerLiteWebForm[] $webforms */
                                            if ( count( $webforms ) ) {
                                                foreach ( $webforms as $webform ) {
                                                    if ( ! in_array( $webform->type, [ 'embed', 'embedded', 'button' ] ) ) {
                                                        continue;
                                                    }

                                                    $embed_button_webforms[] = $webform;
                                                }
                                            }
                                            ?>
                                            <label for="form_type_webform" class="selectit<?php echo count( $embed_button_webforms ) == 0 ? ' ml_unavailable' : '' ?>">
                                                <input id="form_type_webform" type="radio" name="form_type"
                                                       onclick="embedFormSelected()"
                                                       value="2">
                                                <?php echo __( 'Embedded forms created in MailerLite', 'mailerlite' ); ?>
                                                <p>
                                                    <img class="mailerlite-icon"
                                                         src="<?php echo MAILERLITE_PLUGIN_URL ?>/assets/image/mailerlite_form.png">
                                                </p>
                                                <p class="description">
                                                    <?php _e( 'Add signup forms from your MailerLite account.',
                                                        'mailerlite' ); ?>
                                                </p>
                                            </label>
                                            <input type="hidden" id="mailerliteEmbedFormCount" value="<?php echo (count( $embed_button_webforms ) > 0 ) ?? false; ?>"/>
                                        </div>
                                    </div>

                                    <div class="clear"></div>

                                </div>

                                <p id="expl" class="hidden info notice notice-<?php echo count( $embed_button_webforms ) == 0 ? 'warning' : 'info' ?>">
                                <?php
                                if (count($embed_button_webforms ) == 0) {
                                    echo __( 'Warning about forms', 'mailerlite' );
                                } else {
                                    echo __( 'Explanation about forms', 'mailerlite' );
                                }
                                ?>
                                </p>
                                <div class="submit">
                                    <input class="button-primary"
                                           id="createFormBtn"
                                           value="<?php echo __( 'Create form', 'mailerlite' ); ?>" name="create_signup_form"
                                           type="submit">
                                    <a class="button-secondary"
                                       href="<?php echo admin_url( 'admin.php?page=mailerlite_main' ); ?>"><?php echo __( 'Back',
                                            'mailerlite' ); ?></a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <script>
            function embedFormSelected(customForm = false)
            {
                document.getElementById('createFormBtn').removeAttribute('disabled');
                if (customForm) {
                    document.getElementById('expl').classList.add('hidden');
                    return;
                }
                const webForms = document.getElementById('mailerliteEmbedFormCount').value;
                document.getElementById('expl').classList.remove('hidden');
                console.log(webForms);
                if (!webForms) {
                    document.getElementById('createFormBtn').setAttribute('disabled', 'disabled');
                }
            }

        </script>

        <?php
    }
}
