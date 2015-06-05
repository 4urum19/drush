<?php

namespace Drush\Boot;

abstract class DrupalBoot extends BaseBoot {

  function __construct() {
  }

  function valid_root($path) {
  }

  function get_profile() {
  }

  /**
   * Bootstrap phases used with Drupal:
   *
   *     DRUSH_BOOTSTRAP_DRUSH                = Only Drush.
   *     DRUSH_BOOTSTRAP_DRUPAL_ROOT          = Find a valid Drupal root.
   *     DRUSH_BOOTSTRAP_DRUPAL_SITE          = Find a valid Drupal site.
   *     DRUSH_BOOTSTRAP_DRUPAL_CONFIGURATION = Load the site's settings.
   *     DRUSH_BOOTSTRAP_DRUPAL_DATABASE      = Initialize the database.
   *     DRUSH_BOOTSTRAP_DRUPAL_FULL          = Initialize Drupal fully.
   *     DRUSH_BOOTSTRAP_DRUPAL_LOGIN         = Log into Drupal with a valid user.
   *
   * The value is the name of the method of the Boot class to
   * execute when bootstrapping.  Prior to bootstrapping, a "validate"
   * method is called, if defined.  The validate method name is the
   * bootstrap method name with "_validate" appended.
   */
  function bootstrap_phases() {
    return array(
      DRUSH_BOOTSTRAP_DRUSH                  => 'bootstrap_drush',
      DRUSH_BOOTSTRAP_DRUPAL_ROOT            => 'bootstrap_drupal_root',
      DRUSH_BOOTSTRAP_DRUPAL_SITE            => 'bootstrap_drupal_site',
      DRUSH_BOOTSTRAP_DRUPAL_CONFIGURATION   => 'bootstrap_drupal_configuration',
      DRUSH_BOOTSTRAP_DRUPAL_DATABASE        => 'bootstrap_drupal_database',
      DRUSH_BOOTSTRAP_DRUPAL_FULL            => 'bootstrap_drupal_full',
      DRUSH_BOOTSTRAP_DRUPAL_LOGIN           => 'bootstrap_drupal_login');
  }

  /**
   * The phases where Drush will look to see if new commandfiles
   * have been defined.  For Drupal, we first do a preflight, and
   * if a command is not found at that point, we next attempt a full
   * bootstrap.  If a command is found after preflight, then we
   * bootstrap to the phase declared by the command.
   */
  function bootstrap_init_phases() {
    return array(DRUSH_BOOTSTRAP_DRUSH, DRUSH_BOOTSTRAP_DRUPAL_FULL);
  }

  function enforce_requirement(&$command) {
    parent::enforce_requirement($command);
    $this->drush_enforce_requirement_drupal_dependencies($command);
  }

  function report_command_error($command) {
    // If we reach this point, command doesn't fit requirements or we have not
    // found either a valid or matching command.

    // If no command was found check if it belongs to a disabled module.
    if (!$command) {
      $command = $this->drush_command_belongs_to_disabled_module();
    }
    parent::report_command_error($command);
  }

  function command_defaults() {
    return array(
      'drupal dependencies' => array(),
      'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
    );
  }

  /**
   * @return array of strings - paths to directories where contrib
   * modules can be found
   */
  abstract function contrib_modules_paths();

  /**
   * @return array of strings - paths to directories where contrib
   * themes can be found
   */
  abstract function contrib_themes_paths();

