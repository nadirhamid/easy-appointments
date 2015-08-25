<?php

require_once EA_SRC_DIR . 'dbmodels.php';
require_once EA_SRC_DIR . 'logic.php';

/**
 * Ajax communication
 */
class EAAjax
{
	
	/**
	 * DB utils
	 *
	 * @var EADBModel
	 **/
	var $models;

	/**
	 * Type of data request
	 *
	 * @var type
	 **/
	var $type;

	function __construct()
	{
		// Frontend ajax calls
		add_action( 'wp_ajax_nopriv_next_step', array($this, 'ajax_front_end') );
		add_action( 'wp_ajax_next_step', array($this, 'ajax_front_end') );

		add_action( 'wp_ajax_nopriv_date_selected', array($this, 'ajax_date_selected') );
		add_action( 'wp_ajax_date_selected', array($this, 'ajax_date_selected') );

		add_action( 'wp_ajax_res_appointment', array($this, 'ajax_res_appointment') );
		add_action( 'wp_ajax_nopriv_res_appointment', array($this, 'ajax_res_appointment') );

		add_action( 'wp_ajax_final_appointment', array($this, 'ajax_final_appointment') );
		add_action( 'wp_ajax_nopriv_final_appointment', array($this, 'ajax_final_appointment') );

		add_action( 'wp_ajax_cancel_appointment', array($this, 'ajax_cancel_appointment') );
		add_action( 'wp_ajax_nopriv_cancel_appointment', array($this, 'ajax_cancel_appointment') );
		// end frontend

		// admin ajax section
		if(is_admin()) {
			// Appointments
			add_action( 'wp_ajax_appointments', array($this, 'ajax_appointments') );

			// Appointment
			add_action( 'wp_ajax_appointment', array($this, 'ajax_appointment') );

			// Services
			add_action( 'wp_ajax_services', array($this, 'ajax_services'));

			// Service
			add_action( 'wp_ajax_service', array($this, 'ajax_service'));

			// Locations
			add_action( 'wp_ajax_locations', array($this, 'ajax_locations'));

			// Location
			add_action( 'wp_ajax_location', array($this, 'ajax_location'));

			// Worker
			add_action( 'wp_ajax_worker', array($this, 'ajax_worker'));

			// Workers
			add_action( 'wp_ajax_workers', array($this, 'ajax_workers'));

			// Connection
			add_action( 'wp_ajax_connection', array($this, 'ajax_connection'));

			// Connections
			add_action( 'wp_ajax_connections', array($this, 'ajax_connections'));

			// Open times
			add_action( 'wp_ajax_open_times', array($this, 'ajax_open_times'));

			// Setting
			add_action( 'wp_ajax_setting', array($this, 'ajax_setting'));

			// Settings
			add_action( 'wp_ajax_settings', array($this, 'ajax_settings'));

			// Settings
			add_action( 'wp_ajax_report', array($this, 'ajax_report'));

		}

		$this->models = new EADBModels;
	}

	public function ajax_front_end()
	{
		unset($_GET['action']);

		$result = $this->models->get_next( $_GET );

		$this->send_ok_json_result( $result );
	}

	public function ajax_date_selected()
	{
		unset($_GET['action']);

		$logic = new EALogic();

		$slots = $logic->get_open_slots($_GET['location'], $_GET['service'], $_GET['worker'], $_GET['date']);

		$this->send_ok_json_result( $slots );
	}

