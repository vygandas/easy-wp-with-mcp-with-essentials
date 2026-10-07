<?php

namespace MailerLiteForms\Admin\Views;

use MailerLiteForms\Controllers\RolePermissionController;
use MailerLiteForms\Helper;
use MailerLiteForms\Models\MailerLiteField;
use MailerLiteForms\Models\MailerLiteGroup;

class EditCustomView
{

    /**
     * Constructor
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public function __construct($result, $form, $fields, $groups, $can_load_more_groups)
    {
        if (!RolePermissionController::instance()->isAllowed()) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'mailerlite' ) );
        }
        $this->view($result, $form, $fields, $groups, $can_load_more_groups);
    }

    /**
     * Output view
     *
     * @access      private
     * @return      void
     * @since       1.5.0
     */
    private function view($result, $form, $fields, $groups, $can_load_more_groups)
    {
        $form_id = 0;
        if ( isset($_GET['id']) )
            $form_id = absint( $_GET['id'] );
        $rolePermission = RolePermissionController::instance();
        ?>

        <div class="wrap columns-2 dd-wrap">
            <h1><?php _e( 'Edit custom signup form', 'mailerlite' ); ?></h1>
            <?php if ( isset( $result ) && $result == 'success' ): ?>
                <div id="message" class="updated below-h2"><p><?php _e( 'Form saved.', 'mailerlite' ); ?> <a
                            href="<?php echo admin_url( 'admin.php?page=mailerlite_main' ); ?>"><?php _e( 'Back to forms list',
                                'mailerlite' ); ?></a>
                    </p></div>
            <?php endif; ?>
            <div class="metabox-holder has-right-sidebar">
                <?php new SidebarView(); ?>
                <div id="post-body">
                    <div id="post-body-content">
                        <form id="edit_custom"
                              action="<?php echo admin_url( 'admin.php?page=mailerlite_main&view=edit&id=' . $form_id); ?>"
                              method="post">
                            <?php Helper::nonce_field(); ?>

                            <input type="text" name="form_name" class="form-large" size="30" maxlength="255"
                                   value="<?php echo $form->name; ?>" id="form_name"
                                   placeholder="<?php _e( 'Form name', 'mailerlite' ); ?>"
                            <?php echo !$rolePermission->canEdit('form_name') ? 'disabled' : ''; ?>
                            >
                            <div>
                                <?php echo __( 'Use the shortcode', 'mailerlite' ); ?>
                                <input type="text" onfocus="this.select();" readonly="readonly"
                                       value="[mailerlite_form form_id=<?php echo $form_id; ?>]"
                                       size="26">
                                <?php echo __( 'to display this form inside a post, page or text widget.',
                                    'mailerlite' ); ?>
                            </div>

                            <br>

                            <div class="option-page-wrap">

                                <h2 class="nav-tab-wrapper" id="wpt-tabs">
                                    <a class="nav-tab nav-tab-active" id="ml-details-tab"
                                       href="#top#ml-details"><?php _e( 'Form details', 'mailerlite' ); ?></a>
                                    <?php if($rolePermission->canEdit('groups') || $rolePermission->canEdit('form_fields')): ?>
                                    <a class="nav-tab" id="ml-fields-tab"
                                       href="#top#ml-fields"><?php _e( 'Form fields and lists', 'mailerlite' ); ?></a>
                                    <?php endif; ?>
                                </h2>

                                <div class="tab-content-wrapper">
                                    <section id="ml-details" class="tab-content active">

                                        <table class="form-table">
                                            <tbody>
                                            <tr>
                                                <th>
                                                    <label for="form_title"><?php _e( 'Form title',
                                                            'mailerlite' ); ?></label>
                                                </th>
                                                <td>
                                                    <input type="text" class="regular-text" name="form_title" size="30"
                                                           maxlength="255" value="<?php echo $form->data['title']; ?>"
                                                           id="form_title"
                                                    <?php echo !$rolePermission->canEdit('form_title') ? 'disabled' : ''; ?>
                                                    >
                                                </td>
                                            </tr>
                                            <tr>
                                                <th><label for="form_description"><?php _e( 'Form description',
                                                            'mailerlite' ); ?></label></th>
                                                <td>
                                                    <?php
                                                    $settings = [
                                                        'media_buttons' => false,
                                                        'textarea_rows' => 4,
                                                        'tinymce'       => [
                                                            'toolbar1' =>
                                                                'bold,italic,underline,bullist,numlist,link,unlink,forecolor,alignleft,aligncenter,alignright,undo,redo',
                                                            'toolbar2' => ''
                                                        ],
                                                    ];
                                                    if(!$rolePermission->canEdit('form_description')) {
                                                        $settings['tinymce']['readonly'] = 1;
                                                    }
                                                    wp_editor( stripslashes( $form->data['description'] ),
                                                        'form_description', $settings );
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th><label for="success_message"><?php _e( 'Success message',
                                                            'mailerlite' ); ?></label></th>
                                                <td>
                                                    <?php
                                                    $settings = [
                                                        'media_buttons' => false,
                                                        'textarea_rows' => 4,
                                                        'tinymce'       => [
                                                            'toolbar1' =>
                                                                'bold,italic,underline,bullist,numlist,link,unlink,forecolor,alignleft,aligncenter,alignright,undo,redo',
                                                            'toolbar2' => ''
                                                        ],
                                                    ];
                                                    if(!$rolePermission->canEdit('success_message')) {
                                                        $settings['tinymce']['readonly'] = 1;
                                                    }
                                                    wp_editor( stripslashes( $form->data['success_message'] ),
                                                        'success_message', $settings );
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>
                                                    <label for="email_label"><?php _e( 'Email field label',
                                                            'mailerlite' ); ?></label>
                                                </th>
                                                <td>
                                                    <input type="text" class="regular-text" name="email_label" size="30"
                                                           maxlength="255"
                                                           value="<?php echo $form->data['email_label']; ?>" id="email_label"
                                                        <?php echo !$rolePermission->canEdit('email_label') ? 'disabled' : ''; ?>
                                                    >
                                                </td>
                                            </tr>

                                            <tr>
                                                <th>
                                                    <label for="email_placeholder"><?php _e( 'Email field placeholder',
                                                            'mailerlite' ); ?></label>
                                                </th>
                                                <td>
                                                    <input type="text" class="regular-text" name="email_placeholder" size="30"
                                                           maxlength="255"
                                                           value="<?php echo $form->data['email_placeholder']; ?>" id="email_placeholder"
                                                        <?php echo !$rolePermission->canEdit('email_placeholder') ? 'disabled' : ''; ?>
                                                    >
                                                </td>
                                            </tr>

                                            <tr>
                                                <th><label for="button_name"><?php _e( 'Button title',
                                                            'mailerlite' ); ?></label>
                                                </th>
                                                <td><input type="text" class="regular-text" name="button_name" size="30"
                                                           maxlength="255" value="<?php echo $form->data['button']; ?>"
                                                           id="button_name"
                                                        <?php echo !$rolePermission->canEdit('button_name') ? 'disabled' : ''; ?>
                                                    >
                                                </td>
                                            </tr>

                                            <tr>
                                                <th><label for="button_name"><?php _e( 'Please wait message',
                                                            'mailerlite' ); ?></label>
                                                </th>
                                                <td><input type="text" class="regular-text" name="please_wait" size="30"
                                                           maxlength="255"
                                                           value="<?php if ( isset( $form->data['please_wait'] ) ) {
                                                               echo $form->data['please_wait'];
                                                           } ?>" id="please_wait_name"
                                                        <?php echo !$rolePermission->canEdit('please_wait') ? 'disabled' : ''; ?>
                                                    >
                                                </td>
                                            </tr>

