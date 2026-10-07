<?php
namespace MailerLiteForms\Views;

class CustomForm
{

    /**
     * Constructor
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public function __construct($form_id, $form_data)
    {

        $this->view($form_id, $form_data);
    }

    /**
     * Output view
     *
     * @access      private
     * @since       1.5.0
     */
    private function view($form_id, $form_data)
    {
        $unique_id = uniqid();

        ?>

            <div id="mailerlite-form_<?php echo $form_id; ?>" data-temp-id="<?php echo $unique_id; ?>">
                <div class="mailerlite-form">
                    <form action="" method="post" novalidate>
                        <?php if ( ! empty( $form_data['title'] )) { ?>
                            <div class="mailerlite-form-title"><h3><?php echo $form_data['title']; ?></h3></div>
                        <?php } ?>
                        <div class="mailerlite-form-description"><?php echo wp_kses_post( stripslashes( $form_data['description'] ) ); ?></div>
                        <div class="mailerlite-form-inputs">
                            <?php foreach ( $form_data['fields'] as $key => $field ): ?>
                                <?php

                                if (is_array($field)) {
                                    $title = $field['title'];
                                    $type  = strtolower($field['type']);
                                } else {
                                    $title = $field;
                                    if ($key == 'email') {
                                        $type = 'email';
                                    } else {
                                        $type = 'text';
                                    }
                                }

                                switch ($type) {
                                    case 'email' :
                                        $input_type = 'email';
                                        break;
                                    case 'number':
                                        $input_type = 'number';
                                        break;
                                    case 'date':
                                        $input_type = 'date';
                                        break;
                                    default:
                                        $input_type = 'text';
                                        break;
                                }
                                ?>
                                <div class="mailerlite-form-field">
                                    <label for="mailerlite-<?php echo $form_id; ?>-field-<?php echo esc_attr( $key ); ?>"><?php echo ($key == 'email') ? esc_html( $form_data['email_label'] ?? $title ) : esc_html( $title ); ?></label>
                                    <input id="mailerlite-<?php echo $form_id; ?>-field-<?php echo esc_attr( $key ); ?>"
                                           type="<?php echo $input_type; ?>" <?php if ($input_type === 'email') { ?>required="required" <?php } ?>
                                           name="form_fields[<?php echo esc_attr( $key ); ?>]"
                                           placeholder="<?php echo esc_attr( ($key == 'email') ? ($form_data['email_placeholder'] ?? $title) : $title ); ?>"/>
                                </div>
                            <?php endforeach; ?>
                            <div class="mailerlite-form-loader"><?php if ( ! empty( $form_data['please_wait'] ) ) {
                                    echo $form_data['please_wait'];
                                } else {
                                    _e( 'Please wait...', 'mailerlite' );
                                } ?></div>
                            <div class="mailerlite-subscribe-button-container">
                                <button class="mailerlite-subscribe-submit" type="submit">
                                    <?php echo $form_data['button']; ?>
                                </button>
                            </div>
                            <input type="hidden" name="form_id" value="<?php echo $form_id; ?>"/>
                            <input type="hidden" name="action" value="mailerlite_subscribe_form"/>
                            <input type="hidden" name="ml_nonce" value="<?php echo wp_create_nonce('mailerlite_form'); ?>"/>
                        </div>
                        <div class="mailerlite-form-response">
                            <?php if ( ! empty( $form_data['success_message'] ) ) { ?>
                                <h4><?php echo wp_kses_post( $form_data['success_message'] ); ?></h4>
                            <?php } else { ?>
                                <h4><?php _e( 'Thank you for signing up!', 'mailerlite' ); ?></h4>
                            <?php } ?>
                        </div>
                    </form>
                </div>
            </div>
        <script type="text/javascript" src='<?php echo MAILERLITE_PLUGIN_URL ?>/assets/js/localization/validation-messages.js'></script>
        <?php
        ob_start();
        ?>
        <script type="text/javascript">
                var selectedLanguage = "<?php echo $form_data['language'] ?? false; ?>";
                var validationMessages = messages["en"];
                if(selectedLanguage) {
                    validationMessages = messages[selectedLanguage];
                }

                window.addEventListener("load", function () {
                            var form_container = document.querySelector(`#mailerlite-form_<?php echo $form_id; ?>[data-temp-id="<?php echo $unique_id; ?>"] form`);
                            let submitButton = form_container.querySelector('.mailerlite-subscribe-submit');
                            submitButton.disabled = true;
                            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                method: 'POST',
                                headers:{
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: new URLSearchParams({
                                    "action" : "ml_create_nonce",
                                    "ml_nonce" : form_container.querySelector("input[name='ml_nonce']").value
                                })
                            })
                                .then((response) => response.json())
                                .then((json) => {
                                       if(json.success) {
                                           form_container.querySelector("input[name='ml_nonce']").value = json.data.ml_nonce;
                                           submitButton.disabled = false;
                                       }
                                })
                                .catch((error) => {
                                    console.error('Error:', error);
                                });
                        form_container.addEventListener('submit', (e) => {
                            e.preventDefault();
                            let data = new URLSearchParams(new FormData(form_container)).toString();
                            let validationError = false;
                            document.querySelectorAll('.mailerlite-form-error').forEach(el => el.remove());
                           Array.from(form_container.elements).forEach((input) => {
                               if(input.type !== 'hidden') {
                                   if(input.required) {
                                       if(input.value == '') {
                                           validationError = true;
                                           let error = document.createElement("span");
                                           error.className = 'mailerlite-form-error';
                                           error.textContent = validationMessages.required;
                                           input.after(error);
                                           return false;
                                       }
                                   }
                                   if((input.type == "email") && (!validateEmail(input.value))) {
                                       validationError = true;
                                       let error = document.createElement("span");
                                       error.className = 'mailerlite-form-error';
                                       error.textContent = validationMessages.email;
                                       input.after(error);
                                       return false;
                                   }
                               }
                           });
                           if(validationError) {
                               return false;
                           }

                            fade.out(form_container.querySelector('.mailerlite-subscribe-button-container'), () => {
                                fade.in(form_container.querySelector('.mailerlite-form-loader'));
                            });

                            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                method: 'POST',
                                headers:{
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: data
                            })
                                .then((response) => {
                                    fade.out(form_container.querySelector('.mailerlite-form-inputs'), () => {
                                        fade.in(form_container.querySelector('.mailerlite-form-response'));
                                    });
                                })
                                .catch((error) => {
                                    console.error('Error:', error);
                                });
                        });
                    }, false);

                var fade = {
                    out: function(el, fn = false) {
                        var fadeOutEffect = setInterval(function () {
                            if (!el.style.opacity) {
                                el.style.opacity = 1;
                            }
                            if (el.style.opacity > 0) {
                                el.style.opacity -= 0.1;
                            } else {
                                el.style.display = 'none';
                                clearInterval(fadeOutEffect);
                            }
                        }, 50);
                        if( typeof (fn) == 'function') {
                            fn();
                        }
                    },
                    in: function(el) {
                        var fadeInEffect = setInterval(function () {
                            if (!el.style.opacity) {
                                el.style.opacity = 0;
                            }
                            if (el.style.opacity < 1) {

                                el.style.opacity = Number(el.style.opacity) + 0.1;
                            } else {
                                el.style.display = 'block';
                                clearInterval(fadeInEffect);
                            }
                        }, 50);
                    }
                };

                function validateEmail(email){
                    if(email.match(
                        /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/
                    )) {
                        return true;
                    }
                    return false;
                }
            </script>
        <?php
            $content = ob_get_clean();
            echo preg_replace('/\s+/', ' ', $content);
    }
}