	public function ajax_res_appointment()
	{
		$table = 'ea_appointments';
		
		$data = $_GET;

		$logic = new EALogic();

		$open_slots = $logic->get_open_slots($data['location'], $data['service'], $data['worker'], $data['date']);

		$is_free = false;

		foreach ($open_slots as $value) {
			if($value['value'] == $data['start']) {
				$is_free = true;
				break;
			}
		}

		if(!$is_free) {
			$this->send_err_json_result('{"err":true, "message":"Slots are full"}');
		}

		unset($data['action']);

		$data['status'] = 'reservation';
		$service = $this->models->get_row('ea_services', $data['service']);

		$data['price'] = $service->price;
		$end_time = strtotime("{$data['start']} + {$service->duration} minute");

		$data['end'] = date('H:i', $end_time);

		$data['ip'] = $_SERVER['REMOTE_ADDR'];

		$data['session'] = session_id();

		if(!$logic->can_make_reservation($data['ip'])) {
			$resp = array(
				'err' => true,
				'message' => __('Daily limit of booking request has been reached. Please contact us by email!', 'easy-appointments')
			);
			$this->send_err_json_result(json_encode($resp));
		}

		$response = $this->models->replace( $table, $data, true );

		if($response == false) {
			$resp = array(
				'err' => true,
				'message' => __("Something went wrong! Please try again.", 'easy-appointments')
			);
			$this->send_err_json_result(json_encode($resp));
		}

		$this->send_ok_json_result($response);
	}

	public function ajax_final_appointment()
	{
		$logic = new EALogic();

		$table = 'ea_appointments';

		$data = $_GET;

		unset($data['action']);

		$data['status'] = 'pending';

		$appointment = $this->models->get_row('ea_appointments', $data['id'], ARRAY_A);

		// Merge data
		foreach ($appointment as $key => $value) {
			if(!array_key_exists($key, $data)) {
				$data[$key] = $value;
			}
		}

		$response = $this->models->replace( $table, $data, true );

		if($response == false) {
			$this->send_err_json_result('{"err":true}');
		} else {
			EALogic::send_notification($data);

			$send_user_mail = $logic->get_option_value('send.user.email', false);

			if(!empty($send_user_mail)) {
				EALogic::send_status_change_mail($data['id']);
			}
		}

		$response = new stdClass();
		$response->message = 'Ok';
		$this->send_ok_json_result($response);
	}

	public function ajax_cancel_appointment()
	{
		$table = 'ea_appointments';

		$data = $_GET;
		
		unset($data['action']);

		$data['status'] = 'abandoned';

		$appointment = $this->models->get_row('ea_appointments', $data['id'], ARRAY_A);

		// Merge data
		foreach ($appointment as $key => $value) {
			if(!array_key_exists($key, $data)) {
				$data[$key] = $value;
			}
		}

		$response = $this->models->replace( $table, $data, true );
		
		if($response == false) {
			$this->send_err_json_result('{"err":true}');
		}

		$response = new stdClass;
		$response->data = true;

		$this->send_ok_json_result($response);
	}

	public function ajax_setting()
	{
		$this->parse_single_model('ea_options');
	}

	public function ajax_settings()
	{
		$data = $this->parse_input_data();

		$response = array();

		if($this->type === 'GET') {
			$response = $this->models->get_all_rows('ea_options');
		} else {
			foreach ($data as $option) {
				// update single option
				$response[] = $this->models->replace('ea_options', $option);
			}
		}

		$this->send_ok_json_result($response);
	}

	/**
	 * Update all settings ajax call
	 * @return [type] [description]
	 */
	public function ajax_settings_upd()
	{
		$this->parse_input_data();

		$response = array();

		if($this->type === 'GET') {
			$response = $this->models->get_all_rows('ea_options');
		}

		$this->send_ok_json_result($response);
	}

	public function ajax_open_times()
	{
		$data = $this->parse_input_data();

		$logic = new EALogic();

		if(!array_key_exists('app_id', $data)) {
			$data['app_id'] = null;
		}

		$slots = $logic->get_open_slots($data['location'], $data['service'], $data['worker'], $data['date'], $data['app_id']);

		die(json_encode($slots));
	}

	public function ajax_appointments()
	{
		$data = $this->parse_input_data();

		$response = array();

		if($this->type === 'GET') {
			$response = $this->models->get_all_rows('ea_appointments', $data);
		}

		die(json_encode($response));
	}

