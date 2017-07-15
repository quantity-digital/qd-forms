<?php

use QD\Signups;

class SignupCreatorTest extends PHPUnit_Framework_TestCase {
	public $signup;

	public function setUp() {
		\WP_Mock::setUp();

		$this->signup = new QD\Signups\SignupCreator();
	}

	public function tearDown() {
		\WP_Mock::tearDown();
	}

	/**
	 * @test
	 */
	public function addFieldShouldAddValueToFieldsArray()
	{
		$field = new QD\Signups\SignupField();

		$this->signup->addField( $field );
		$this->assertContains( $field, $this->signup->fields );
	}

    /**
     * @test 
	 * @expectedException TypeError
     */
	public function addFieldShouldOnlyAcceptSignupFieldClass() 
	{
		$this->signup->addField([ 'Fry' => 'Human' ]);
	}

	/**
	 * @test
	 */
	public function setNameShouldSetTheName()
	{
		$this->signup->setName( 'Email signup' );
		$this->assertEquals( $this->signup->name, 'Email signup' );
	}

	/**
	 * @test
	 */
	public function setSlugShouldSetTheSlug()
	{
		$this->signup->setSlug( 'email-signup' );
		$this->assertEquals( $this->signup->slug, 'email-signup' );
	}

	/**
	 * @test
	 * @expectedException Exception
	 * @expectedExceptionMessage Slug should be no longer than 20 characters
	 */
	public function setSlugShouldThrowWhenLengthExceeds20()
	{
		$this->signup->setSlug( 'email-signup-that-is-awesome' );
	}

	/**
	 * @test
	 */
	public function setPostTypeSettingsShouldSetThePostTypeSettings()
	{
		$this->signup->setPostTypeSettings([ 'public' => false ]);
		$this->assertEquals( $this->signup->postTypeSettings, [ 'public' => false ] );
	}

	/**
	 * @test
	 * @expectedException Exception
	 * @expectedExceptionMessage Name should be set before running init
	 */
	public function initShouldThrowIfNoName()
	{
		$this->signup->setSlug( 'email-signup' );
		$this->signup->init();
	}

	/**
	 * @test
	 * @expectedException Exception
	 * @expectedExceptionMessage Slug should be set before running init
	 */
	public function initShouldThrowIfNoSlug()
	{
		$this->signup->setName( 'Email Signup' );
		$this->signup->init();
	}

	/**
	 * @test
	 */
	public function initShouldAddAppropriateActions()
	{
		\WP_Mock::expectActionAdded( 'init', array( $this->signup, 'registerPostType' ), 0, 0 );
		\WP_Mock::expectActionAdded( 'acf/init', array( $this->signup, 'registerACFFields' ), 0, 0 );
		\WP_Mock::expectActionAdded( 'rest_api_init', array( $this->signup, 'registerRESTRoute' ), 0, 0 );

		$this->signup->setName( 'Email Signup' );
		$this->signup->setSlug( 'email-signup' );
		$this->signup->init();

		$this->assertTrue( $this->signup->initHasRun );
	}

	/**
	 * @test
	 */
	public function registerPostTypesRunsRegisterPostTypeWithSlugAndSettings()
	{
		\WP_Mock::userFunction( 'register_post_type', array(
			'times' => 1,
			'args' => [ 'email-signup', [ 'public' => false ] ],
		) );

		$this->signup->setName( 'Email Signup' );
		$this->signup->setSlug( 'email-signup' );
		$this->signup->setPostTypeSettings([ 'public' => false ]);
		$this->signup->init();

		$this->signup->registerPostType();
	}

	/**
	 * @test
	 */
	public function registerACFFieldsGetsFieldGroupSettingsAndAddsTheFieldGroup()
	{
		\WP_Mock::userFunction( 'acf_add_local_field_group')
			->once()
			->with( 'MyMockedSettings' );

		$mock = \Mockery::mock( QD\Signups\SignupCreator::class )->makePartial();

		$mock
			->shouldReceive('getACFFieldSettings')
			->with([ ])
			->once()
			->andReturn( 'MyMockedFieldSettings' );

		$mock
			->shouldReceive('getACFFieldGroupSettings')
			->with( 'email-signup', 'Email Signup', 'MyMockedFieldSettings' )
			->once()
			->andReturn( 'MyMockedSettings' );
		
		$mock->setName( 'Email Signup' );
		$mock->setSlug( 'email-signup' );
		$mock->init();

		$mock->registerACFFields();
	}

	/**
	 * @test
	 */
	public function getACFFieldGroupSettingsReturnsCorrectSettings()
	{
		$this->signup->setName( 'Email Signup' );
		$this->signup->setSlug( 'email-signup' );
		$this->signup->init();

		$settings = $this->signup->getACFFieldGroupSettings( 'foo', 'bar', [ 'baz' ] );

		$this->assertEquals( $settings, [
            'key' => 'group_signup_foo',
            'title' => 'bar',
            'fields' => [ 'baz' ],
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'foo',
                    ],
                ],
            ],
        ]);
	}

	/**
	 * @test
	 */
	public function getACFFieldSettingsRunsOnEachFieldAndReturnsArrayOfTheResults()
	{
		$this->signup->setName( 'Email Signup' );
		$this->signup->setSlug( 'email-signup' );
		$this->signup->init();

		$fieldMock1 = \Mockery::mock( QD\Signups\SignupField::class );
		$fieldMock2 = \Mockery::mock( QD\Signups\SignupField::class );

		$fieldMock1->shouldReceive( 'getACFFieldSettings' )->andReturn( 'mock1' );
		$fieldMock2->shouldReceive( 'getACFFieldSettings' )->andReturn( 'mock2' );

		$fields = [ $fieldMock1, $fieldMock2 ];

		$settings = $this->signup->getACFFieldSettings( $fields );

		$this->assertEquals( $settings, [ 'mock1', 'mock2' ]);
	}

}
