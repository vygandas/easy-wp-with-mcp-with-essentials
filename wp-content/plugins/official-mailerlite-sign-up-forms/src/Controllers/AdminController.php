<?php
namespace MailerLiteForms\Controllers;

use MailerLiteForms\Admin\Actions;
use MailerLiteForms\Admin\Hooks;
use MailerLiteForms\Admin\Status;
use MailerLiteForms\Admin\Views\CreateView;
use MailerLiteForms\Admin\Views\EditCustomView;
use MailerLiteForms\Admin\Views\EditEmbeddedView;
use MailerLiteForms\Admin\Views\GroupsView;
use MailerLiteForms\Admin\Views\MainView;
use MailerLiteForms\Admin\Views\SettingsView;
use MailerLiteForms\Admin\Views\StatusView;
use MailerLiteForms\Api\ApiType;
use MailerLiteForms\Api\PlatformAPI;
use MailerLiteForms\Helper;
use MailerLiteForms\Modules\Form;

class AdminController
{

    const FIRST_GROUP_LOAD = 100;

    /**
     * Constructor
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public function __construct()
    {

    }

    /**
     * Create, edit, list pages method
     */
    public static function forms() {

        $rolePermission = RolePermissionController::instance();

        if (!$rolePermission->isAllowed()) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'mailerlite' ) );
        }

        global $wpdb;

        $api_key = self::apiKey();
        $result  = '';

        // exit if API key is not set
        if ( $api_key == "" ) {

            exit;
        }

        // Create new signup form view
        if ( isset( $_GET['view'] ) && $_GET['view'] == 'create' ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to view this page.', 'mailerlite' ) );
            }
            if ( isset( $_POST['create_signup_form'] ) ) {

                Helper::verify_nonce();

                ( new Form() )->create_new_form( $_POST );

                wp_redirect(
                    'admin.php?page=mailerlite_main&view=edit&id='
                    . $wpdb->insert_id
                );
            } else {
                if ( isset( $_GET['noheader'] ) ) {

                    require_once( ABSPATH . 'wp-admin/admin-header.php' );
                }
            }

            $API = new PlatformAPI($api_key);

            $webforms = $API->getEmbeddedForms([
                'limit' => 1000,
                'type' => 'embedded'
            ]);

            if ( ! empty( $webforms->error ) && ! empty( $webforms->error->message ) ) {

                $msg = '<u>' . __( 'Error happened', 'mailerlite' ) . '</u>: ' . $webforms->error->message;
                add_action( 'admin_notices', function() use ($msg) {

                    $class   = 'notice notice-error';
                    printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $msg );
                });
            }


            new CreateView($webforms);

        } // Edit signup form view
        elseif ( isset( $_GET['view'] ) && isset( $_GET['id'] )
            && $_GET['view'] == 'edit'
            && absint( $_GET['id'] )
        ) {
            $_POST = array_map( 'stripslashes_deep', $_POST );

            $form_id = absint( $_GET['id'] );

            $query = $wpdb->prepare(
                "SELECT *
				FROM {$wpdb->base_prefix}mailerlite_forms
				WHERE id=%d",
                $form_id
            );
            $form = $wpdb->get_row($query);

            if ( isset( $form->data ) ) {
                $form->data = unserialize( $form->data );

                if ( $form->type == Form::TYPE_CUSTOM ) {
                    add_filter(
                        'wp_default_editor',
                        function() {
                            return 'tinymce';
                        }
                    );

                    $API = new PlatformAPI( AdminController::apiKey() );

                    $groups_from_ml = $API->getGroups([
                        'limit' => AdminController::FIRST_GROUP_LOAD
                    ]);

                    $form_lists = isset( $_POST['form_lists'] ) && is_array( $_POST['form_lists'] ) ? $_POST['form_lists'] : $form->data['lists'];

                    if (! isset($form->data['selected_groups'])) {
                        $groups_selected = array_filter($groups_from_ml, function($group) use ($form_lists) {
                            return in_array($group->id, $form_lists);
                        });

                        if (count($groups_selected) != count($form_lists)) {

                            $groups_from_ml_extended = $API->getGroups([
                                'limit' => AdminController::FIRST_GROUP_LOAD
                            ]);

                            $groups_selected = array_filter($groups_from_ml_extended, function($group) use ($form_lists) {
                                return in_array($group->id, $form_lists);
                            });
                        }
                    } else {
                        $groups_selected = $form->data['selected_groups'];

                        if (isset($_POST['selected_groups']) && !empty($_POST['selected_groups'])) {
                            $form_selected_groups =[];
                            $selected_groups = explode(';*',$_POST['selected_groups']);

                            foreach ($selected_groups as $group) {
                                $group = explode('::', $group);
                                $group_data = [];
                                $group_data['id'] = $group[0];
                                $group_data['name'] = $group[1];
                                $form_selected_groups[] = (object)$group_data;
                            }
                            $groups_selected = $form_selected_groups;
                        }
                    }
                    if (!isset($form->data['email_label'])) {
                        $form->data['email_label'] = 'Email';
                        $form->data['email_placeholder'] = 'Email';
                    }
                    $groups_not_selected = array_filter($groups_from_ml, function($group) use ($form_lists) {
                        return ! in_array($group->id, $form_lists);
                    });

                    $groups = array_merge($groups_selected, $groups_not_selected);

                    $can_load_more_groups = $API->checkMoreGroups(AdminController::FIRST_GROUP_LOAD);

                    $fields    = $API->getFields();

                    if ( isset( $_POST['save_custom_signup_form'] ) ) {
                        Helper::verify_nonce();

                        $form_name        = htmlspecialchars(Helper::issetWithDefault( 'form_name',
                            __( 'Subscribe for newsletter!', 'mailerlite' ) ));
                        $form_title       = htmlspecialchars(Helper::issetWithDefault( 'form_title',
                            __( 'Newsletter signup', 'mailerlite' ) ));
                        $form_description = Helper::issetWithDefault( 'form_description',
                            __( 'Just simple MailerLite form!', 'mailerlite' ), false );
                        $success_message  = Helper::issetWithDefault( 'success_message',
                            '<span style="color: rgb(51, 153, 102);">' . __( 'Thank you for sign up!',
                                'mailerlite' ) . '</span>', false );
                        $button_name      = htmlspecialchars(Helper::issetWithDefault( 'button_name', __( 'Subscribe', 'mailerlite' ) ));
                        $please_wait      = htmlspecialchars(Helper::issetWithDefault( 'please_wait' ));
                        $language         = htmlspecialchars(Helper::issetWithDefault( 'language' ));

                        $selected_fields = isset( $_POST['form_selected_field'] )
                        && is_array(
                            $_POST['form_selected_field']
                        ) ? $_POST['form_selected_field'] : [];
                        $field_titles    = isset( $_POST['form_field'] )
                        && is_array(
                            $_POST['form_field']
                        ) ? $_POST['form_field'] : [];

                        $email_label =  htmlspecialchars(Helper::issetWithDefault( 'email_label',
                            __( 'Email', 'mailerlite' ) ));

                        $email_placeholder =  htmlspecialchars(Helper::issetWithDefault( 'email_placeholder',
                            __( 'Email', 'mailerlite' ) ));

                        $prepared_fields = [];

                        foreach ( $selected_fields as $field ) {
                            $field = sanitize_key( $field );
                            if ( isset( $field_titles[ $field ] ) ) {
                                $prepared_fields[ $field ] = [];
                                $prepared_fields[ $field ]['title'] = htmlspecialchars($field_titles[ $field ]);
                                $prepared_fields[ $field ]['type']  = isset($_POST['field_type_' . $field]) ? $_POST['field_type_' . $field] : 'text';
                            }
                        }

                        if ( ! isset( $field_titles['email'] ) || $field_titles['email'] == '' ) {
                            $field_titles['email'] = __( 'Email', 'mailerlite' );

                            // Force to use email
                            $prepared_fields['email'] = [];
                            $prepared_fields['email']['title'] = $field_titles['email'];
                            $prepared_fields['email']['type']  = 'email';
                        }

                        $form_data = [
                            'title'           => wp_kses_post( $rolePermission->canEdit('form_title') ? $form_title : $form->data['form_title'] ),
                            'description'     => wp_kses_post( $rolePermission->canEdit('form_description') ? wpautop( $form_description, true ) : $form->data['description'] ),
                            'success_message' => wp_kses_post( $rolePermission->canEdit('success_message') ? wpautop( $success_message, true ) : $form->data['success_message'] ),
                            'button'          => wp_kses_post( $rolePermission->canEdit('button_name') ? $button_name : $form->data['button'] ),
                            'please_wait'     => wp_kses_post( $rolePermission->canEdit('please_wait') ? $please_wait : $form->data['please_wait'] ),
                            'language'        => wp_kses_post( $rolePermission->canEdit('language') ? $language : $form->data['language'] ),
                            'lists'           => $rolePermission->canEdit('groups') ? $form_lists : $form->data['lists'],
                            'fields'          => $rolePermission->canEdit('form_fields') ? $prepared_fields : $form->data['fields'],
                            'selected_groups' => $rolePermission->canEdit('groups') && isset($form_selected_groups) ? $form_selected_groups : $form->data['selected_groups'],
                            'email_label' => wp_kses_post( $email_label ),
                            'email_placeholder' => wp_kses_post( $email_placeholder ),
                        ];

                        $form_name = $rolePermission->canEdit('form_name') ? $form_name : $form->name;

                        $wpdb->update(
                            $wpdb->base_prefix . 'mailerlite_forms',
                            [
                                'name' => $form_name,
                                'data' => serialize( $form_data ),
                            ],
                            [ 'id' => $form_id ],
                            [],
                            [ '%d' ]
                        );

                        $form->data = $form_data;
                        $form->name = $form_name;

                        $result = 'success';
                    }

                    new EditCustomView($result, $form, $fields, $groups, $can_load_more_groups);

                } elseif ( $form->type == Form::TYPE_EMBEDDED ) {

                    $API = new PlatformAPI( $api_key );

                    $webforms = $API->getEmbeddedForms([
                        'limit' => 1000,
                        'type' => 'embedded'
                    ]);

                    if ( ! empty( $webforms->error ) && ! empty( $webforms->error->message ) ) {

                        $msg = '<u>' . __( 'Error happened', 'mailerlite' ) . '</u>: ' . $webforms->error->message;
                        add_action( 'admin_notices', function() use ($msg) {

                            $class   = 'notice notice-error';
                            printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $msg );
                        });
                    }

                    $parsed_webforms = [];

                    foreach ( $webforms as $webform ) {
                        $parsed_webforms[ $webform->id ] = $webform->code;
                    }

                    if ( isset( $_POST['save_embedded_signup_form'] ) ) {
                        Helper::verify_nonce();
                        $form_name = Helper::issetWithDefault( 'form_name', __( 'Embedded webform', 'mailerlite' ) );

                        $form_webform_id = isset( $_POST['form_webform_id'] )
                        && isset( $parsed_webforms[ $_POST['form_webform_id'] ] )
                            ? absint( $_POST['form_webform_id'] ) : 0;

                        $form_data = [
                            'id'   => $form_webform_id,
                            'code' => $parsed_webforms[ $form_webform_id ],
                        ];

                        $wpdb->update(
                            $wpdb->base_prefix . 'mailerlite_forms',
                            [
                                'name' => $form_name,
                                'data' => serialize( $form_data ),
                            ],
                            [ 'id' => $form_id ],
                            [],
                            [ '%d' ]
                        );

                        $form->data = $form_data;
                        $form->name = $form_name;

                        $result = 'success';
                    }

                    new EditEmbeddedView($result, $form, $webforms, $API->getApiType());
                }
            } else {
                $query = "
					SELECT * FROM
					{$wpdb->base_prefix}mailerlite_forms
					ORDER BY time DESC
				";
                $forms_data = $wpdb->get_results($query);

                new MainView( $forms_data );
            }
        } // Delete signup form view
        elseif ( isset( $_GET['view'] ) && isset( $_GET['id'] )
            && $_GET['view'] == 'delete'
            && absint( $_GET['id'] )
            && current_user_can( 'manage_options' )) {
            Helper::verify_nonce();
            $wpdb->delete(
                $wpdb->base_prefix . 'mailerlite_forms', [ 'id' => absint( $_GET['id'] ) ]
            );
            wp_redirect( 'admin.php?page=mailerlite_main' );
        } // Signup forms list
        else {
            $query = "
				SELECT * FROM
				{$wpdb->base_prefix}mailerlite_forms
				ORDER BY time DESC
			";
            $forms_data = $wpdb->get_results($query);

            new MainView( $forms_data );
        }
    }

    /**
     * Show Settings view
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public static function settings()
    {

        $api_key = self::apiKey();

        $ML_Settings_Double_OptIn   = new PlatformAPI( $api_key );

        if( $ML_Settings_Double_OptIn->getApiType() !== ApiType::INVALID ) {

            $double_optin_enabled = $ML_Settings_Double_OptIn->getDoubleOptin();
            $double_optin_enabled_local = ! get_option('mailerlite_double_optin_disabled');

            // Make sure they option is up-to-date
            if ($double_optin_enabled != $double_optin_enabled_local) {

                update_option('mailerlite_double_optin_disabled', ! $double_optin_enabled);
            }
        }

        new SettingsView( $api_key );
    }

    /**
     * Show Status page
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public static function status()
    {

        $information = ( new Status() )->getInformation();

        new StatusView($information);
    }

    /**
     * Register Actions and Hooks
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public static function init()
    {

        if ( is_admin() ) {

            new Actions();

            new Hooks();
        }
    }

    /**
     * Show more groups
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public static function getMoreGroups()
    {

        global $wpdb;

        check_admin_referer( 'mailerlite_load_more_groups', 'ml_nonce' );

        $form_id = absint( isset( $_POST['form_id'] ) ? $_POST['form_id'] : 0 );
        $offset  = absint( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 );

        $form = null;
        $lists = [];

        if ($form_id > 0) {
            $query = $wpdb->prepare(
                "SELECT *
        FROM {$wpdb->base_prefix}mailerlite_forms
        WHERE id=%d",
                $form_id
            );

            $form = $wpdb->get_row($query);


            if ($form) {
                $form->data = unserialize($form->data);

                $lists = $form->data['lists'];
            }
        }

        $ML_Groups = new PlatformAPI(self::apiKey());

        $groups_from_ml_extended = $ML_Groups->getMoreGroups(self::FIRST_GROUP_LOAD, $offset);

        $groups = array_filter($groups_from_ml_extended, function($group) use ($lists) {
            return ! in_array($group->id, $lists);
        });

        $can_load_more_groups = $ML_Groups->checkMoreGroups( self::FIRST_GROUP_LOAD, $offset + 1);

        new GroupsView( $groups, $form, $can_load_more_groups);

        exit;
    }

    /**
     * Get API key
     *
     * @access      public
     * @return      string
     * @since       1.5.0
     */
    public static function apiKey()
    {

        return get_option( 'mailerlite_api_key' );
    }
}
