<?php

if (!defined('EVENT_ESPRESSO_VERSION') )
	exit('NO direct script access allowed');

/**
 * Event Espresso
 *
 * Event Registration and Management Plugin for WordPress
 *
 * @ package			Event Espresso
 * @ author				Seth Shoultes
 * @ copyright		(c) 2008-2011 Event Espresso  All Rights Reserved.
 * @ license			http://eventespresso.com/support/terms-conditions/   * see Plugin Licensing *
 * @ link				http://www.eventespresso.com
 * @ version		 	4.0
 *
 * ------------------------------------------------------------------------
 *
 * EE_Ticket_List_Shortcodes
 * 
 * this is a child class for the EE_Shortcodes library.  The EE_Ticket_List_Shortcodes lists all shortcodes related to Ticket Lists. 
 *
 * This is a special shortcode parser in that it will actually LOAD other parsers and receive a template to parse via the Shortcode Parser.
 *
 * NOTE: if a method doesn't have any phpdoc commenting the details can be found in the comments in EE_Shortcodes parent class.
 * 
 * @package		Event Espresso
 * @subpackage	libraries/shortcodes/EE_Ticket_List_Shortcodes.lib.php
 * @author		Darren Ethier
 *
 * ------------------------------------------------------------------------
 */
class EE_Ticket_List_Shortcodes extends EE_Shortcodes {

	public function __construct() {
		parent::__construct();
	}


	protected function _init_props() {
		$this->label = __('Ticket List Shortcodes', 'event_espresso');
		$this->description = __('All shortcodes specific to ticket lists', 'event_espresso');
		$this->_shortcodes = array(
			'[TICKET_LIST]' => __('Will output a list of tickets', 'event_espresso'),
			'[RECIPIENT_TICKET_LIST]' => __('Will output a list of tickets for the recipient of the email. Note, if the recipient is the Event Author, then this is blank.', 'event_espresso'),
			'[PRIMARY_REGISTRANT_TICKET_LIST]' => __('Will output a list of tickets that the primary registration receieved.', 'event_espresso')
			);
	}



	protected function _parser( $shortcode ) {
		switch ( $shortcode ) {
			case '[TICKET_LIST]' :
				return $this->_get_ticket_list();
				break;

			case '[RECIPIENT_TICKET_LIST]' :
				return $this->_get_recipient_ticket_list();
				break;

			case '[PRIMARY_REGISTRANT_TICKET_LIST]' :
				return $this->_get_recipient_ticket_list( TRUE );
				break;
		}
		return '';
	}



	/**
	 * figure out what the incoming data is and then return the appropriate parsed value.
	 * @return string
	 */
	private function _get_ticket_list() {
		$this->_validate_list_requirements();
		$this->_set_shortcode_helper();


		if ( $this->_data['data'] instanceof EE_Messages_Addressee )
			return $this->_get_ticket_list_for_main();

		else if ( $this->_data['data'] instanceof EE_Attendee )
			return $this->_get_ticket_list_for_attendee();

		else if ( $this->_data['data'] instanceof EE_Event )
			return $this->_get_ticket_list_for_event();

		//prevent recursive loop
		else
			return '';
	}



	/**
	 * figure out what the incoming data is and then return the appropriate parsed value
	 *
	 * @param  boolean $primary whether we're getting the primary registrant ticket_list.
	 * @return string
	 */
	private function _get_recipient_ticket_list( $primary = FALSE ) {
		$this->_validate_list_requirements();
		$this->_set_shortcode_helper();

		if ( $this->_data['data'] instanceof EE_Messages_Addressee )
			return $this->_get_recipient_ticket_list_parsed( $this->_data['data'], $primary );

		else if ( $this->_extra_data['data'] instanceof EE_Messages_Addressee )
			return $this->_get_recipient_ticket_list_parsed( $this->_extra_data['data'], $primary );

		else
			return '';
	}


	private function _get_recipient_ticket_list_parsed( EE_Messages_Addressee $data, $primary = FALSE ) {
		//setup valid shortcodes depending on what the status of the $this->_data property is
		if ( $this->_data['data'] instanceof EE_Messages_Addressee ) {
			$attendee = $primary ? $data->primary_att_obj : $data->att_obj;
			if ( ! $attendee instanceof EE_Attendee ) return '';
			$valid_shortcodes = array('ticket', 'event_list', 'attendee_list','datetime_list', 'registration', 'attendee');
			$template = $this->_data['template'];
			$tkts = $data->attendees[$attendee->ID()]['tkt_objs'];
			$data = $this->_data;
		} elseif ( $this->_data['data'] instanceof EE_Event ) {
			$attendee = $primary ? $data->primary_att_obj : $data->att_obj;
			if ( ! $attendee instanceof EE_Attendee ) return '';
			$valid_shortcodes = array('ticket', 'attendee_list', 'datetime_list', 'attendee');
			$template = is_array($this->_data['template'] ) && isset($this->_data['template']['ticket_list']) ? $this->_data['template']['ticket_list'] : $this->_extra_data['template']['ticket_list'];
			//let's remove any existing [EVENT_LIST] shortcode from the ticket list template so that we don't get recursion.
			$template = str_replace('[EVENT_LIST]', '', $template);
			//data will be tickets for this event for this recipient.
			$tkts = $this->_get_tickets_from_event( $this->_data['data'], $attendee );
			$data = $this->_extra_data;
		} elseif ( $this->_data['data'] instanceof EE_Attendee ) {
			$attendee = $primary ? $data->primary_att_obj : $data->att_obj;
			if ( ! $attendee instanceof EE_Attendee ) return '';
			$valid_shortcodes = array('ticket', 'event_list', 'datetime_list', 'attendee');
			$template = is_array($this->_data['template']) && isset($this->_data['template']['ticket_list']) ? $this->_data['template']['ticket_list'] : $this->_extra_data['template']['ticket_list'];
			//let's remove any existing [ATTENDEE_LIST] shortcode from the ticket list template so that we don't get recursion.
			$template = str_replace('[ATTENDEE_LIST]', '', $template);
			$tkts = $this->_get_tickets_from_attendee( $this->_data['data'], $attendee );
			$data = $this->_extra_data;
		}

		$tktparsed = '';
		foreach ( $tkts as $ticket ) {
			$tkt_parsed .= $this->_shortcode_helper->parse_ticket_list_template( $template, $ticket, $valid_shortcodes, $data );
		}
		return $tktparsed;
	}


