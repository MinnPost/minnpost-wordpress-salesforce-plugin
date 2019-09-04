<?php
/*
Plugin Name: MinnPost Salesforce
Plugin URI:
Description:
Version: 0.0.4
Author: Jonathan Stegall
Author URI: https://code.minnpost.com
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
		$this->version = '0.0.4';
		$this->admin_init();
		$this->init();
		register_activation_hook( __FILE__, array( $this, 'add_user_fields' ) );
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
		add_filter( 'object_sync_for_salesforce_find_sf_object_match', array( $this, 'find_sf_object_match' ), 10, 4 );
		add_filter( 'object_sync_for_salesforce_push_object_allowed', array( $this, 'push_not_allowed' ), 10, 5 );
		add_filter( 'object_sync_for_salesforce_settings_tabs', array( $this, 'minnpost_tabs' ), 10, 1 );
		add_action( 'object_sync_for_salesforce_push_success', array( $this, 'push_member_level' ), 10, 5 );
		add_filter( 'object_sync_for_salesforce_push_update_params_modify', array( $this, 'set_names_if_missing' ), 10, 5 );
		add_action( 'object_sync_for_salesforce_pre_pull', array( $this, 'pull_member_level' ), 10, 5 );
		add_filter( 'user_account_management_custom_error_message', array( $this, 'login_fail_check' ), 10, 3 );

		add_filter( 'minnpost_membership_get_active_recurring_donations', array( $this, 'get_active_recurring_donations' ), 10, 5 );
		add_filter( 'minnpost_membership_get_pledged_opportunities', array( $this, 'get_pledged_opportunities' ), 10, 7 );

		add_filter( 'minnpost_membership_get_failed_opportunities', array( $this, 'get_failed_opportunities' ), 10, 10 );
		add_filter( 'minnpost_membership_get_successful_opportunities', array( $this, 'get_successful_opportunities' ), 10, 4 );

		add_filter( 'minnpost_membership_get_member_level', array( $this, 'get_member_level' ), 10, 3 );
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
		if ( is_plugin_active( 'object-sync-for-salesforce/object-sync-for-salesforce.php' ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../object-sync-for-salesforce/object-sync-for-salesforce.php';
			$salesforce       = Object_Sync_Salesforce::get_instance();
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
		$get_data = filter_input_array( INPUT_GET, FILTER_SANITIZE_STRING );
		$page     = isset( $get_data['tab'] ) ? sanitize_key( $get_data['tab'] ) : 'settings';
		$section  = isset( $get_data['tab'] ) ? sanitize_key( $get_data['tab'] ) : 'settings';

		$input_callback_default   = array( $this, 'display_input_field' );
		$input_checkboxes_default = array( $this, 'display_checkboxes' );
		$this->fields_minnpost_settings(
			'minnpost',
			'minnpost',
			array(
				'text'       => $input_callback_default,
				'checkboxes' => $input_checkboxes_default,
			)
		);
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
		add_settings_section( $page, ucwords( str_replace( '_', ' ', $page ) ), null, $page );
		// todo: figure out how to pick what objects to prematch against and put that here in the admin settings
		$minnpost_salesforce_settings = array(
			'nonmember_level_name' => array(
				'title'    => 'Name of Non-Member Level',
				'callback' => $callbacks['text'],
				'page'     => $page,
				'section'  => $section,
				'args'     => array(
					'type'     => 'text',
					'desc'     => '',
					'constant' => '',
				),
			),
		);
		foreach ( $minnpost_salesforce_settings as $key => $attributes ) {
			$id       = 'salesforce_api_' . $key;
			$name     = 'salesforce_api_' . $key;
			$title    = $attributes['title'];
			$callback = $attributes['callback'];
			$page     = $attributes['page'];
			$section  = $attributes['section'];
			$args     = array_merge(
				$attributes['args'],
				array(
					'title'     => $title,
					'id'        => $id,
					'label_for' => $id,
					'name'      => $name,
				)
			);
			add_settings_field( $id, $title, $callback, $page, $section, $args );
			register_setting( $section, $id );
		}
	}

	/**
	* Add an admin tab to the Salesforce plugin's settings
	*
	* @param array $tabs
	* @return array $tabs
	*/
	public function minnpost_tabs( $tabs ) {
		$tabs['minnpost'] = 'MinnPost';
		return $tabs;
	}

	/**
	* Do not add user with ID of 1 to Salesforce
	*
	* @param bool $push_allowed
	* @param string $object_type
	* @param array $object
	* @param int $sf_sync_trigger
	* @param array $mapping
	* @return bool $push_allowed
	*/
	public function push_not_allowed( $push_allowed, $object_type, $object, $sf_sync_trigger, $mapping ) {
		if ( 'user' === $object_type && 1 === $object['ID'] ) { // do not add user 1 to salesforce
			$push_allowed = false;
		}
		return $push_allowed;
	}

	/**
	* Find an object match between a WordPress object and a Salesforce object
	* This is designed to find out if there is already a map based on the available WordPress data
	*
	* @param string $salesforce_id
	*   Unique identifier for the Salesforce object
	* @param array $wordpress_object
	*   Array of the WordPress object's data
	* @param array $mapping
	*   Array of the fieldmap between the WordPress and Salesforce object types
	* @param string $action
	*   Is this a push or pull action?
	*
	* @return array $salesforce_id
	*   Unique identifier for the Salesforce object
	*
	* todo: may need a way for this to prevent a deletion in Salesforce if multiple contacts match the email address, for example. the plugin itself will block it if there are existing map rows. we might need to expand it for this, or maybe it is sufficient as it is. mp would probably turn off the delete hooks anyway.
	*
	*/
	public function find_sf_object_match( $salesforce_id, $wordpress_object, $mapping = array(), $action ) {

		if ( 'push' === $action && 'user' === $mapping['wordpress_object'] ) {
			if ( is_object( $this->salesforce ) ) {
				$salesforce_api = $this->salesforce->salesforce['sfapi'];
			} else {
				$salesforce     = $this->salesforce();
				$salesforce_api = $salesforce->salesforce['sfapi'];
			}

			if ( is_object( $salesforce_api ) ) {

				// we want to see if the user's email address exists as a primary on any contact and use that contact if so
				$mail   = $wordpress_object['user_email'];
				$query  = "SELECT Id FROM Contact WHERE Consolidated_EMail__c LIKE '%$mail%'";
				$result = $salesforce_api->query( $query );

				if ( isset( $result['data']['totalSize'] ) && 1 === $result['data']['totalSize'] ) {
					$salesforce_id = $result['data']['records'][0]['Id'];
				} elseif ( isset( $result['data']['totalSize'] ) && $result['data']['totalSize'] > 1 ) {
					error_log( 'Salesforce has ' . $result['data']['totalSize'] . ' matches for this email. Try to log all of them: ' . print_r( $result['data']['records'], true ) );
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
	*   The Salesforce ID
	* @param string $wordpress_id_field_name
	*   How to identify the ID field for the WordPress object
	*
	*/
	public function push_member_level( $op, $sf_response, $synced_object, $object_id, $wordpress_id_field_name ) {
		// we run it on the push_success hook because that gives us the salesforce data we need
		if ( isset( $synced_object['wordpress_object'][ $wordpress_id_field_name ] ) && isset( $sf_response['data']['Membership_Level__c'] ) ) {
			$wordpress_id            = $synced_object['wordpress_object'][ $wordpress_id_field_name ];
			$salesforce_member_level = $sf_response['data']['Membership_Level__c'];
			$this->set_member_level( $wordpress_id_field_name, $wordpress_id, $salesforce_member_level );
		}

	}

	/**
	* Set contact name fields if this is a new Contact being added to Salesforce
	* This runs before the user has been pushed to Salesforce, but we have data for it, which may have a Salesforce ID
	* @param array $params
	*   Params mapping the fields to their values
	* @param string $salesforce_id
	*   Salesforce ID if there is a matched object
	* @param array $mapping
	*   Mapping object.
	* @param array $object
	*   WordPress object data.
	* @param string $object_type
	*   WordPress object type
	*
	*/
	public function set_names_if_missing( $params, $salesforce_id, $mapping, $object, $object_type = '' ) {
		if ( 'user' === $object_type && null === $salesforce_id ) {
			$params['FirstName'] = $object['first_name'];
			$params['LastName']  = $object['last_name'];
		}
		return $params;

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
	* @param string $wordpress_id_field_name
	*   How to identify the ID field for the WordPress object
	* @param array $params
	*   The params array that matches fields to each other for saving
	*
	*/
	public function pull_member_level( $wordpress_id, $mapping, $object, $wordpress_id_field_name, $params ) {

		// as per this question, if the only thing that changes is the member level formula that we reference, the updated api call does not get triggered
		// https://salesforce.stackexchange.com/questions/42726/how-to-detect-changes-in-formula-field-value-via-api

		// i think it should run on the pre pull hook because we don't let salesforce create users by itself
		if ( null !== $wordpress_id && isset( $params['member_level']['value'] ) ) {
			$this->set_member_level( $wordpress_id_field_name, $wordpress_id, $params['member_level']['value'] );
		}

	}

	/**
	* If a user fails to log in, check to see if they exist in Salesforce
	*
	* @param string $message
	* @param string $error_code
	* @param array $data
	* @return string $message
	*
	*/
	public function login_fail_check( $message, $error_code, $data ) {
		if ( 'invalid_username' === $error_code || 'invalid_email' === $error_code || 'invalidcombo' === $error_code ) {
			if ( is_object( $this->salesforce ) ) {
				$salesforce_api = $this->salesforce->salesforce['sfapi'];
			} else {
				$salesforce     = $this->salesforce();
				$salesforce_api = $salesforce->salesforce['sfapi'];
			}
			if ( is_object( $salesforce_api ) ) {
				$mail = $data['user_email'];
				if ( isset( $mail ) ) {
					$query  = "SELECT Id FROM Contact WHERE Consolidated_EMail__c LIKE '%$mail%'";
					$result = $salesforce_api->query( $query );
					if ( isset( $result['data']['totalSize'] ) && 1 === $result['data']['totalSize'] ) {
						$salesforce_id = $result['data']['records'][0]['Id'];
						// translators: 1) is the register URL, 2) is the user's raw url encoded email address
						$message = sprintf(
							'We couldn\'t find a website account with that email address, but we do have a MinnPost membership record for it. You can <a href="%1$s?user_email=%2$s">create an account</a> to access member benefits and settings.',
							site_url( '/user/register/' ),
							rawurlencode( $mail )
						);
					}
				}
			}
		}
		return $message;
	}

	/**
	* Get the user's active recurring donations
	*
	* @param int $user_id
	* @param string $active_field_name
	* @param string $active_field_value
	* @param string $payment_type_field_name
	* @param string $payment_type_field_value
	* @return array $donations
	*
	*/
	public function get_active_recurring_donations( $user_id, $active_field_name, $active_field_value, $payment_type_field_name, $payment_type_field_value ) {

		$donations = array();

		if ( is_object( $this->salesforce ) ) {
			$salesforce = $this->salesforce;
		} else {
			$salesforce = $this->salesforce();
		}

		$mapping = $this->salesforce->mappings->load_by_wordpress( 'user', $user_id, true );
		if ( ! empty( $mapping ) ) {
			$salesforce_id  = $mapping['salesforce_id'];
			$salesforce_api = $salesforce->salesforce['sfapi'];
			$query          = "SELECT Id, npe03__Amount__c, npe03__Installment_Period__c, npe03__Next_Payment_Date__c FROM npe03__Recurring_Donation__c WHERE npe03__Contact__c = '$salesforce_id'";
			if ( '' !== $active_field_name && '' !== $active_field_value ) {
				$query .= " AND $active_field_name = '$active_field_value'";
			}
			if ( '' !== $payment_type_field_name && '' !== $payment_type_field_value ) {
				$query .= " AND $payment_type_field_name = '$payment_type_field_value'";
			}
			$result = $salesforce_api->query(
				$query,
				array(
					'cache'            => true,
					'cache_expiration' => MINUTE_IN_SECONDS * 5,
				)
			);
			if ( isset( $result['data']['totalSize'] ) && 0 <= $result['data']['totalSize'] ) {
				$records = $result['data']['records'];
				foreach ( $records as $record ) {
					$donations[] = array(
						'id'        => $record['Id'],
						'amount'    => $record['npe03__Amount__c'],
						'frequency' => $record['npe03__Installment_Period__c'],
						'next_date' => $record['npe03__Next_Payment_Date__c'],
					);
				}
			}
		}

		return $donations;
	}

	/**
	* Get the user's pledged opportunities
	*
	* @param int $user_id
	* @param string $recurrence_field
	* @param string $recurrence_value
	* @param string $contact_id_field
	* @param string $payment_type_field_name
	* @param string $payment_type_field_value
	* @param string $opportunity_type_value
	* @return array $donations
	*
	*/
	public function get_pledged_opportunities( $user_id, $recurrence_field, $recurrence_value, $contact_id_field, $payment_type_field_name, $payment_type_field_value, $opportunity_type_value = '' ) {

		$donations = array();

		if ( is_object( $this->salesforce ) ) {
			$salesforce = $this->salesforce;
		} else {
			$salesforce = $this->salesforce();
		}

		$mapping = $this->salesforce->mappings->load_by_wordpress( 'user', $user_id, true );
		if ( ! empty( $mapping ) ) {
			$salesforce_id  = $mapping['salesforce_id'];
			$salesforce_api = $salesforce->salesforce['sfapi'];
			$query          = "SELECT Id, Amount, CloseDate FROM Opportunity WHERE StageName = 'Pledged' AND $contact_id_field = '$salesforce_id'";
			if ( '' !== $recurrence_field && '' !== $recurrence_value ) {
				$query .= " AND $recurrence_field = '$recurrence_value'";
			}
			if ( '' !== $payment_type_field_name && '' !== $payment_type_field_value ) {
				$query .= " AND $payment_type_field_name = '$payment_type_field_value'";
			}
			if ( '' !== $opportunity_type_value ) {
				$query .= " AND Type = '$opportunity_type_value'";
			}
			$result = $salesforce_api->query(
				$query,
				array(
					'cache'            => true,
					'cache_expiration' => MINUTE_IN_SECONDS * 5,
				)
			);
			if ( isset( $result['data']['totalSize'] ) && 0 <= $result['data']['totalSize'] ) {
				$records = $result['data']['records'];
				foreach ( $records as $record ) {
					$donations[] = array(
						'id'        => $record['Id'],
						'amount'    => $record['Amount'],
						'next_date' => $record['CloseDate'],
					);
				}
			}
		}

		return $donations;
	}

	/**
	* Get the user's failed opportunities based on the passed criteria
	*
	* @param int $user_id
	* @param string $history_opp_contact_field
	* @param string $opp_payment_type_field
	* @param string $opp_payment_type_value
	* @param string $history_failed_value
	* @param string|int $history_days_for_failed
	* @param string $recurrence_field_name
	* @param string $recurrence_field_value
	* @param string $failed_recurring_id_field
	* @param string $opportunity_type_value
	* @return array $donations
	*
	*/
	public function get_failed_opportunities( $user_id, $history_opp_contact_field, $opp_payment_type_field, $opp_payment_type_value, $history_failed_value, $history_days_for_failed, $recurrence_field_name, $recurrence_field_value, $failed_recurring_id_field, $opportunity_type_value = '' ) {

		$donations = array();

		if ( is_object( $this->salesforce ) ) {
			$salesforce = $this->salesforce;
		} else {
			$salesforce = $this->salesforce();
		}

		$mapping = $this->salesforce->mappings->load_by_wordpress( 'user', $user_id, true );
		if ( ! empty( $mapping ) ) {
			$salesforce_id  = $mapping['salesforce_id'];
			$salesforce_api = $salesforce->salesforce['sfapi'];
			$query          = "SELECT Id, Amount, CloseDate, $failed_recurring_id_field, $recurrence_field_name FROM Opportunity WHERE StageName = '$history_failed_value' AND $history_opp_contact_field = '$salesforce_id'";
			if ( '' !== $opp_payment_type_field && '' !== $opp_payment_type_value ) {
				$query .= " AND $opp_payment_type_field = '$opp_payment_type_value'";
			}
			if ( '' !== $history_days_for_failed ) {
				$thirty_days_ago = date( 'Y-m-d', strtotime( '-30 days' ) );
				$today           = current_time( 'Y-m-d' );
				$query          .= " AND ( CloseDate <= $today AND CloseDate >= $thirty_days_ago )";
			}
			if ( '' !== $opportunity_type_value ) {
				$query .= " AND Type = '$opportunity_type_value'";
			}

			$result = $salesforce_api->query(
				$query,
				array(
					'cache'            => true,
					'cache_expiration' => MINUTE_IN_SECONDS * 5,
				)
			);
			if ( isset( $result['data']['totalSize'] ) && 0 <= $result['data']['totalSize'] ) {
				$records = $result['data']['records'];
				foreach ( $records as $record ) {
					if ( $record[ $recurrence_field_name ] !== $recurrence_field_value && '' !== $failed_recurring_id_field ) {
						$id = $record[ $failed_recurring_id_field ];
					} else {
						$id = $record['Id'];
					}
					$donation = array(
						'id'         => $id,
						'amount'     => $record['Amount'],
						'close_date' => $record['CloseDate'],
					);
					if ( $record[ $recurrence_field_name ] !== $recurrence_field_value && '' !== $failed_recurring_id_field ) {
						$donation['frequency'] = $record[ $recurrence_field_name ];
					}
					$donations[] = $donation;
				}
			}
		}

		return $donations;
	}

	/**
	* Get the user's successful opportunities based on the passed criteria
	*
	* @param int $user_id
	* @param string $history_opp_contact_field
	* @param string $history_success_value
	* @param string $opportunity_type_value
	* @return array $donations
	*
	*/
	public function get_successful_opportunities( $user_id, $history_opp_contact_field, $history_success_value, $opportunity_type_value = '' ) {

		$donations = array();

		if ( is_object( $this->salesforce ) ) {
			$salesforce = $this->salesforce;
		} else {
			$salesforce = $this->salesforce();
		}

		$mapping = $this->salesforce->mappings->load_by_wordpress( 'user', $user_id, true );
		if ( ! empty( $mapping ) ) {
			$salesforce_id  = $mapping['salesforce_id'];
			$salesforce_api = $salesforce->salesforce['sfapi'];
			$query          = "SELECT Id, Amount, CloseDate FROM Opportunity WHERE StageName = '$history_success_value' AND $history_opp_contact_field = '$salesforce_id'";
			if ( '' !== $opportunity_type_value ) {
				$query .= " AND Type = '$opportunity_type_value'";
			}
			$result = $salesforce_api->query(
				$query,
				array(
					'cache'            => true,
					'cache_expiration' => MINUTE_IN_SECONDS * 5,
				)
			);
			if ( isset( $result['data']['totalSize'] ) && 0 <= $result['data']['totalSize'] ) {
				$records = $result['data']['records'];
				foreach ( $records as $record ) {
					$donations[] = array(
						'id'         => $record['Id'],
						'amount'     => $record['Amount'],
						'close_date' => $record['CloseDate'],
					);
				}
			}
		}

		return $donations;
	}

	/**
	* Get the user's member level without cache
	*
	* @param int $user_id
	* @return string $member_level
	*
	*/
	public function get_member_level( $user_id ) {
		$member_level = '';

		if ( is_object( $this->salesforce ) ) {
			$salesforce = $this->salesforce;
		} else {
			$salesforce = $this->salesforce();
		}

		$mapping = $this->salesforce->mappings->load_by_wordpress( 'user', $user_id, true );
		if ( ! empty( $mapping ) ) {
			$salesforce_id  = $mapping['salesforce_id'];
			$salesforce_api = $salesforce->salesforce['sfapi'];
			$query          = "SELECT Id, Membership_Level__c FROM Contact WHERE Id = '$salesforce_id'";
			$result         = $salesforce_api->query(
				$query,
				array(
					'cache'            => true,
					'cache_expiration' => MINUTE_IN_SECONDS * 5,
				)
			);
			if ( isset( $result['data']['totalSize'] ) && 1 === $result['data']['totalSize'] ) {
				$member_level = $result['data']['records'][0]['Membership_Level__c'];
			}
		}

		return $member_level;
	}

	/**
	* Do the actual setting of the member level.
	* This works the same for push and pull, it just requires the correct data
	*
	* @param string $wordpress_id_field_name
	*   How to identify the ID field for the WordPress object
	* @param int $wordpress_id
	*   ID for the WordPress object
	* @param string $salesforce_member_level
	*   The member level value from Salesforce
	*
	*/
	private function set_member_level( $wordpress_id_field_name, $wordpress_id, $salesforce_member_level ) {
		$user = get_user_by( $wordpress_id_field_name, $wordpress_id );
		if ( false !== $user ) {

			$nonmember_level_name = get_option( 'salesforce_api_nonmember_level_name', 'Non-member' );

			if ( $salesforce_member_level !== $nonmember_level_name ) {
				$level_from_salesforce = 'member_' . strtolower( substr( $salesforce_member_level, 9 ) );
			} else {
				$level_from_salesforce = $salesforce_member_level;
			}

			$wp_roles = new WP_Roles(); // get all the available roles in WordPress
			$wp_roles = $wp_roles->get_names(); // just get the names

			$this_user_roles = $user->roles; // this is roles for this user

			// check all the user's current roles
			if ( ! empty( $this_user_roles ) ) {
				foreach ( $this_user_roles as $key => $value ) {

					$level_from_wordpress = $value;

					// if the user's role didn't change, get out of this function
					if ( false !== strpos( $value, 'member_' ) && $level_from_wordpress === $level_from_salesforce ) {
						return;
					}

					// this user was a member but now they're not. remove the level and get out of this function.
					if ( false !== strpos( $value, 'member_' ) && $level_from_salesforce === $nonmember_level_name ) {
						// this user is no longer a member, so get rid of the level
						$user->remove_role( $value );
						return;
					}

					// if the user has a new member level, get rid of the old one
					if ( false !== strpos( $value, 'member_' ) && $level_from_wordpress !== $level_from_salesforce ) {
						$user->remove_role( $value );
					}
				}
			}

			// if the salesforce level is a role, add it to the user
			if ( array_key_exists( $level_from_salesforce, $wp_roles ) ) {
				$user->add_role( $level_from_salesforce );
			}

			// if a user has no roles, give them the default WordPress role
			// this is helpful for legacy accounts that could lapse in their membership and then be left with no user role, which could be problematic.
			if ( empty( $this_user_roles ) ) {
				$default_role = get_option( 'default_role' );
				$user->add_role( $default_role );
			}

			// add the value from the Salesforce API to the user's meta for member level
			if ( $salesforce_member_level !== $nonmember_level_name ) {
				update_user_meta( $user->ID, 'member_level', $salesforce_member_level );
			} else {
				update_user_meta( $user->ID, 'member_level', $nonmember_level_name );
			}
		} // End if().
	}

	/**
	* Add roles and capabilities
	* This adds the member roles
	*
	*/
	public function add_roles_capabilities() {
		$bronze   = add_role( 'member_bronze', 'Member - Bronze', array() );
		$silver   = add_role( 'member_silver', 'Member - Silver', array() );
		$gold     = add_role( 'member_gold', 'Member - Gold', array() );
		$platinum = add_role( 'member_platinum', 'Member - Platinum', array() );
	}

	/**
	* Remove roles and capabilities
	* This removes the member roles
	*
	*/
	public function remove_roles_capabilities() {
		remove_role( 'member_bronze' );
		remove_role( 'member_silver' );
		remove_role( 'member_gold' );
		remove_role( 'member_platinum' );
	}

	/**
	* Default display for <input> fields
	*
	* @param array $args
	*/
	public function display_input_field( $args ) {
		$type    = $args['type'];
		$id      = $args['label_for'];
		$name    = $args['name'];
		$desc    = $args['desc'];
		$checked = '';

		$class = 'regular-text';

		if ( 'checkbox' === $type ) {
			$class = 'checkbox';
		}

		if ( ! isset( $args['constant'] ) || ! defined( $args['constant'] ) ) {
			$value = esc_attr( get_option( $id, '' ) );
			if ( 'checkbox' === $type ) {
				if ( '1' === $value ) {
					$checked = 'checked ';
				}
				$value = 1;
			}
			if ( '' === $value && isset( $args['default'] ) && '' !== $args['default'] ) {
				$value = $args['default'];
			}

			echo sprintf(
				'<input type="%1$s" value="%2$s" name="%3$s" id="%4$s" class="%5$s"%6$s>',
				esc_attr( $type ),
				esc_attr( $value ),
				esc_attr( $name ),
				esc_attr( $id ),
				sanitize_html_class( $class . esc_html( ' code' ) ),
				esc_html( $checked )
			);
			if ( '' !== $desc ) {
				echo sprintf(
					'<p class="description">%1$s</p>',
					esc_html( $desc )
				);
			}
		} else {
			echo sprintf(
				'<p><code>%1$s</code></p>',
				esc_html__( 'Defined in wp-config.php', 'object-sync-for-salesforce' )
			);
		}
	}

	/**
	* Display for multiple checkboxes
	* Above method can handle a single checkbox as it is
	*
	* @param array $args
	*/
	public function display_checkboxes( $args ) {
		$type    = 'checkbox';
		$name    = $args['name'];
		$options = get_option( $name, array() );
		foreach ( $args['items'] as $key => $value ) {
			$text    = $value['text'];
			$id      = $value['id'];
			$desc    = $value['desc'];
			$checked = '';
			if ( is_array( $options ) && in_array( $key, $options, true ) ) {
				$checked = 'checked';
			} elseif ( is_array( $options ) && empty( $options ) ) {
				if ( isset( $value['default'] ) && true === $value['default'] ) {
					$checked = 'checked';
				}
			}
			echo sprintf(
				'<div class="checkbox"><label><input type="%1$s" value="%2$s" name="%3$s[]" id="%4$s"%5$s>%6$s</label></div>',
				esc_attr( $type ),
				esc_attr( $key ),
				esc_attr( $name ),
				esc_attr( $id ),
				esc_html( $checked ),
				esc_html( $text )
			);
			if ( '' !== $desc ) {
				echo sprintf(
					'<p class="description">%1$s</p>',
					esc_html( $desc )
				);
			}
		}
	}
}
// Instantiate our class
$minnpost_salesforce = new Minnpost_Salesforce();