	public function ajax_appointment()
	{
		$response = $this->parse_single_model('ea_appointments', false);
		
		if($response == false) {
			$this->send_err_json_result('err');	
		}

		if($this->type != 'NEW' && $this->type != 'UPDATE') {
			$this->send_ok_json_result($response);
		}

		if(isset($this->data['_mail'])) {
			EALogic::send_status_change_mail($response->id);
		}

		$this->send_ok_json_result($response);
	}

	/**
	 * Service model
	 */
	public function ajax_service()
	{
		$this->parse_single_model('ea_services');
	}

	/**
	 * Services collection
	 */
	public function ajax_services()
	{
		$this->parse_input_data();

		$response = array();

		if($this->type === 'GET') {
			$response = $this->models->get_all_rows('ea_services');
		}

		die(json_encode($response));
	}

	/**
	 * Locations collection
	 */
	public function ajax_locations()
	{
		$this->parse_input_data();

		$response = array();

		if($this->type === 'GET') {
			$response = $this->models->get_all_rows('ea_locations');
		}

		header( "Content-Type: application/json" );

		die(json_encode($response));
	}

	/**
	 * Single location
	 */
	public function ajax_location()
	{
		$this->parse_single_model('ea_locations');
	}

	/**
	 * Workers collection
	 */
	public function ajax_workers()
	{
		$this->parse_input_data();

		$response = array();

		if($this->type === 'GET') {
			$response = $this->models->get_all_rows('ea_staff');
		}

		header( "Content-Type: application/json" );

		die(json_encode($response));
	}

	/**
	 * Single worker
	 */
	public function ajax_worker()
	{
		$this->parse_single_model('ea_staff');
	}

	/**
	 * Workers collection
	 */
	public function ajax_connections()
	{
		$this->parse_input_data();

		$response = array();

		if($this->type === 'GET') {
			$response = $this->models->get_all_rows('ea_connections');
		}

		header( "Content-Type: application/json" );

		die(json_encode($response));
	}

	/**
	 * Single connection
	 */
	public function ajax_connection()
	{
		$this->parse_single_model('ea_connections');
	}

	/**
	 * REST enter point
	 */
	private function parse_input_data()
	{
		$method = $_SERVER['REQUEST_METHOD'];

		switch ($method) {
			case 'POST':
				$data = json_decode( file_get_contents( "php://input" ), true );
				$this->type = 'NEW';
				break;

			case 'PUT':
				$data = json_decode( file_get_contents( "php://input" ), true );
				$this->type = 'UPDATE';
				break;

			case 'GET':
				$data = $_REQUEST;
				$this->type = 'GET';
				break;

			case 'DELETE':
				$data = $_REQUEST;
				$this->type = 'DELETE';
				break;
		}
		
		return $data;
	}

	/**
	 * Ajax call for report data
	 */
	public function ajax_report() {
		$data = $this->parse_input_data();

		$type = $data['report'];

		$rep = new EAReport();
		$response = $rep->get($type, $data);

		$this->send_ok_json_result($response);
	}

	/**
	 * 
	 */
	private function parse_single_model($table, $end = true)
	{
		$data = $this->parse_input_data();

		if(!$end) {
			$this->data = $data;
		}

		$response = array();

		switch ($this->type) {
			case 'GET':
				$id = (int)$_GET['id'];
				$response = $this->models->get_row( $table, $id );
				break;
			case 'UPDATE':
			case 'NEW':
				$response = $this->models->replace( $table, $data, true );
				break;
			case 'DELETE':
				$data = $_GET;
				$response = $this->models->delete( $table, $data, true );
				break;
		}
		
		if($response == false) {
			$this->send_err_json_result('{"err":true}');
		}

		if($end) {
			$this->send_ok_json_result($response);
		} else {
			return $response;
		}
	}

	private function send_ok_json_result($result)
	{
		header( "Content-Type: application/json" );

		die(json_encode($result));
	}

	private function send_err_json_result($message)
	{
		header('HTTP/1.1 500 Internal Server Error');
		die($message);
	}
}