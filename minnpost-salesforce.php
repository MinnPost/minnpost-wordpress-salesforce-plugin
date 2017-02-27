<?php
/*
Plugin Name: MinnPost Salesforce
Plugin URI: 
Description: 
Version: 0.0.1
Author: Jonathan Stegall
Author URI: http://code.minnpost.com
License: GPL2+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: minnpost-salesforce
*/

// Start up the plugin
class Minnpost_Salesforce {

	/**
	* @var string
	*/
	private $version;

	/**
	* @var object
	*/
	public $salesforce;

	/**
	 * This is our constructor
	 *
	 * @return void
	 */
	public function __construct() {
		$this->version = '0.0.1';
		$this->admin_init();
		$this->init();
        register_activation_hook( __FILE__, array( $this, 'add_user_fields') );
        register_activation_hook( __FILE__, array( $this, 'add_roles_capabilities' ) );
        register_deactivation_hook( __FILE__, array( $this, 'remove_roles_capabilities' ) );
	}

	/**
	* admin start
	*
	* @throws \Exception
	*/
	private function admin_init() {
		add_action( 'admin_init', array( $this, 'salesforce' ) );
		add_action( 'admin_init', array( $this, 'minnpost_salesforce_settings_forms' ) );
	}

    public function add_user_fields() {
        add_user_meta( 1, 'member_level', '' );
    }

	/**
	* start
	*
	* @throws \Exception
	*/
    private function init() {
    	add_filter( 'salesforce_rest_api_find_sf_object_match', array( $this, 'find_sf_object_match' ), 10, 4 );
    	add_filter( 'salesforce_rest_api_push_object_allowed', array( $this, 'push_not_allowed' ), 10, 5 );
    	add_filter( 'salesforce_rest_api_settings_tabs', array( $this, 'minnpost_tabs'), 10, 1 );
        add_action( 'salesforce_rest_api_push_success', array( $this, 'push_member_level' ), 10, 4 );
        add_action( 'salesforce_rest_api_pre_pull', array( $this, 'pull_member_level' ), 10, 5 );
        
    }

    /**
	* Load the Salesforce object
	* Also make it available to this whole class
	*
	* @return $this->salesforce
	*
	*/
    public function salesforce() {
		// get the base class
		if ( ! function_exists( 'is_plugin_active' ) ) {
     		require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
     	}
		if ( is_plugin_active('salesforce-rest-api/salesforce-rest-api.php') ) {
			require_once plugin_dir_path( __FILE__ ) . '../salesforce-rest-api/salesforce-rest-api.php';
			$salesforce = Salesforce_Rest_API::get_instance();
			$this->salesforce = $salesforce;
			return $this->salesforce;
		}
	}

	/**
    * Create default WordPress admin settings form for MinnPost-specific salesforce things
    * This is for the Settings page/tab
    *
    */
    public function minnpost_salesforce_settings_forms() {
        $page = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
        $section = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
        $input_callback_default = array( $this, 'display_input_field' );
        $input_checkboxes_default = array( $this, 'display_checkboxes' );
        $this->fields_minnpost_settings( 'minnpost', 'minnpost', array( 'text' => $input_callback_default, 'checkboxes' => $input_checkboxes_default ) );
    }