                                            <tr>
                                                <th>
                                                    <label for="language"><?php _e( 'Validation messages',
                                                            'mailerlite' ); ?></label>
                                                </th>
                                                <td>
                                                    <select id="language" name="language" <?php echo !$rolePermission->canEdit('language') ? 'disabled' : ''; ?>>
                                                        <?php foreach ( Helper::$languages as $langKey => $langName ): ?>
                                                            <option data-code="<?php echo $langKey; ?>"
                                                                    value="<?php echo $langKey; ?>"
                                                                <?php echo $langKey == ( isset( $form->data['language'] ) ? $form->data['language'] : '' ) ?
                                                                    ' selected="selected"' : ''; ?>><?php echo $langName; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>

                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>

                                    </section>
                                    <?php if($rolePermission->canEdit('groups') || $rolePermission->canEdit('form_fields')): ?>
                                    <section id="ml-fields" class="tab-content">

                                        <table class="form-table">
                                            <tr>
                                                <?php if($rolePermission->canEdit('form_fields')): ?>
                                                <td style="vertical-align: top;width: 350px;">
                                                    <h2><?php _e( 'Fields', 'mailerlite' ); ?></h2>
                                                    <p class="description"><?php _e( 'Select the fields that will be displayed in the form and set their order.',
                                                            'mailerlite' ); ?></p>
                                                    <table class="form-table" id="ml-fields-table">
                                                        <tbody>