  function commandfile_searchpaths($phase, $phase_max = FALSE) {
    if (!$phase_max) {
      $phase_max = $phase;
    }

    $searchpath = array();
    switch ($phase) {
      case DRUSH_BOOTSTRAP_DRUPAL_ROOT:
        $drupal_root = drush_get_context('DRUSH_SELECTED_DRUPAL_ROOT');
        $searchpath[] = $drupal_root . '../drush';
        $searchpath[] = $drupal_root . '/drush';
        $searchpath[] = $drupal_root . '/sites/all/drush';

        // Add the drupalboot.drush.inc commandfile.
        // $searchpath[] = __DIR__;
        break;
      case DRUSH_BOOTSTRAP_DRUPAL_SITE:
        // If we are going to stop bootstrapping at the site, then
        // we will quickly add all commandfiles that we can find for
        // any extension associated with the site, whether it is enabled
        // or not.  If we are, however, going to continue on to bootstrap
        // all the way to DRUSH_BOOTSTRAP_DRUPAL_FULL, then we will
        // instead wait for that phase, which will more carefully add
        // only those Drush commandfiles that are associated with
        // enabled modules.
        if ($phase_max < DRUSH_BOOTSTRAP_DRUPAL_FULL) {
          $searchpath = array_merge($searchpath, $this->contrib_modules_paths());

          // Adding commandfiles located within /profiles. Try to limit to one profile for speed. Note
          // that Drupal allows enabling modules from a non-active profile so this logic is kinda dodgy.
          $cid = drush_cid_install_profile();
          if ($cached = drush_cache_get($cid)) {
            $profile = $cached->data;
            $searchpath[] = "profiles/$profile/modules";
            $searchpath[] = "profiles/$profile/themes"
          }
          else {
            // If install_profile is not available, scan all profiles.
            $searchpath[] = "profiles";
            $searchpath[] = "sites/all/profiles";
          }

          $searchpath = array_merge($searchpath, $this->contrib_themes_paths());
        }
        break;
      case DRUSH_BOOTSTRAP_DRUPAL_CONFIGURATION:
        // Nothing to do here anymore. Left for documentation.
        break;
      case DRUSH_BOOTSTRAP_DRUPAL_FULL:
        // Add enabled module paths, excluding the install profile. Since we are bootstrapped,
        // we can use the Drupal API.
        $ignored_modules = drush_get_option_list('ignored-modules', array());
        $cid = drush_cid_install_profile();
        if ($cached = drush_cache_get($cid)) {
          $ignored_modules[] = $cached->data;
        }
        foreach (array_diff(drush_module_list(), $ignored_modules) as $module) {
          $filepath = drupal_get_path('module', $module);
          if ($filepath && $filepath != '/') {
            $searchpath[] = $filepath;
          }
        }

        $searchpath[] = drupal_get_path('theme', drush_theme_get_admin());
        $searchpath[] = drupal_get_path('theme', drush_theme_get_default());
        break;
    }

    return $searchpath;
  }

  /**
   * Check if the given command belongs to a disabled module.
   *
   * @return array
   *   Array with a command-like bootstrap error or FALSE if Drupal was not
   *   bootstrapped fully or the command does not belong to a disabled module.
   */
  function drush_command_belongs_to_disabled_module() {
    if (drush_has_boostrapped(DRUSH_BOOTSTRAP_DRUPAL_FULL)) {
      _drush_find_commandfiles(DRUSH_BOOTSTRAP_DRUPAL_SITE, DRUSH_BOOTSTRAP_DRUPAL_CONFIGURATION);
      drush_get_commands(TRUE);
      $commands = drush_get_commands();
      $arguments = drush_get_arguments();
      $command_name = array_shift($arguments);
      if (isset($commands[$command_name])) {
        // We found it. Load its module name and set an error.
        if (is_array($commands[$command_name]['drupal dependencies']) && count($commands[$command_name]['drupal dependencies'])) {
          $modules = implode(', ', $commands[$command_name]['drupal dependencies']);
        }
        else {
          // The command does not define Drupal dependencies. Derive them.
          $command_files = commandfiles_cache()->get();
          $command_path = $commands[$command_name]['path'] . DIRECTORY_SEPARATOR . $commands[$command_name]['commandfile'] . '.drush.inc';
          $modules = array_search($command_path, $command_files);
        }
        return array(
          'bootstrap_errors' => array(
            'DRUSH_COMMAND_DEPENDENCY_ERROR' => dt('Command !command needs the following module(s) enabled to run: !dependencies.', array(
              '!command' => $command_name,
              '!dependencies' => $modules,
            )),
          ),
        );
      }
    }

    return FALSE;
  }