    /**
    * Fields for the Log Settings tab
    * This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
    *
    * @param string $page
    * @param string $section
    * @param array $callbacks
    */
    private function fields_minnpost_settings( $page, $section, $callbacks ) {
        add_settings_section( $page, ucwords( str_replace('_', ' ', $page) ), null, $page );
        // todo: figure out how to pick what objects to prematch against and put that here in the admin settings
        $minnpost_salesforce_settings = array(
            'nonmember_level_name' => array(
                'title' => 'Name of Non-Member Level',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'text',
                    'desc' => '',
                    'constant' => ''
                ),
            ),
        );
        foreach ( $minnpost_salesforce_settings as $key => $attributes ) {
            $id = 'salesforce_api_' . $key;
            $name = 'salesforce_api_' . $key;
            $title = $attributes['title'];
            $callback = $attributes['callback'];
            $page = $attributes['page'];
            $section = $attributes['section'];
            $args = array_merge(
                $attributes['args'],
                array(
                    'title' => $title,
                    'id' => $id,
                    'label_for' => $id,
                    'name' => $name
                )
            );
            add_settings_field( $id, $title, $callback, $page, $section, $args );
            register_setting( $section, $id );
        }
    }

   function minnpost_tabs( $tabs ) {
		$tabs['minnpost'] = 'MinnPost';
		return $tabs;
	}

    public function push_not_allowed( $push_allowed, $object_type, $object, $sf_sync_trigger, $mapping ) {
    	if ( $object_type === 'user' && $object['ID'] === 1 ) { // do not add user 1 to salesforce
			return FALSE;
		}
    }

    /**
	* Find an object match between a WordPress object and a Salesforce object
	* This is designed to find out if there is already a map based on the available WordPress data
	*
	* @param string $salesforce_id
	*	Unique identifier for the Salesforce object
	* @param array $wordpress_object
	*	Array of the wordpress object's data
    * @param array $mapping
    *   Array of the fieldmap between the WordPress and Salesforce object types
    * @param string $action
    *   Is this a push or pull action?
	*
	* @return array $salesforce_id
	*	Unique identifier for the Salesforce object
	*
	* todo: may need a way for this to prevent a deletion in Salesforce if multiple contacts match the email address, for example. the plugin itself will block it if there are existing map rows. we might need to expand it for this, or maybe it is sufficient as it is. mp would probably turn off the delete hooks anyway.
	*
	*/
	public function find_sf_object_match( $salesforce_id, $wordpress_object, $mapping = array(), $action ) {

        if ( $action === 'push' && $mapping['wordpress_object'] === 'user' ) {
    		if ( is_object( $this->salesforce ) ) {
    			$salesforce_api = $this->salesforce->salesforce['sfapi'];
    		} else {
    			$salesforce = $this->salesforce();
    			$salesforce_api = $salesforce->salesforce['sfapi'];
    		}
    		
    		if ( is_object( $salesforce_api ) ) {

    			// we want to see if the user's email address exists as a primary on any contact and use that contact if so
    			$mail = $wordpress_object['user_email'];
    			$query = "SELECT Primary_Contact__c FROM Email__c WHERE Email_Address__c = '$mail'";
    			$result = $salesforce_api->query( $query );

    			if ( $result['data']['totalSize'] === 1 ) {
    				$salesforce_id = $result['data']['records'][0]['Primary_Contact__c'];
    			} elseif ( $result['data']['totalSize'] > 1 ) {
    				error_log('Salesforce has ' . $result['data']['totalSize'] . ' matches for this email. Try to log all of them: ' . print_r($result['data']['records'], true));
    			}
    		}
        }

		return $salesforce_id;
	}

    /**
    * Apply the member level to the user's roles
    * This runs after the user has been pushed to Salesforce and has a response from Salesforce, which may have a member level
    * If the current object is a user with an ID, and it comes from Salesforce with a member level, do stuff with it
    * Currently it just deals with the roles associated with the user
    *
    * @param string $op
    *   What kind of operation we were doing (create, update, delete)
    * @param array $sf_response
    *   The full response from Salesforce
    * @param array $synced_object
    *   The WordPress object, object map, and field map together
    * @param string $object_id
    *   How to identify the ID field for the WordPress object
    *
    */
    public function push_member_level( $op, $sf_response, $synced_object, $object_id ) {

        // we run it on the push_success hook because that gives us the salesforce data we need
        if ( isset( $synced_object['wordpress_object'][$object_id] ) && isset( $sf_response['data']['Membership_Level__c'] ) ) {
            $wordpress_id = $synced_object['wordpress_object'][$object_id];
            $salesforce_member_level = $sf_response['data']['Membership_Level__c'];
            $this->set_member_level( $object_id, $wordpress_id, $salesforce_member_level );
        }

    }

    /**
    * Apply the member level to the user's roles
    * This runs before the user has been pulled from Salesforce, but we have Salesforce data for it, which may have a member level
    * If the current object is a user with an ID, and it comes from Salesforce with a member level, do stuff with it
    * Currently it just deals with the roles associated with the user
    *
    * @param int $wordpress_id
    *   ID for the WordPress object
    * @param array $mapping
    *   The fieldmap between the WordPress and Salesforce objects
    * @param array $object
    *   The Salesforce object
    * @param string $object_id
    *   How to identify the ID field for the WordPress object
    * @param array $params
    *   The params array that matches fields to each other for saving
    *
    */
    public function pull_member_level( $wordpress_id, $mapping, $object, $object_id, $params ) {

        // as per this question, if the only thing that changes is the member level formula that we reference, the updated api call does not get triggered
        // https://salesforce.stackexchange.com/questions/42726/how-to-detect-changes-in-formula-field-value-via-api

        // i think it should run on the pre pull hook because we don't let salesforce create users by itself
        if ( $wordpress_id !== NULL && isset( $params['member_level']['value'] ) ) {
            $this->set_member_level( $object_id, $wordpress_id, $params['member_level']['value'] );
        }

    }

    /**
    * Do the actual setting of the member level.
    * This works the same for push and pull, it just requires the correct data
    *
    * @param string $object_id
    *   How to identify the ID field for the WordPress object
    * @param int $wordpress_id
    *   ID for the WordPress object
    * @param array $salesforce_member_level
    *   The member level value from Salesforce
    *
    */
    private function set_member_level( $object_id, $wordpress_id, $salesforce_member_level ) {
        $user = get_user_by( $object_id, $wordpress_id );
        if ( $user !== FALSE ) {

            $nonmember_level_name = get_option( 'salesforce_api_nonmember_level_name', 'Non-member' );
            
            if ( $salesforce_member_level !== $nonmember_level_name ) {
                $level_from_salesforce = 'member_' . strtolower( substr( $salesforce_member_level, 9 ) );
            } else {
                $level_from_salesforce = $salesforce_member_level;
            }

            $wp_roles = new WP_Roles(); // get all the available roles in wordpress
            $wp_roles = $wp_roles->get_names(); // just get the names
            
            $this_user_roles = $user->roles; // this is roles for this user
            
            // check all the user's current roles
            if ( !empty( $this_user_roles ) ) {
                foreach ( $this_user_roles as $key => $value ) {

                    $level_from_wordpress = $value;

                    // if the user's role didn't change, get out of this function
                    if ( strpos( $value, 'member_' ) !== FALSE && $level_from_wordpress === $level_from_salesforce ) {
                        return;
                    }

                    // this user was a member but now they're not. remove the level and get out of this function.
                    if ( strpos( $value, 'member_' ) !== FALSE && $level_from_salesforce === $nonmember_level_name ) {
                        // this user is no longer a member, so get rid of the level
                        $user->remove_role( $value );
                        return;
                    }

                    // if the user has a new member level, get rid of the old one
                    if ( strpos( $value, 'member_' ) !== FALSE && $level_from_wordpress !== $level_from_salesforce ) {
                        $user->remove_role( $value );
                    }

                }
            }

            // if the salesforce level is a role, add it to the user
            if ( array_key_exists( $level_from_salesforce, $wp_roles ) ) {
                $user->add_role( $level_from_salesforce );
            }

        }
    }

    /**
    * Add roles and capabilities
    * This adds the member roles
    *
    */ 
    public function add_roles_capabilities() {
        $bronze = add_role('member_bronze', 'Member - Bronze', array());
        $silver = add_role('member_silver', 'Member - Silver', array());
        $gold = add_role('member_gold', 'Member - Gold', array());
        $platinum = add_role('member_platinum', 'Member - Platinum', array());
    }

    /**
    * Remove roles and capabilities
    * This removes the member roles
    *
    */
    public function remove_roles_capabilities() {
        remove_role('member_bronze');
        remove_role('member_silver');
        remove_role('member_gold');
        remove_role('member_platinum');
    }

	/**
    * Default display for <input> fields
    *
    * @param array $args
    */
    public function display_input_field( $args ) {
        $type   = $args['type'];
        $id     = $args['label_for'];
        $name   = $args['name'];
        $desc   = $args['desc'];
        $checked = '';

        $class = 'regular-text';

        if ( $type === 'checkbox' ) {
            $class = 'checkbox';
        }

        if ( !defined( $args['constant'] ) ) {
            $value  = esc_attr( get_option( $id, '' ) );
            if ( $type === 'checkbox' ) {
                if ( $value === '1' ) {
                    $checked = 'checked ';
                }
                $value = 1;
            }
            if ( $value === '' && isset( $args['default'] ) && $args['default'] !== '' ) {
                $value = $args['default'];
            }
            echo '<input type="' . $type. '" value="' . $value . '" name="' . $name . '" id="' . $id . '"
            class="' . $class . ' code" ' . $checked . ' />';
            if ( $desc != '' ) {
                echo '<p class="description">' . $desc . '</p>';
            }
        } else {
            echo '<p><code>Defined in wp-config.php</code></p>';
        }
    }

    /**
    * Display for multiple checkboxes
    * Above method can handle a single checkbox as it is
    *
    * @param array $args
    */
    public function display_checkboxes( $args ) {
        $type = 'checkbox';
        $name = $args['name'];
        $options = get_option( $name );
        foreach ( $args['items'] as $key => $value ) {
            $text = $value['text'];
            $id = $value['id'];
            $desc = $value['desc'];
            $checked = '';
            if (is_array( $options ) && in_array( $key, $options ) ) {
                $checked = 'checked';
            }
            echo '<div><label><input type="' . $type. '" value="' . $key . '" name="' . $name . '[]" id="' . $id . '" ' . $checked . ' />' . $text . '</label></div>';
            if ( $desc != '' ) {
                echo '<p class="description">' . $desc . '</p>';
            }
        }
    }


/// end class
}
// Instantiate our class
$Minnpost_Salesforce = new Minnpost_Salesforce();