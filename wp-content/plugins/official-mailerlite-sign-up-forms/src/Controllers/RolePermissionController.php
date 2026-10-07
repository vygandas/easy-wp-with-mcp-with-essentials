<?php
namespace MailerLiteForms\Controllers;

class RolePermissionController
{
    public $roles;

    public $rolesWithNames;

    public $settings;

    public $allowedRoles;

    public $allowedPermissions;

    const PERMISSIONS = [
        'delete_forms',
        'edit_forms',
    ];

    public $wpRoles = [];

    const FORM_FIELDS = [
        'form_name' => 'Form Name',
        'form_title' => 'Form Title',
        'form_description' => 'Form Description',
        'success_message' => 'Success Message',
        'button_name' => 'Button Name',
        'please_wait' => 'Please Wait Text',
        'language' => 'Language',
        'form_fields' => 'Form Fields',
        'groups' => 'Groups',
        'email_label' => 'Email Field Label',
        'email_placeholder' => 'Email Field Placeholder',
    ];

    private $loggedInUser;

    public $isEnabled;

    public function __construct()
    {
        global $wp_roles;
        $roles = isset($wp_roles) ? $wp_roles->get_names() : [];
        foreach($roles as $key => $value) {
            if (!in_array($key, ['administrator', 'customer', 'subscriber'])) {
                $this->wpRoles[] = $key;
            }
            if(!in_array($key, $this->wpRoles)) {
                unset($roles[$key]);
            }
        }
        $this->roles = array_keys($roles);
        $this->rolesWithNames = $roles;
        $this->settings = get_option('mailerlite_forms_user_role_settings', []);
        $this->loggedInUser = $this->getCurrentUserWithRoles();

        if(empty($this->settings)) {
            update_option('mailerlite_forms_user_role_settings', [
                'enabled' => false,
                'allowed_roles' => ['editor'],
                'allowed_permissions' => []
            ]);
            $this->settings = get_option('mailerlite_forms_user_role_settings');
        }
        $this->isEnabled = $this->settings['enabled'];
        $this->allowedRoles = $this->settings['allowed_roles'];
        $this->allowedPermissions = $this->settings['allowed_permissions'];
    }

    public function canEdit(string $field):bool
    {
        if($this->isAdmin()) {
            return true;
        }
        return $this->isAllowed() && in_array($field, $this->allowedPermissions);
    }

    public function canDelete():bool
    {
        if($this->isAdmin()) {
            return true;
        }
        return $this->isAllowed() && in_array('delete_forms', $this->allowedPermissions);
    }

    public static function instance()
    {
        return new self;
    }

    private function getCurrentUserWithRoles()
    {
        $user = wp_get_current_user();
        if (empty($user->roles) && $user->ID > 0) {
            $user_data = get_userdata($user->ID);
            if ($user_data && !empty($user_data->roles)) {
                $user->roles = $user_data->roles;
            }
        }
        return $user;
    }

    public function isAdmin()
    {
        return in_array('administrator', $this->loggedInUser->roles);
    }

    public function isAllowed()
    {
        if($this->isAdmin()) {
            return true;
        }
        if(!$this->isEnabled) {
            return false;
        }
        $allowed = false;
        foreach($this->loggedInUser->roles as $role)
        {
            if (in_array($role, $this->allowedRoles))
            {
                $allowed = true;
                break;
            }
        }
        return $allowed;
    }

    public function toggleRolesAndPermissions()
    {
        $this->checkNonce();
        $this->settings['enabled'] = !$this->isEnabled;
        update_option('mailerlite_forms_user_role_settings', $this->settings);
    }

    public function editAllowedRolesAndPermissions($roles = [], $permissions = [])
    {
        $this->checkNonce();
        $roles[] = 'editor';
        $this->settings['allowed_roles'] = $roles;
        $this->settings['allowed_permissions'] = $permissions;
        update_option('mailerlite_forms_user_role_settings', $this->settings);
    }

    private function checkNonce()
    {
        if (!$this->isAdmin()) {
            wp_send_json_error( 'Invalid permission.' );
            wp_die();
        }
        if ( ! wp_verify_nonce( $_POST['ml_settings_non_admin_custom_form_nonce'], 'ml_form_doi_nonce' ) ) {
            wp_send_json_error( 'Invalid security token sent.' );
            wp_die();
        }
    }

}