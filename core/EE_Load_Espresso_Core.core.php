<?php
if ( ! defined( 'EVENT_ESPRESSO_VERSION' ) ) {
	exit( 'No direct script access allowed' );
}

/**
 *
 * EE_Load_Espresso_Core
 *
 * This is the core application loader class at the center of the EE Middleware Request Stack.
 * Although not an instance of EE_Middleware, it DOES implement the EEI_Request_Decorator, allowing it to communicate
 * with the other EE_Middleware classes.
 * Performs all of the basic class loading that used to be in the EE_System constructor.
 *
 * @package		Event Espresso
 * @subpackage	core/
 * @author		Brent Christensen, Michael Nelson
 *
 * ------------------------------------------------------------------------
 */
class EE_Load_Espresso_Core implements EEI_Request_Decorator, EEI_Request_Stack_Core_App {


	/**
	 * @access private
	 * @type   EE_Load_Espresso_Core $_instance
	 */
	private static $_instance;

	/**
	 * @access protected
	 * @type   \EE_Activation_Manager $activationManager
	 */
	protected $activationManager;

	/**
	 * EspressoCore object for the current site
	 *
	 * @access protected
	 * @type   EspressoCore $espressoCore
	 */
	protected $espressoCore;

	/**
	 * array of cached EspressoCore objects indexed by blog id
	 *
	 * @access protected
	 * @type   EspressoCore[] $espressoCoreCache
	 */
	protected $espressoCoreCache;



	/**
	 * @singleton method used to instantiate class object
	 * @access    public
	 * @param \EE_Activation_Manager $activationManager
	 * @return \EE_Load_Espresso_Core
	 * @throws \EE_Error
	 */
	public static function instance( \EE_Activation_Manager $activationManager = null ) {
		// check if class object is instantiated
		if ( ! self::$_instance instanceof EE_Load_Espresso_Core ) {
			if ( ! $activationManager instanceof \EE_Activation_Manager ) {
				throw new EE_Error(
					__( 'A valid instance of the EE_Activation_Manager class is required to instantiate EE_Load_Espresso_Core.', 'event_espresso' )
				);
			}
			self::$_instance = new self( $activationManager );
		}
		return self::$_instance;
	}



	/**
	 * @access    private
	 * @param \EE_Activation_Manager $activationManager
	 * @throws \EE_Error
	 */
	private function __construct( \EE_Activation_Manager $activationManager ) {
		$this->activationManager = $activationManager;
		// PSR4 Autoloaders
		espresso_load_required( 'EE_Psr4AutoloaderInit', EE_CORE . 'EE_Psr4AutoloaderInit.core.php' );
		new EE_Psr4AutoloaderInit();
		// deprecated functions
		espresso_load_required( 'EE_Deprecated', EE_CORE . 'EE_Deprecated.core.php' );
		// load interfaces
		espresso_load_required(
			'EEI_Payment_Method_Interfaces',
			EE_LIBRARIES . 'payment_methods' . DS . 'EEI_Payment_Method_Interfaces.php'
		);
		// workarounds for PHP < 5.3
		espresso_load_required( 'EEH_Class_Tools', EE_HELPERS . 'EEH_Class_Tools.helper.php' );
		// manages activations, upgrades, and migrations
		espresso_load_required( 'EE_Activation_Manager', EE_CORE . 'EE_Activation_Manager.core.php' );
		// now setup the first core
		$this->getEspressoCore();
		$this->activationManager->setEspressoCore( $this->espressoCore );
		// central repository for classes
		espresso_load_required( 'EE_Registry', EE_CORE . 'EE_Registry.core.php' );
		// updates the database to the current version
		espresso_load_required( 'EE_Data_Migration_Manager', EE_CORE . 'EE_Data_Migration_Manager.core.php' );
		// allow addons to load first so that they can register autoloaders, set hooks for running DMS's, etc
		add_action( 'AHEE__EE_Bootstrap__load_espresso_addons', array( $this, 'loadEspressoAddons' ) );
		// when an ee addon is activated, we want to call the core hook(s) again
		// because the newly-activated addon didn't get a chance to run at all
		add_action( 'activate_plugin', array( $this, 'loadEspressoAddons' ), 10 );
	}



	/**
	 *    handle
	 *    sets hooks for running rest of system
	 *    provides "AHEE__EE_System__construct__complete" hook for EE Addons to use as their starting point
	 *    starting EE Addons from any other point may lead to problems
	 *
	 * @access 	public
	 * @param 	EE_Request 	$request
	 * @param 	EE_Response $response
	 * @return 	EE_Response
	 */
	public function handle_request( EE_Request $request, EE_Response $response ) {
		$this->initializeEspressoCore( $request, $response );
		return $this->espressoCore->response();
	}



