<?php

namespace MailerLiteForms\Views;

use MailerLiteForms\Modules\Form;

class Preview
{

    /**
     * Constructor
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public function __construct()
    {

        $this->view();
    }

    /**
     * Output view
     *
     * @access      private
     * @since       1.5.0
     */
    private function view()
    {

        $form_id = 0;

        if ( isset($_GET['form_id']) ) {

            $form_id = absint($_GET['form_id']);
        }

        remove_action( 'wp_head', 'mailerlite_universal_woo_commerce' );
        ?>

        <html lang="en">
            <head>
                <?php wp_head(); ?>
            </head>
            <body style="overflow:hidden;">
                <div style='width: 400px;margin: auto;'>
                    <?php ( new Form() )->load_mailerlite_form( $form_id ); ?>
                </div>
                <style>
                    .ml_message_wrapper > * {
                        margin: 0 !important;
                        padding: 0 !important;
                    }
                </style>
            </body>
        </html>

        <?php
    }
}