  /**
   * Check that a command has its declared dependencies available or have no
   * dependencies.
   *
   * @param $command
   *   Command to check. Any errors  will be added to the 'bootstrap_errors' element.
   *
   * @return
   *   TRUE if command is valid.
   */
  function drush_enforce_requirement_drupal_dependencies(&$command) {
    // If the command bootstrap is DRUSH_BOOTSTRAP_MAX, then we will
    // allow the requirements to pass if we have not successfully
    // bootstrapped Drupal.  The combination of DRUSH_BOOTSTRAP_MAX
    // and 'drupal dependencies' indicates that the drush command
    // will use the dependent modules only if they are available.
    if ($command['bootstrap'] == DRUSH_BOOTSTRAP_MAX) {
      // If we have not bootstrapped, then let the dependencies pass;
      // if we have bootstrapped, then enforce them.
      if (drush_get_context('DRUSH_BOOTSTRAP_PHASE') < DRUSH_BOOTSTRAP_DRUPAL_FULL) {
        return TRUE;
      }
    }
    // If there are no drupal dependencies, then do nothing
    if (!empty($command['drupal dependencies'])) {
      foreach ($command['drupal dependencies'] as $dependency) {
        drush_include_engine('drupal', 'environment');
        if(!drush_module_exists($dependency)) {
          $command['bootstrap_errors']['DRUSH_COMMAND_DEPENDENCY_ERROR'] = dt('Command !command needs the following modules installed/enabled to run: !dependencies.', array('!command' => $command['command'], '!dependencies' => implode(', ', $command['drupal dependencies'])));
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Validate the DRUSH_BOOTSTRAP_DRUPAL_ROOT phase.
   *
   * In this function, we will check if a valid Drupal directory is available.
   * We also determine the value that will be stored in the DRUSH_DRUPAL_ROOT
   * context and DRUPAL_ROOT constant if it is considered a valid option.
   */
  function bootstrap_drupal_root_validate() {
    $drupal_root = drush_get_context('DRUSH_SELECTED_DRUPAL_ROOT');

    if (empty($drupal_root)) {
      return drush_bootstrap_error('DRUSH_NO_DRUPAL_ROOT', dt("A Drupal installation directory could not be found"));
    }
    if (!$signature = drush_valid_root($drupal_root)) {
      return drush_bootstrap_error('DRUSH_INVALID_DRUPAL_ROOT', dt("The directory !drupal_root does not contain a valid Drupal installation", array('!drupal_root' => $drupal_root)));
    }

    $version = drush_drupal_version($drupal_root);
    $major_version = drush_drupal_major_version($drupal_root);
    if ($major_version <= 5) {
      return drush_set_error('DRUSH_DRUPAL_VERSION_UNSUPPORTED', dt('Drush !drush_version does not support Drupal !major_version.', array('!drush_version' => DRUSH_VERSION, '!major_version' => $major_version)));
    }

    drush_bootstrap_value('drupal_root', realpath($drupal_root));
    define('DRUSH_DRUPAL_SIGNATURE', $signature);

    return TRUE;
  }

  /**
   * Bootstrap Drush with a valid Drupal Directory.
   *
   * In this function, the pwd will be moved to the root
   * of the Drupal installation.
   *
   * The DRUSH_DRUPAL_ROOT context, DRUSH_DRUPAL_CORE context, DRUPAL_ROOT, and the
   * DRUSH_DRUPAL_CORE constants are populated from the value that we determined during
   * the validation phase.
   *
   * We also now load the drushrc.php for this specific Drupal site.
   * We can now include files from the Drupal Tree, and figure
   * out more context about the platform, such as the version of Drupal.
   */
  function bootstrap_drupal_root() {
    // Load the config options from Drupal's /drush and sites/all/drush directories.
    drush_load_config('drupal');

    $drupal_root = drush_set_context('DRUSH_DRUPAL_ROOT', drush_bootstrap_value('drupal_root'));
    chdir($drupal_root);
    $version = drush_drupal_version();
    $major_version = drush_drupal_major_version();

    $core = $this->bootstrap_drupal_core($drupal_root);

    // DRUSH_DRUPAL_CORE should point to the /core folder in Drupal 8+ or to DRUPAL_ROOT
    // in prior versions.
    drush_set_context('DRUSH_DRUPAL_CORE', $core);
    define('DRUSH_DRUPAL_CORE', $core);

    _drush_preflight_global_options();

    drush_log(dt("Initialized Drupal !version root directory at !drupal_root", array("!version" => $version, '!drupal_root' => $drupal_root)));
  }

  /**
   * VALIDATE the DRUSH_BOOTSTRAP_DRUPAL_SITE phase.
   *
   * In this function we determine the URL used for the command,
   * and check for a valid settings.php file.
   *
   * To do this, we need to set up the $_SERVER environment variable,
   * to allow us to use conf_path to determine what Drupal will load
   * as a configuration file.
   */
  function bootstrap_drupal_site_validate() {
    // Define the selected conf path as soon as we have identified that
    // we have selected a Drupal site.  Drush used to set this context
    // during the drush_bootstrap_drush phase.
    $drush_uri = _drush_bootstrap_selected_uri();
    drush_set_context('DRUSH_SELECTED_DRUPAL_SITE_CONF_PATH', drush_conf_path($drush_uri));

    $this->bootstrap_drupal_site_setup_server_global($drush_uri);
    return $this->bootstrap_drupal_site_validate_settings_present();
  }

  /**
   * Set up the $_SERVER globals so that Drupal will see the same values
   * that it does when serving pages via the web server.
   */
  function bootstrap_drupal_site_setup_server_global($drush_uri) {
    // Fake the necessary HTTP headers that Drupal needs:
    if ($drush_uri) {
      $drupal_base_url = parse_url($drush_uri);
      // If there's no url scheme set, add http:// and re-parse the url
      // so the host and path values are set accurately.
      if (!array_key_exists('scheme', $drupal_base_url)) {
        $drush_uri = 'http://' . $drush_uri;
        $drupal_base_url = parse_url($drush_uri);
      }
      // Fill in defaults.
      $drupal_base_url += array(
        'path' => NULL,
        'host' => NULL,
        'port' => NULL,
      );
      $_SERVER['HTTP_HOST'] = $drupal_base_url['host'];

      if ($drupal_base_url['scheme'] == 'https') {
        $_SERVER['HTTPS'] = 'on';
      }

      if ($drupal_base_url['port']) {
        $_SERVER['HTTP_HOST'] .= ':' . $drupal_base_url['port'];
      }
      $_SERVER['SERVER_PORT'] = $drupal_base_url['port'];

      if (array_key_exists('path', $drupal_base_url)) {
        $_SERVER['PHP_SELF'] = $drupal_base_url['path'] . '/index.php';
      }
      else {
        $_SERVER['PHP_SELF'] = '/index.php';
      }
    }
    else {
      $_SERVER['HTTP_HOST'] = 'default';
      $_SERVER['PHP_SELF'] = '/index.php';
    }

    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD']  = NULL;

    $_SERVER['SERVER_SOFTWARE'] = NULL;
    $_SERVER['HTTP_USER_AGENT'] = NULL;
    $_SERVER['SCRIPT_FILENAME'] = DRUPAL_ROOT . '/index.php';
  }

  /**
   * Validate that the Drupal site has all of the settings that it
   * needs to operated.
   */
  function bootstrap_drupal_site_validate_settings_present() {
    $site = drush_bootstrap_value('site', $_SERVER['HTTP_HOST']);

    $conf_path = drush_bootstrap_value('conf_path', \conf_path(TRUE, TRUE));
    $conf_file = "$conf_path/settings.php";
    if (!file_exists($conf_file)) {
      return drush_bootstrap_error('DRUPAL_SITE_SETTINGS_NOT_FOUND', dt("Could not find a Drupal settings.php file at !file.",
         array('!file' => $conf_file)));
    }

    return TRUE;
  }

  /**
   * Called by bootstrap_drupal_site to do the main work
   * of the drush drupal site bootstrap.
   */
  function bootstrap_do_drupal_site() {
    $drush_uri = drush_get_context('DRUSH_SELECTED_URI');
    drush_set_context('DRUSH_URI', $drush_uri);
    $site = drush_set_context('DRUSH_DRUPAL_SITE', drush_bootstrap_value('site'));
    $conf_path = drush_set_context('DRUSH_DRUPAL_SITE_ROOT', drush_bootstrap_value('conf_path'));

    drush_log(dt("Initialized Drupal site !site at !site_root", array('!site' => $site, '!site_root' => $conf_path)));

    _drush_preflight_global_options();
  }

  /**
   * Initialize a site on the Drupal root.
   *
   * We now set various contexts that we determined and confirmed to be valid.
   * Additionally we load an optional drushrc.php file in the site directory.
   */
  function bootstrap_drupal_site() {
    drush_load_config('site');
    $this->bootstrap_do_drupal_site();
  }

  /**
   * Initialize and load the Drupal configuration files.
   *
   * We process and store a normalized set of database credentials
   * from the loaded configuration file, so we can validate them
   * and access them easily in the future.
   *
   * Also override Drupal variables as per --variables option.
   */
  function bootstrap_drupal_configuration() {
    global $conf;

    $override = array(
      'dev_query' => FALSE, // Force Drupal6 not to store queries since we are not outputting them.
      'cron_safe_threshold' => 0, // Don't run poormanscron during Drush request (D7+).
    );

    $current_override = drush_get_option_list('variables');
    foreach ($current_override as $name => $value) {
      if (is_numeric($name) && (strpos($value, '=') !== FALSE)) {
        list($name, $value) = explode('=', $value, 2);
      }
      $override[$name] = $value;
    }
    $conf = is_array($conf) ? array_merge($conf, $override) : $conf;
  }

  /**
   * Validate the DRUSH_BOOTSTRAP_DRUPAL_DATABASE phase
   *
   * Attempt to make a working database connection using the
   * database credentials that were loaded during the previous
   * phase.
   */
  function bootstrap_drupal_database_validate() {
    if (!drush_valid_db_credentials()) {
      return drush_bootstrap_error('DRUSH_DRUPAL_DB_ERROR');
    }
    return TRUE;
  }

  /**
   * Boostrap the Drupal database.
   */
  function bootstrap_drupal_database() {
    // We presume that our derived classes will connect and then
    // either fail, or call us via parent::
    drush_log(dt("Successfully connected to the Drupal database."), 'bootstrap');
  }

  /**
   * Attempt to load the full Drupal system.
   */
  function bootstrap_drupal_full() {
    drush_include_engine('drupal', 'environment');

    $this->add_logger();

    // Write correct install_profile to cache as needed. Used by _drush_find_commandfiles().
    $cid = drush_cid_install_profile();
    $install_profile = $this->get_profile();
    if ($cached_install_profile = drush_cache_get($cid)) {
      // We have a cached profile. Check it for correctness and save new value if needed.
      if ($cached_install_profile->data != $install_profile) {
        drush_cache_set($cid, $install_profile);
      }
    }
    else {
      // No cached entry so write to cache.
      drush_cache_set($cid, $install_profile);
    }

    _drush_log_drupal_messages();
  }

  /**
   * Log into the bootstrapped Drupal site with a specific
   * username or user id.
   */
  function bootstrap_drupal_login() {
    $uid_or_name = drush_set_context('DRUSH_USER', drush_get_option('user', 0));
    $userversion = drush_user_get_class();
    if (!$account = $userversion->load_by_uid($uid_or_name)) {
      if (!$account = $userversion->load_by_name($uid_or_name)) {
        if (is_numeric($uid_or_name)) {
          $message = dt('Could not login with user ID !user.', array('!user' => $uid_or_name));
          if ($uid_or_name === 0) {
            $message .= ' ' . dt('This is typically caused by importing a MySQL database dump from a faulty tool which re-numbered the anonymous user ID in the users table. See !link for help recovering from this situation.', array('!link' => 'http://drupal.org/node/1029506'));
          }
        }
        else {
          $message = dt('Could not login with user account `!user\'.', array('!user' => $uid_or_name));
        }
        return drush_set_error('DRUPAL_USER_LOGIN_FAILED', $message);
      }
    }
    $userversion->setCurrentUser($account);
    _drush_log_drupal_messages();
  }

}