	/**
	 * @param    EE_Request  $request
	 * @param    EE_Response $response
	 * @return void
	 */
	protected function initializeEspressoCore( EE_Request $request, EE_Response $response ) {
		$this->espressoCore->set_request( $request );
		$this->espressoCore->set_response( $response );
		// EE_Registry
		$this->espressoCore->set_registry( EE_Registry::instance() );
		$this->espressoCore->registry()->set_request( $this->espressoCore->request() );
		$this->espressoCore->registry()->set_response( $this->espressoCore->response() );
		// WP cron jobs
		$this->espressoCore->registry()->load_core( 'Cron_Tasks' );
		$this->espressoCore->registry()->load_core( 'Request_Handler' );
		// load EE_System
		$this->espressoCore->set_system(
			$this->espressoCore->registry()->load_core( 'EE_System' )
		);
		$this->espressoCore->system()->set_registry( $this->espressoCore->registry() );
		$this->espressoCore->system()->set_request( $this->espressoCore->request() );
		$this->espressoCore->system()->set_response( $this->espressoCore->response() );
		// pass core to Activation Manager
		$this->activationManager->setEspressoCore( $this->espressoCore );
	}



	/**
	 * @param  int $blog_id
	 * @return \EspressoCore
	 */
	protected function getEspressoCore( $blog_id = 0 ) {
		$blog_id = ! empty( $blog_id ) ? $blog_id : get_current_blog_id();
		if (
			! isset( $this->espressoCoreCache[ $blog_id ] )
			|| ! $this->espressoCoreCache[ $blog_id ] instanceof \EspressoCore
		) {
			$this->espressoCoreCache[ $blog_id ] = new \EspressoCore();
		}
		$this->espressoCore = $this->espressoCoreCache[ $blog_id ];
		return $this->espressoCore;
	}



	/**
	 * unsetEspressoCore
	 * unsets an Espresso Core object from the cache, which presumably releases it's objects
	 *
	 * @param  int $blog_id
	 * @return bool
	 */
	protected function unsetEspressoCore( $blog_id = 0 ) {
		$blog_id = ! empty( $blog_id ) ? $blog_id : 0;
		if ( ! $blog_id ) {
			return false;
		}
		unset( $this->espressoCoreCache[ $blog_id ] );
		return true;
	}



	/**
	 * loadEspressoAddons
	 *
	 * allow addons to load first so that they can set hooks for running DMS's, etc
	 * this is hooked into both:
	 *    'AHEE__EE_Bootstrap__load_core_configuration'
	 *        which runs during the WP 'plugins_loaded' action at priority 5
	 *    and the WP 'activate_plugin' hookpoint
	 *
	 * @access public
	 * @return void
	 */
	public function loadEspressoAddons() {
		// set autoloaders for all of the classes implementing EEI_Plugin_API
		// which provide helpers for EE plugin authors to more easily register certain components with EE.
		EEH_Autoloader::instance()->register_autoloaders_for_each_file_in_folder( EE_LIBRARIES . 'plugin_api' );
		//load and setup EE_Capabilities
		$this->espressoCore->registry()->load_core( 'Capabilities' );
		//caps need to be initialized on every request so that capability maps are set.
		//@see https://events.codebasehq.com/projects/event-espresso/tickets/8674
		$this->espressoCore->registry()->CAP->init_caps();
		do_action( 'AHEE__EE_System__load_espresso_addons' );
	}



	/**
	 * handle_response
	 * called after the request stack has been fully processed
	 * nothing happening here at this moment...
	 *
	 * @access    public
	 * @param \EE_Request $request
	 * @param \EE_Response $response
	 */
	public function handle_response( EE_Request $request, EE_Response $response ) {
		//EEH_Debug_Tools::printr( $request, '$request', __FILE__, __LINE__ );
		//EEH_Debug_Tools::printr( $response, '$response', __FILE__, __LINE__ );
		//die();
	}



	/**
	 * Similar to wp's switch_to_blog(), but also reset
	 * a few EE singletons that need to be
	 * reset too
	 *
	 * @param int $blog_id
	 * @throws \EE_Error
	 */
	public static function switchToBlog( $blog_id = 0 ) {
		\EE_Load_Espresso_Core::instance()->getEspressoCore( $blog_id );
		\EE_Load_Espresso_Core::instance()->initializeEspressoCore(
			EE_Bootstrap::get_request(),
			EE_Bootstrap::get_response()
		);
	}



	/**
	 * @param int $blog_id
	 * @return \EE_Registry
	 * @throws \EE_Error
	 */
	public static function getRegistryForBlog( $blog_id = 0 ) {
		\EE_Load_Espresso_Core::instance()->getEspressoCore( $blog_id );
		return \EE_Load_Espresso_Core::instance()->espressoCore->registry();
	}



	/**
	 * @param int          $blog_id
	 * @param \EE_Registry $registry
	 * @throws \EE_Error
	 */
	public static function setRegistryForBlog( \EE_Registry $registry, $blog_id = 0 ) {
		\EE_Load_Espresso_Core::instance()->getEspressoCore( $blog_id );
		\EE_Load_Espresso_Core::instance()->espressoCore->set_registry( $registry );
	}



}
// End of file EE_Load_Espresso_Core.core.php
// Location: /core/EE_Load_Espresso_Core.core.php
