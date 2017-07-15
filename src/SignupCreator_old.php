<?php

namespace QD\Signups;

require_once __dir__ . '/QD.Post.php';
require_once __dir__ . '/QD.SecureUploadService.php';
require_once __DIR__ . '/QD.Utils.php';

/**
  * Create a REST API endpoint that creates a specific post type with defined custom fields
  *
  * Actions:
  * qd_forms_post_creation_failed: $slug
  * qd_forms_file_uploads_failed: $slug
  * qd_forms_submission_success: $slug, $postId
  * qd_forms_submission_failed: $slug
  *
  * Filters:
  * qd_forms_submission_custom_fields: $value, $slug, $request
  * qd_forms_submission_title: $value, $slug, $customFields, $request
  * qd_forms_submission_post_type: $value, $slug, $customFields, $request
  * qd_forms_submission_status: $value, $slug, $customFields, $request
  * qd_forms_submission_content: $value, $slug, $customFields, $request
  * qd_forms_field_setup: $value, $slug, $field, $fieldName
  * qd_forms_upload_field_setup: $value, $slug, $field
  *
  */
class SignupCreator
{
    public $name;
    public $slug;
    private $fields;
    private $postTypeSettings = [];
    private $fileUploadSettings = [];
    private $fileValidationDefaults = [];

    private $schemaDefaults = [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ];

    private $postTypeDefaults = [
        'public' => false,
        'show_ui' => true,
        'hierarchical' => false,
        'menu_icon' => 'dashicons-feedback',
        'supports' => [ 'title', 'page-attributes', 'revisions' ],
    ];

    public function __construct($name, $slug)
    {
        $this->name = $name;
        $this->slug = $slug;

        // Set default post type label
        $this->postTypeDefaults['label'] = $this->name;

        // Set file validation defaults
        $this->fileValidationDefaults = [
            new \Upload\Validation\Extension([
                'doc',
                'docx',
                'pdf',
                'rtf',
                'txt',
                'jpg',
                'jpeg',
                'png',
            ]),
            new \Upload\Validation\Size('5M'),
        ];
    }

    public function init()
    {
        add_action('init', array($this, 'registerPostType'), 0, 0);
        add_action('acf/init', array($this, 'registerACFFields'), 0, 0);
        add_action('rest_api_init', array($this, 'registerRESTRoute'), 0, 0);

        // Ensure that associated files are deleted when a signup is deleted
        foreach ($this->fileUploadSettings as $settings) {
            \QD\SecureUploadService::deleteFilesOnPostDelete($this->slug, $settings['name']);
        }
    }

    /**
     * Set the schema for bothe API endpoint and ACF custom post type
     * This is basically a JSON Schema as defined in the WP REST API,
     * only in one level, ie. no nested objects supported.
     *
     * @param $schema The schema of the form
     */
    public function setFields($fields)
    {
        $this->fields = $fields;
    }

    public function setPostTypeSettings($postTypeSettings)
    {
        $this->postTypeSettings = array_merge($this->postTypeDefaults, $postTypeSettings);
    }

    public function addFileUpload($fieldName, $validations, $required = false)
    {
        $this->fileUploadSettings[] = [
            'name' => $fieldName,
            'validations' => empty($validations) ? $this->fileValidationDefaults : $validations,
            'required' => $required,
        ];
    }

    public function registerPostType()
    {
         register_post_type($this->slug, $this->postTypeSettings);
    }