	/**
	 * This returns the parsed ticket list for main template;
	 */
	private function _get_ticket_list_for_main() {
		$valid_shortcodes = array('ticket', 'event_list', 'attendee_list','datetime_list', 'registration', 'attendee');
		$template = $this->_data['template'];
		$data = $this->_data['data'];
		$tktparsed = '';


		//now we need to loop through the ticket list and send data to the EE_Parser helper.
		foreach ( $data->tickets as $ticket ) {
			$tktparsed .= $this->_shortcode_helper->parse_ticket_list_template($template, $ticket['ticket'], $valid_shortcodes, $this->_data);
		}

		return $tktparsed;

	}


	/**
	 * return parsed list of tickets for an event
	 * @return string
	 */
	private function _get_ticket_list_for_event() {
		$valid_shortcodes = array('ticket', 'attendee_list', 'datetime_list', 'attendee');
		$template = is_array($this->_data['template'] ) && isset($this->_data['template']['ticket_list']) ? $this->_data['template']['ticket_list'] : $this->_extra_data['template']['ticket_list'];
		$event = $this->_data['data'];

		//let's remove any existing [EVENT_LIST] shortcodes from the ticket list template so that we don't get recursion.
		$template = str_replace('[EVENT_LIST]', '', $template);

		//here we're setting up the tickets for the ticket list template for THIS event.
		$tkt_parsed = '';
		$tickets = $this->_get_tickets_from_event($event);

		//each ticket in this case should be an ticket object.
		foreach ( $tickets as $ticket ) {
			$tkt_parsed .= $this->_shortcode_helper->parse_ticket_list_template($template, $ticket, $valid_shortcodes, $this->_extra_data);
		}

		return $tkt_parsed;
	}



	/**
	 * return parsed list of tickets for an attendee
	 * @return string
	 */
	private function _get_ticket_list_for_attendee() {
		$valid_shortcodes = array('ticket', 'event_list', 'datetime_list', 'attendee');
		
		$template = is_array($this->_data['template']) && isset($this->_data['template']['ticket_list']) ? $this->_data['template']['ticket_list'] : $this->_extra_data['template']['ticket_list'];
		$attendee = $this->_data['data'];

		//let's remove any existing [ATTENDEE_LIST] shortcode from the ticket list template so that we don't get recursion.
		$template = str_replace('[ATTENDEE_LIST]', '', $template);

		//here we're setting up the tickets for the ticket list template for THIS attendee.
		$tkt_parsed = '';
		$tickets = $this->_get_tickets_from_attendee($attendee);

		//each ticket in this case should be an ticket object.
		foreach ( $tickets as $ticket ) {
			$tkt_parsed .= $this->_shortcode_helper->parse_ticket_list_template($template, $ticket, $valid_shortcodes, $this->_extra_data);
		}

		return $tkt_parsed;
	}




	private function _get_tickets_from_event( EE_Event $event, $att = NULL ) {
		$evt_tkts = isset($this->_extra_data['data']->events) ? $this->_extra_data['data']->events[$event->ID()]['tkt_objs'] : array(); 

		if ( $att instanceof EE_Attendee && $this->_extra_data['data'] instanceof EE_Messages_Addressee ) {
			$adj_tkts = array();
			//return only tickets for the given attendee
			foreach ( $evt_tkts as $tkt ) {
				if ( isset( $this->_extra_data['data']->attendees[$attendee->ID()]['tkt_objs'][$tkt->ID()] ) )
					$adj_tkts = $tkt;
			}
			$evt_tkts = $adj_tkts;
		}
		return $evt_tkts;
	}

	private function _get_tickets_from_attendee( EE_Attendee $attendee, $att = NULL ) {
		$att_tkts = isset($this->_extra_data['data']->attendees) ? $this->_extra_data['data']->attendees[$attendee->ID()]['tkt_objs'] : array();
		if ( $att instanceof EE_Attendee && $this->_extra_data['data'] instanceof EE_Messages_Addressee ) {
			$adj_tkts = array();
			//return only tickets for the given attendee
			foreach ( $att_tkts as $tkt ) {
				if ( isset( $this->_extra_data['data']->attendees[$attendee->ID()]['tkt_objs'][$tkt->ID()] ) )
					$adj_tkts = $tkt;
			}
			$att_tkts = $adj_tkts;
		}
		return $att_tkts;
	}


	
} // end EE_Ticket_List_Shortcodes class