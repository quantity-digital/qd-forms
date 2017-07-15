<?php

namespace QD\Signups;

class SignupCreator
{
    public $initHasRun = false;
    public $fields = []; 
    public $name;
    public $slug;
    public $postTypeSettings;

    public function __construct() 
    {

    }

    public function addField( SignupField $field ) {
        $this->fields[] = $field;
    }

    public function setName( $name ) {
        $this->name = $name;
    }

    public function setSlug( $slug ) {
        if ( strlen( $slug ) > 20 ) {
            throw new \Exception( 'Slug should be no longer than 20 characters' );
        }

        $this->slug = $slug;
    }

    public function setPostTypeSettings( array $postTypeSettings ) {
        $this->postTypeSettings = $postTypeSettings;
    }

    public function init()
    {
        if ( $this->initHasRun ) {
            return false;
        }

        if ( empty( $this->name ) ) {
            throw new \Exception( 'Name should be set before running init' );
        }

        if ( empty( $this->slug ) ) {
            throw new \Exception( 'Slug should be set before running init' );
        }

        add_action('init', array($this, 'registerPostType'), 0, 0);
        add_action('acf/init', array($this, 'registerACFFields'), 0, 0);
        add_action('rest_api_init', array($this, 'registerRESTRoute'), 0, 0);

        $this->initHasRun = true;
    }

    public function registerPostType()
    {
        register_post_type($this->slug, $this->postTypeSettings);
    }

    public function registerACFFields()
    {
        $fields = $this->getACFFieldSettings( $this->fields );
        $settings = $this->getACFFieldGroupSettings( $this->slug, $this->name, $fields );

        acf_add_local_field_group( $settings );
    }

    public function getACFFieldGroupSettings( $slug, $name, $fields )
    {
        return [
            'key' => 'group_signup_' . $slug,
            'title' => $name,
            'fields' => $fields,
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => $slug,
                    ],
                ],
            ],
        ];
    }

    public function getACFFieldSettings( array $fields )
    {
        return array_map(function( SignupField $field ) {
            return $field->getACFFieldSettings();
        }, $fields);
    }

}