    public function registerACFFields()
    {
        acf_add_local_field_group(array(
            'key' => 'group_signup_' . $this->slug,
            'title' => $this->name,
            'fields' => $this->createACFFields(),
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => $this->slug,
                    ],
                ],
            ],
        ));
    }

    public function registerRESTRoute()
    {
        register_rest_route('qd/forms/v1', '/' . $this->slug, [
            [
                'methods'  => 'POST',
                'callback' => array($this, 'restPostHandler'),
                'args'     => $this->getRestArgs(),
            ],
        ]);
    }

    public function restPostHandler($request)
    {
        // TODO: Proper return types on error
        // TODO: Handle redirect URLS on error

        // Get redirect url if set
        $redirectUrl = $request['page_redirect_url'];

        // Apply filters to allow modification of custom fields
        // We trust the WP Rest API to force required fields here
        $customFields = apply_filters('qd_forms_submission_custom_fields', $request->get_body_params(), $this->slug, $request);

        // Basic signup post settings
        $postTitle   = apply_filters('qd_forms_submission_title', $this->name . ' submit', $this->slug, $customFields, $request);
        $postType    = apply_filters('qd_forms_submission_post_type', $this->slug, $this->slug, $customFields, $request);
        $postStatus  = apply_filters('qd_forms_submission_status', 'publish', $this->slug, $customFields, $request);
        $postContent = apply_filters('qd_forms_submission_content', '', $this->slug, $customFields, $request);

        $basic_signup = [
            'post_title'   => $postTitle,
            'post_type'    => $postType,
            'post_status'  => $postStatus,
            'post_content' => $postContent,
        ];

        // Create the post
        $postId = \QD\Post::insertPost($basic_signup, $customFields);

        // If post creation did not succeed, return false
        if (!$postId) {
            do_action('qd_forms_post_creation_failed', $this->slug);
            do_action('qd_forms_submission_failed', $this->slug);

            return rest_ensure_response(array('success' => false));
        }

        // Handle uploads
        $fileUploadsSucceeded = $this->handleUploads($postId);

        // If required files did not upload correctly, delete the submission post and return false
        if (!$fileUploadsSucceeded) {
            do_action('qd_forms_file_uploads_failed', $this->slug);
            do_action('qd_forms_submission_failed', $this->slug);

            // Delete the post as well as attachments
            \QD\Post::deletePost($postId, false, true);

            return rest_ensure_response(array('success' => false));
        }

        do_action('qd_forms_submission_success', $this->slug, $postId);

        // Redirect if a url is set
        if ($redirectUrl) {
            wp_redirect("$redirectUrl?success=true");
            
            exit;
        } else {
            return rest_ensure_response(array('success' => true));
        }
    }

    /**
     * Uploads the file defined by the $fileUploadSettings array
     * Returns true if all required uploads were successfully uploaded
     */
    private function handleUploads($postId)
    {
        // Handle uploads
        $success = array_map(function ($fileSettings) use ($postId) {
            $filesOrFalse = \QD\SecureUploadService::upload($fileSettings['name'], $this->slug, $postId, $fileSettings['validations']);

            if ($filesOrFalse) {
                update_field($fileSettings['name'], $filesOrFalse, $postId);
            }



            // Return true if uploads are not required to bypass the later check
            if ($fileSettings['required']) {
                return $filesOrFalse !== false;
            } else {
                return true;
            }
        }, $this->fileUploadSettings);

        $allFilesSucceeded = array_reduce($success, function ($a, $b) {
            return $a && $b;
        }, true);

        return $allFilesSucceeded;
    }

    public function getRestArgs()
    {

        $restArgs = [];

        foreach ($this->fields as $field) {
            // Skip fields configured to be hidden from the REST API
            if (array_key_exists('show_in_rest', $field) && $field['show_in_rest'] === false) {
                continue;
            }

            // Common args between both AFC and REST
            $inheritedArgs = [];

            // Specific schema overrides
            $overrides = array_key_exists('schema', $field) ? $field['schema'] : [];

            if (array_key_exists('required', $field)) {
                $inheritedArgs['required'] = $field['required'];
            }

            if (array_key_exists('label', $field)) {
                $inheritedArgs['description'] = $field['label'];
            }

            if (array_key_exists('default', $field)) {
                $inheritedArgs['default'] = $field['default'];
            }

            // Schema fields set directly in schema key override inherited args
            // (those common to both fields and schema) and they all override the global defaults
            $restArgs[$field['slug']] = array_merge($this->schemaDefaults, $inheritedArgs, $overrides);
        }

        // Add page redirect field
        $redirectField = [
            'page_redirect_url' => [
                'sanitize_callback' => 'sanitize_text_field',
                'type' => 'string',
            ],
        ];

        return array_merge($redirectField, $restArgs);
    }

    private function createACFFields()
    {

        $textFields = array_map(function ($field) {
            $defaults = [
                'key' => 'signup_' . $this->slug . '_' . $field['slug'],
                'label' => $field['slug'],
                'name'  => $field['slug'],
                'type' => 'text',
            ];

            $inherited = [];

            $overrides = array_key_exists('field', $field) ? $field['field'] : [];

            if (array_key_exists('required', $field)) {
                $inherited['required'] = $field['required'];
            }

            if (array_key_exists('label', $field)) {
                $inherited['label'] = $field['label'];
            }

            if (array_key_exists('default', $field)) {
                $inherited['default'] = $field['default'];
            }

            $merged = array_merge($defaults, $inherited, $overrides);

            return apply_filters('qd_forms_field_setup', $merged, $this->slug, $field);
        }, $this->fields);
        
        $fileFields = array_map(function ($field) {
            $fieldSetup = [
                'key' => 'signup_' . $this->slug . '_' . $field['name'],
                'label' => $field['name'],
                'name'  => $field['name'],
                'type'  => 'text',
            ];

            // Override with any filters set
            return array_merge($fieldSetup, apply_filters('qd_forms_upload_field_setup', [], $this->slug, $fieldSetup, $field['name']));
        }, $this->fileUploadSettings);

        return array_merge($textFields, $fileFields);
    }
}