                                                        <?php
                                                        foreach ( $form->data['fields'] as $key => $field ):
                                                            if (is_array($field)) {
                                                                $title = $field['title'];
                                                            } else {
                                                                $title = $field;
                                                            }
                                                        ?>
                                                            <tr draggable="true">
                                                                <th style="width:1%;">
                                                                    <input type="checkbox"
                                                                         class="input_control <?php
                                                                         echo $key == 'email' ? 'ml-read-only' : ''; ?>"
                                                                         name="form_selected_field[]"
                                                                         value="<?php echo $key; ?>"
                                                                         checked="checked">
                                                                    <span class="ml-grip"></span>
                                                                </th>
                                                                <td>
                                                                    <input type="text" id="field_<?php echo $key; ?>"
                                                                       name="form_field[<?php echo $key; ?>]"
                                                                       size="30" maxlength="255"
                                                                       value="<?php echo $title; ?>" class="<?php
                                                                    echo $key == 'email' ? 'ml-read-only' : ''; ?>">
                                                                    <input type="hidden" id="field_type_<?php echo $key; ?>"
                                                                           name="field_type_<?php echo $key; ?>"
                                                                           value="<?php echo $field['type'] ?? $key; ?>">
                                                                </td>
                                                            </tr>
                                                        <?php
                                                        endforeach;

                                                        /** @var MailerLiteField $field */
                                                        foreach ( $fields as $field ):

                                                            if (array_key_exists( $field->key,
                                                                $form->data['fields'] ))
                                                                continue;
                                                        ?>
                                                            <tr draggable="true">
                                                                <th style="width:1%;">
                                                                    <input type="checkbox"
                                                                         class="input_control"
                                                                         name="form_selected_field[]"
                                                                         value="<?php echo $field->key; ?>">
                                                                    <span class="ml-grip"></span>
                                                                </th>
                                                                <td><input type="text" id="field_<?php echo $field->key; ?>"
                                                                           name="form_field[<?php echo $field->key; ?>]"
                                                                           size="30" maxlength="255"
                                                                           value="<?php echo $field->title; ?>">
                                                                    <input type="hidden" id="field_type_<?php echo $field->key; ?>"
                                                                           name="field_type_<?php echo $field->key; ?>"
                                                                           value="<?php echo $field->type; ?>">
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </td>
                                                <?php endif; ?>
                                                <?php if($rolePermission->canEdit('groups')): ?>
                                                <td style="vertical-align: top;">
                                                    <h2><?php _e( 'Lists', 'mailerlite' ); ?></h2>
                                                    <p class="description"><?php _e( 'Select the list(s) to which people who submit this form should be subscribed.',
                                                            'mailerlite' ); ?></p>
                                                    <table class="form-table">
                                                        <tbody>
                                                        <?php
                                                        /** @var MailerLiteGroup $group */
                                                        foreach ( $groups as $group ) { ?>
                                                            <tr>
                                                                <th style="width:1%;"><input
                                                                        id="list_<?php echo $group->id; ?>"
                                                                        type="checkbox"
                                                                        class="input_control"
                                                                        name="form_lists[]"
                                                                        value="<?php echo $group->id; ?>"<?php echo in_array( $group->
                                                                    id,
                                                                        $form->data['lists'] ) ? ' checked="checked"' : ''; ?>

                                                                    >
                                                                </th>
                                                                <td>
                                                                    <label for="list_<?php echo $group->id; ?>"><?php echo $group->name; ?></label>
                                                                </td>
                                                            </tr>
                                                        <?php } ?>
                                                        <?php if ( $can_load_more_groups ) {
                                                            ?>
                                                            <tr>
                                                                <td colspan="2">
                                                                    <button id="loadMoreGroups" class="button-primary load-more-groups" type="button">
                                                                        Load more
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                            <?php
                                                        }
                                                        ?>
                                                        </tbody>
                                                    </table>
                                                    <table id="more-groups" class="form-table" data-offset="2" style="display: none;">

                                                    </table>

                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                        </table>

                                    </section>
                                    <?php endif; ?>
                                </div>

                            </div>

                            <div id="no-selections-error" class="notice-error" style="display: none;">
                                <p>
                                    <?php _e( 'Please select at least one Interest Group (tag) from the list.',
                                        'mailerlite' ); ?>
                                </p>
                            </div>
                            <input type="hidden" id="selected_groups" name="selected_groups" />
                            <div class="submit">
                                <input class="button-primary"
                                       value="<?php _e( 'Save form', 'mailerlite' ); ?>"
                                       name="save_custom_signup_form" type="submit">
                                <a class="button-secondary"
                                   href="<?php echo admin_url( 'admin.php?page=mailerlite_main' ); ?>"><?php echo __( 'Back',
                                        'mailerlite' ); ?></a>
                            </div>

                        </form>
                        <input type="hidden" id="groupSavePerm" value="<?php echo json_encode($rolePermission->canEdit('groups')); ?>"
                    </div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            document.addEventListener("DOMContentLoaded", function() {

                document.getElementsByClassName('wp-editor-tabs')[0].remove();

                let checkbox_class = document.getElementsByClassName('input_control');

                for(let i = 0; i <= checkbox_class.length; i++) {
                    if(checkbox_class[i]) {
                        checkbox_class[i].onclick = function() {
                            let input = document.querySelector('input#field_' + checkbox_class[i]?.value);
                            if(input) {
                                if (checkbox_class[i].checked === false) {
                                    input.disabled = true;
                                } else {
                                    input.disabled = false;
                                }
                            }
                        };
                    }
                }

                let tabs = document.querySelector('h2#wpt-tabs');
                let sections = document.querySelectorAll('section.tab-content');
                let tabsAnchor = tabs.querySelectorAll('a.nav-tab');

                for(let i = 0; i < tabsAnchor.length; i++) {
                        tabsAnchor[i].onclick = function(e) {
                            e.stopPropagation();
                            tabs.querySelectorAll('a.nav-tab').forEach(function (tab) {
                                tab.classList.remove('nav-tab-active');
                            });
                            sections.forEach(function (section) {
                                section.classList.remove('active');
                            });
                            let sectionId = tabsAnchor[i].id.replace('-tab', '');
                            document.getElementById(sectionId).classList.add('active');
                            tabsAnchor[i].classList.add('nav-tab-active');
                    }
                }

                document.getElementById('edit_custom').onsubmit = function (e) {
                    let checkedLists = document.querySelectorAll("[name='form_lists[]']:checked");
                    let selected_groups = '';
                    let cannotEditGroup = document.getElementById('groupSavePerm').value;
                    if ((cannotEditGroup == 'true') && (checkedLists.length === 0)) {
                        let errorElement = document.getElementById("no-selections-error");
                        errorElement.classList.add('notice');
                        errorElement.style.display = 'block';
                        e.preventDefault();
                        return false;
                    }
                    if(cannotEditGroup == 'true') {
                        checkedLists.forEach(function (list) {
                            let group = list.value +'::'+document.querySelector("label[for='list_"+ list.value + "']").textContent;
                            if (selected_groups !== '') {
                                selected_groups = selected_groups+';*'+group;
                            } else {
                                selected_groups = group;
                            }
                        });
                        document.querySelector("[name=selected_groups]").value = selected_groups;
                    }
                };
                if (document.getElementById('loadMoreGroups')) {
                    document.getElementById('loadMoreGroups').onclick = loadMoreGroups;
                }


                function loadMoreGroups() {
                    let button = document.getElementById('loadMoreGroups');
                    let groupsElement = document.getElementById('more-groups');
                    button.disabled = true;
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers:{
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Accept': 'application/html'
                        },
                        body: new URLSearchParams({
                            action: 'mailerlite_get_more_groups', offset: groupsElement.dataset.offset,
                            form_id: <?php echo $form_id; ?>,
                            ml_nonce: '<?php echo wp_create_nonce( 'mailerlite_load_more_groups' );?>'
                        })
                    })
                        .then((response) => response.text())
                        .then((html) => {
                            groupsElement.dataset.offset = parseInt(Number(groupsElement.dataset.offset) + 1);
                            document.getElementById('loadMoreGroups').parentElement.parentElement.remove();
                            groupsElement.style.display = 'block';
                            groupsElement.innerHTML += html;
                            if(groupsElement.getElementsByClassName('load-more-groups').length) {
                                groupsElement.getElementsByClassName('load-more-groups')[0].id = 'loadMoreGroups';
                                document.getElementById('loadMoreGroups').onclick = loadMoreGroups;
                            }
                        })
                        .catch((error) => {
                            console.error('Error:', error);
                        });
                }
            });

            const rows = document.querySelectorAll('#ml-fields-table tr');

            rows.forEach(function(el, index) {
                el.addEventListener('dragstart', dragField);
                el.addEventListener('dragover', moveField);
            });

            let row;

            function dragField(event) {
                row = event.target;
            }

            function moveField(event) {
                event.preventDefault();

                if (event.target.parentNode.tagName !== 'TR')
                    return;

                let children = Array.from(event.target.parentNode.parentNode.children);

                if(children.indexOf(event.target.parentNode) > children.indexOf(row)) {
                    event.target.parentNode.after(row);
                } else {
                    event.target.parentNode.before(row);
                }
            }
        </script>

        <?php
    }
}
