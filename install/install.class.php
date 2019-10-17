<?php
/**
 * LICENSE
 *
 * Copyright © 2016-2018 Teclib'
 * Copyright © 2010-2018 by the FusionInventory Development Team.
 *
 * This file is part of Flyve MDM Plugin for GLPI.
 *
 * Flyve MDM Plugin for GLPI is a subproject of Flyve MDM. Flyve MDM is a mobile
 * device management software.
 *
 * Flyve MDM Plugin for GLPI is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * Flyve MDM Plugin for GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License
 * along with Flyve MDM Plugin for GLPI. If not, see http://www.gnu.org/licenses/.
 * ------------------------------------------------------------------------------
 * @copyright Copyright © 2018 Teclib
 * @license   https://www.gnu.org/licenses/agpl.txt AGPLv3+
 * @link      https://github.com/flyve-mdm/glpi-plugin
 * @link      https://flyve-mdm.com/
 * ------------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 *
 * @author tbugier
 * @since 0.1.0
 *
 */
class PluginFlyvemdmInstall {

   const DEFAULT_CIPHERS_LIST = 'ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:DHE-DSS-AES128-GCM-SHA256:kEDH+AESGCM:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES256-SHA384:ECDHE-ECDSA-AES256-SHA384:ECDHE-RSA-AES256-SHA:ECDHE-ECDSA-AES256-SHA:DHE-RSA-AES128-SHA256:DHE-RSA-AES128-SHA:DHE-DSS-AES128-SHA256:DHE-RSA-AES256-SHA256:DHE-DSS-AES256-SHA:DHE-RSA-AES256-SHA:AES128-GCM-SHA256:AES256-GCM-SHA384:ECDHE-RSA-RC4-SHA:ECDHE-ECDSA-RC4-SHA:AES128:AES256:RC4-SHA:HIGH:!aNULL:!eNULL:!EXPORT:!DES:!3DES:!MD5:!PSK';

   const BACKEND_MQTT_USER = 'flyvemdm-backend';

   protected static $currentVersion = null;

   protected $migration;

      /**
    * array of upgrade steps key => value
    * key   is the version to upgrade from
    * value is the version to upgrade to
    *
    * Exemple: an entry '2.0' => '2.1' tells that versions 2.0
    * are upgradable to 2.1
    *
    * When possible avoid schema upgrade between bugfix releases. The schema
    * version contains major.minor numbers only. If an upgrade of the schema
    * occurs between bugfix releases, then the upgrade will start from the
    * major.minor.0 version up to the end of the the versions list.
    * Exemple: if previous version is 2.6.1 and current code is 2.6.3 then
    * the upgrade will start from 2.6.0 to 2.6.3 and replay schema changes
    * between 2.6.0 and 2.6.1. This means that upgrade must be _repeatable_.
    *
    * @var array
    */
   private $upgradeSteps = [
      '0.0'    => '2.0',
      '2.0'    => '2.1',
      //'2.1'    => '3.0',
   ];

   /**
    * Autoloader for installation
    * @param string $classname
    * @return bool
    */
   public static function autoload($classname) {
      // useful only for installer GLPI autoloader already handles inc/ folder
      $filename = dirname(__DIR__) . '/inc/' . strtolower(str_replace('PluginFlyvemdm', '',
            $classname)) . '.class.php';
      if (is_readable($filename) && is_file($filename)) {
         include_once($filename);
         return true;
      }
   }

   /**
    *
    * Install the plugin
    *
    * @return boolean true (assume success, needs enhancement)
    *
    */
   public function install(Migration $migration) {
      $this->migration = $migration;
      spl_autoload_register([__CLASS__, 'autoload']);

      $this->installSchema();
      $this->createInitialConfig();
      $this->migration->executeMigration();
      $this->installUpgradeCommonTasks();

      return true;
   }

   protected function installSchema() {
      global $DB;

      $this->migration->displayMessage("create database schema");

      $dbFile = __DIR__ . '/mysql/plugin_flyvemdm_empty.sql';
      if (!$DB->runFile($dbFile)) {
         $this->migration->displayWarning("Error creating tables : " . $DB->error(), true);
         return false;
      }

      if (version_compare(GLPI_VERSION, '9.3.0') >= 0) {
         $this->migrateToInnodb();
      }
      return true;
   }

   /**
    * Find a profile having the given comment, or create it
    * @param string $name Name of the profile
    * @param string $comment Comment of the profile
    * @return integer profile ID
    */
   protected static function getOrCreateProfile($name, $comment) {
      global $DB;

      $comment = $DB->escape($comment);
      $profile = new Profile();
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $condition = "`comment`='$comment'";
      } else {
         $condition = [
            'comment' => $comment,
         ];
      }
      $profiles = $profile->find($condition);
      $row = array_shift($profiles);
      if ($row === null) {
         $profile->fields["name"] = $DB->escape(__($name, "flyvemdm"));
         $profile->fields["comment"] = $comment;
         $profile->fields["interface"] = "central";
         if ($profile->addToDB() === false) {
            die("Error while creating users profile : $name\n\n" . $DB->error());
         }
         return $profile->getID();
      } else {
         return $row['id'];
      }
   }

   public function createDirectories() {
      // Create directory for uploaded applications
      if (!file_exists(FLYVEMDM_PACKAGE_PATH)) {
         if (!mkdir(FLYVEMDM_PACKAGE_PATH, 0770, true)) {
            $this->migration->displayWarning("Cannot create " . FLYVEMDM_PACKAGE_PATH . " directory");
         } else {
            if (!$htAccessHandler = fopen(FLYVEMDM_PACKAGE_PATH . "/.htaccess", "w")) {
               fwrite($htAccessHandler,
                  "allow from all\n") or $this->migration->displayWarning("Cannot create .htaccess file in packages directory\n");
               fclose($htAccessHandler);
            }
         }
      }

      // Create directory for uploaded files
      if (!file_exists(FLYVEMDM_FILE_PATH)) {
         if (!mkdir(FLYVEMDM_FILE_PATH, 0770, true)) {
            $this->migration->displayWarning("Cannot create " . FLYVEMDM_FILE_PATH . " directory");
         } else {
            if (!$htAccessHandler = fopen(FLYVEMDM_FILE_PATH . "/.htaccess", "w")) {
               fwrite($htAccessHandler,
                  "allow from all\n") or $this->migration->displayWarning("Cannot create .htaccess file in files directory\n");
               fclose($htAccessHandler);
            }
         }
      }

      // Create cache directory for the template engine
      PluginFlyvemdmCommon::recursiveRmdir(FLYVEMDM_TEMPLATE_CACHE_PATH);
      if (!mkdir(FLYVEMDM_TEMPLATE_CACHE_PATH, 0770, true)) {
         $this->migration->displayWarning("Cannot create " . FLYVEMDM_TEMPLATE_CACHE_PATH . " directory");
      }
   }

   /**
    * @return null|string
    */
   public function getSchemaVersion() {
      if ($this->isPluginInstalled()) {
         $config = Config::getConfigurationValues('flyvemdm', ['schema_version']);
         if (!isset($config['schema_version'])) {
            return '0.0';
         }
         return $config['schema_version'];
      }

      return null;
   }

   /**
    * is the plugin already installed ?
    *
    * @return boolean
    */
   public function isPluginInstalled() {
      global $DB;

      // Check tables of the plugin between 1.1 and 2.0 releases
      $result = $DB->query("SHOW TABLES LIKE 'glpi_plugin_flyvemdm\\_%'");
      if ($result) {
         if ($DB->numrows($result) > 0) {
            return true;
         }
      }

      return false;
   }

   protected function createRootEntityConfig() {
      $entityConfig = new PluginFlyvemdmEntityConfig();
      $entityConfig->getFromDBByCrit([
         'entities_id' => '0',
      ]);
      if ($entityConfig->isNewItem()) {
         $entityConfig->add([
            'id'               => '0',
            'entities_id'      => '0',
            'download_url'     => PLUGIN_FLYVEMDM_AGENT_DOWNLOAD_URL,
            'agent_token_life' => PluginFlyvemdmAgent::DEFAULT_TOKEN_LIFETIME,
         ]);
      }
   }

   /**
    * Give all rights on the plugin to the profile of the current user
    */
   protected function createFirstAccess() {
      $profileRight = new ProfileRight();

      $newRights = [
         PluginFlyvemdmAgent::$rightname          => READ | UPDATE | PURGE | READNOTE | UPDATENOTE,
         PluginFlyvemdmFleet::$rightname          => ALLSTANDARDRIGHT | READNOTE | UPDATENOTE,
         PluginFlyvemdmPackage::$rightname        => ALLSTANDARDRIGHT | READNOTE | UPDATENOTE,
         PluginFlyvemdmFile::$rightname           => ALLSTANDARDRIGHT | READNOTE | UPDATENOTE,
         PluginFlyvemdmGeolocation::$rightname    => ALLSTANDARDRIGHT | READNOTE | UPDATENOTE,
         PluginFlyvemdmPolicy::$rightname         => READ,
         PluginFlyvemdmPolicyCategory::$rightname => READ,
         PluginFlyvemdmWellknownpath::$rightname  => ALLSTANDARDRIGHT,
         PluginFlyvemdmProfile::$rightname        => PluginFlyvemdmProfile::RIGHT_FLYVEMDM_USE,
         PluginFlyvemdmEntityConfig::$rightname   => READ
            | PluginFlyvemdmEntityConfig::RIGHT_FLYVEMDM_DEVICE_COUNT_LIMIT
            | PluginFlyvemdmEntityConfig::RIGHT_FLYVEMDM_APP_DOWNLOAD_URL
            | PluginFlyvemdmEntityConfig::RIGHT_FLYVEMDM_INVITATION_TOKEN_LIFE,
         PluginFlyvemdmInvitation::$rightname     => ALLSTANDARDRIGHT,
         PluginFlyvemdmInvitationLog::$rightname  => READ,
         PluginFlyvemdmTaskstatus::$rightname     => READ,
         PluginFlyvemdmMqttlog::$rightname        => READ,
      ];

      $profileRight->updateProfileRights($_SESSION['glpiactiveprofile']['id'], $newRights);

      $_SESSION['glpiactiveprofile'] = $_SESSION['glpiactiveprofile'] + $newRights;
   }

   protected function createDefaultFleet() {
      $fleet = new PluginFlyvemdmFleet();
      $request = [
         'AND' => [
            'is_default' => '1',
            Entity::getForeignKeyField() => '0'
         ]
      ];
      if (!$fleet->getFromDBByCrit($request)) {
         $fleet->add([
            'name'         => __('not managed fleet', 'flyvemdm'),
            'entities_id'  => '0',
            'is_recursive' => '0',
            'is_default'   => '1',
         ]);
      }
   }

   /**
    * Create a profile for guest users
    */
   protected function createGuestProfileAccess() {
      // create profile for guest users
      $profileId = self::getOrCreateProfile(
         __("Flyve MDM guest users", "flyvemdm"),
         __("guest Flyve MDM users. Created by Flyve MDM - do NOT modify this comment.", "flyvemdm")
      );
      Config::setConfigurationValues('flyvemdm', ['guest_profiles_id' => $profileId]);
      $profileRight = new ProfileRight();
      $profileRight->updateProfileRights($profileId, [
         PluginFlyvemdmAgent::$rightname   => READ | CREATE,
         PluginFlyvemdmFile::$rightname    => READ,
         PluginFlyvemdmPackage::$rightname => READ,
      ]);
   }

   /**
    * Create a profile for agent user accounts
    */
   protected function createAgentProfileAccess() {
      // create profile for guest users
      $profileId = self::getOrCreateProfile(
         __("Flyve MDM device agent users", "flyvemdm"),
         __("device agent  Flyve MDM users. Created by Flyve MDM - do NOT modify this comment.",
            "flyvemdm")
      );
      Config::setConfigurationValues('flyvemdm', ['agent_profiles_id' => $profileId]);
      $profileRight = new ProfileRight();
      $profileRight->updateProfileRights($profileId, [
         PluginFlyvemdmAgent::$rightname        => READ | UPDATE | DELETE,
         PluginFlyvemdmFile::$rightname         => READ,
         PluginFlyvemdmPackage::$rightname      => READ,
         PluginFlyvemdmEntityConfig::$rightname => READ,
         PluginFlyvemdmGeolocation::$rightname  => CREATE,
         PluginFlyvemdmTaskstatus::$rightname   => READ | UPDATE,
      ]);
   }

   /**
    * Create policies in DB
    */
   protected function createPolicies() {
      $policy = new PluginFlyvemdmPolicy();
      foreach (self::getPolicies() as $policyData) {
         // Import the policy category or find the existing one
         $category = new PluginFlyvemdmPolicyCategory();
         $categoryId = $category->import([
            'completename' => $policyData['plugin_flyvemdm_policycategories_id'],
         ]);
         $policyData['plugin_flyvemdm_policycategories_id'] = $categoryId;

         $symbol = $policyData['symbol'];
         if (version_compare(GLPI_VERSION, '9.4') < 0) {
            $condition = "`symbol`='$symbol'";
         } else {
            $condition = [
               'symbol' => $symbol,
            ];
         }
         $rows = $policy->find($condition);
         $policyData['type_data'] = json_encode($policyData['type_data'],
            JSON_UNESCAPED_SLASHES
         );
         if (count($rows) == 0) {
            // Create only non existing policy objects
            $policy->add($policyData);
         } else {
            // Update default value and recommended value for existing policy objects
            $policy2 = new PluginFlyvemdmPolicy();
            $policy2->getFromDBBySymbol($symbol);
            $policy2->update([
               'id'                                  => $policy2->getID(),
               'default_value'                       => $policyData['default_value'],
               'recommended_value'                   => $policyData['recommended_value'],
               'type_data'                           => $policyData['type_data'],
               'android_min_version'                 => $policyData['android_min_version'],
               'android_max_version'                 => $policyData['android_max_version'],
               'apple_min_version'                   => $policyData['apple_min_version'],
               'apple_max_version'                   => $policyData['apple_max_version'],
               'plugin_flyvemdm_policycategories_id' => $categoryId,
            ]);
         }
      }
   }

   /**
    * @return array
    */
   protected function getNotificationTargetInvitationEvents() {
      // Force locale for localized strings
      $currentLocale = $_SESSION['glpilanguage'];
      Session::loadLanguage('en_GB');

      $notifications = [
         PluginFlyvemdmNotificationTargetInvitation::EVENT_GUEST_INVITATION => [
            'itemtype'     => PluginFlyvemdmInvitation::class,
            'name'         => __('User invitation', 'flyvemdm'),
            'subject'      => 'You have been invited to join Flyve MDM', 'flyvemdm',
            'content_text' => 'Hi,

##user.firstname## ##user.realname## invited you to enroll your mobile device
in Flyve Mobile Device Managment (Flyve MDM). Flyve MDM allows administrators
to easily manage and administrate mobile devices. For more information,
please contact ##user.firstname## ##user.realname## to his email address
##user.email##.

Please join the Flyve Mobile Device Management system by downloading
and installing the Flyve MDM application for Android from the following link.

##flyvemdm.download_app##

If you\'re viewing this email from a computer flash the QR code you see below
with the Flyve MDM Application.

If you\'re viewing this email from your device to enroll then tap the
following link or copy it to your browser.

##flyvemdm.enroll_url##

Regards,

',
            'content_html' => 'Hi,

##user.firstname## ##user.realname## invited you to enroll your mobile device
in Flyve Mobile Device Managment (Flyve MDM). Flyve MDM allows administrators
to easily manage and administrate mobile devices. For more information,
please contact ##user.firstname## ##user.realname## to his email address
<a href="mailto:##user.email##?subject=Questions about Flyve MDM">
##user.email##</a>.

Please join the Flyve Mobile Device Management system by downloading
and installing the Flyve MDM application for Android from the following link.

<a href="##flyvemdm.download_app##">##flyvemdm.download_app##</a>

If you\'re viewing this email from a computer flash the QR code you see below
with the Flyve MDM Application.

If you\'re viewing this email from your device to enroll then tap the
following link or copy it to your browser.

<a href="##flyvemdm.enroll_url##">##flyvemdm.enroll_url##</a>

<img src="cid:##flyvemdm.qrcode##" alt="Enroll QRCode" title="Enroll QRCode">

Regards,

',
         ],
      ];

      // Restore user's locale
      Session::loadLanguage($currentLocale);

      return $notifications;
   }

   public function createNotificationTargetInvitation() {
      // Create the notification template
      $notification = new Notification();
      $template = new NotificationTemplate();
      $translation = new NotificationTemplateTranslation();
      $notificationTarget = new PluginFlyvemdmNotificationTargetInvitation();
      $notification_notificationTemplate = new Notification_NotificationTemplate();

      foreach ($this->getNotificationTargetInvitationEvents() as $event => $data) {
         $itemtype = $data['itemtype'];
         if (version_compare(GLPI_VERSION, '9.4') < 0) {
            $condition = "`itemtype`='$itemtype' AND `name`='" . Toolbox::addslashes_deep($data['name']) . "'";
         } else {
            $condition = [
               'itemtype' => $itemtype,
               'name' => Toolbox::addslashes_deep($data['name']),
            ];
         }
         if (count($template->find($condition)) < 1) {
            // Add template
            $templateId = $template->add([
               'name'     => addcslashes($data['name'], "'\""),
               'comment'  => '',
               'itemtype' => $itemtype,
            ]);

            // Add default translation
            $contentHtml = !isset($data['content_html']) ? $data['content_text'] : $data['content_html'];
            $translation->add([
               'notificationtemplates_id' => $templateId,
               'language'                 => '',
               'subject'                  => addcslashes($data['subject'], "'\""),
               'content_text'             => addcslashes($data['content_text'], "'\""),
               'content_html'             => addcslashes(htmlentities(self::convertTextToHtml($contentHtml),
                  ENT_NOQUOTES | ENT_HTML401), "'\""),
            ]);

            // Create the notification
            $notificationId = $notification->add([
               'name'         => addcslashes($data['name'], "'\""),
               'comment'      => '',
               'entities_id'  => 0,
               'is_recursive' => 1,
               'is_active'    => 1,
               'itemtype'     => $itemtype,
               'event'        => $event,
            ]);

            $notification_notificationTemplate->add([
               'notifications_id'         => $notificationId,
               'notificationtemplates_id' => $templateId,
               'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
            ]);

            $notificationTarget->add([
               'items_id'         => Notification::USER,
               'type'             => Notification::USER_TYPE,
               'notifications_id' => $notificationId,
            ]);

         }
      }
   }

   /**
    * Upgrade the plugin to the current code version
    *
    * @param string version to upgrade from
    */
   public function upgrade(Migration $migration) {
      spl_autoload_register([__CLASS__, 'autoload']);

      $this->migration = $migration;
      if (isset($_SESSION['plugin_flyvemdm']['cli']) && $_SESSION['plugin_flyvemdm']['cli'] == 'force-upgrade') {
         // Might return false
         $fromSchemaVersion = array_search(PLUGIN_FLYVEMDM_SCHEMA_VERSION, $this->upgradeSteps);
      } else {
         $fromSchemaVersion = $this->getSchemaVersion();
      }

      // Prevent problem of execution time
      ini_set("max_execution_time", "0");
      ini_set("memory_limit", "-1");

      while ($fromSchemaVersion && isset($this->upgradeSteps[$fromSchemaVersion])) {
         $this->upgradeOneStep($this->upgradeSteps[$fromSchemaVersion]);
         $fromSchemaVersion = $this->upgradeSteps[$fromSchemaVersion];
      }

      if (!PLUGIN_FLYVEMDM_IS_OFFICIAL_RELEASE) {
         $this->upgradeOneStep('develop');
      }
      $this->installUpgradeCommonTasks();
      return true;
   }

   private function installUpgradeCommonTasks() {
      $this->createDirectories();
      $this->createFirstAccess();
      $this->createGuestProfileAccess();
      $this->createAgentProfileAccess();
      $this->createDefaultFleet();
      $this->createPolicies();
      $this->createNotificationTargetInvitation();
      $this->createJobs();
      $this->createRootEntityConfig();
      $this->createDisplayPreferences();

      Config::setConfigurationValues(
         'flyvemdm', [
            'version' => PLUGIN_FLYVEMDM_VERSION,
            'schema_version' => PLUGIN_FLYVEMDM_SCHEMA_VERSION,
         ]
      );
   }

   /**
    * Proceed to upgrade of the plugin to the given version
    *
    * @param string $toVersion
    */
   protected function upgradeOneStep($toVersion) {
      ini_set("max_execution_time", "0");
      ini_set("memory_limit", "-1");

      $suffix = str_replace('.', '_', $toVersion);
      $includeFile = __DIR__ . "/upgrade_to_$suffix.php";
      if (is_readable($includeFile) && is_file($includeFile)) {
         include_once $includeFile;
         $updateClass = "PluginFlyvemdmUpgradeTo$suffix";
         $this->migration->addNewMessageArea("Upgrade to $toVersion");
         $upgradeStep = new $updateClass();
         $upgradeStep->upgrade($this->migration);
         $this->migration->executeMigration();
         $this->migration->displayMessage('Done');
      }
   }

   protected function createJobs() {
      CronTask::Register(PluginFlyvemdmPackage::class, 'ParseApplication', MINUTE_TIMESTAMP,
         [
            'comment' => __('Parse uploaded applications to collect metadata', 'flyvemdm'),
            'mode'    => CronTask::MODE_EXTERNAL,
         ]);
   }

   /**
    * Uninstall the plugin
    * @return boolean true (assume success, needs enhancement)
    */
   public function uninstall() {
      $this->rrmdir(GLPI_PLUGIN_DOC_DIR . '/flyvemdm');

      $this->deleteRelations();
      $this->deleteNotificationTargetInvitation();
      $this->deleteProfileRights();
      $this->deleteProfiles();
      $this->deleteDisplayPreferences();
      $this->deleteTables();
      // Cron jobs deletion handled by GLPI

      $config = new Config();
      $config->deleteByCriteria(['context' => 'flyvemdm']);

      return true;
   }

   /**
    * Cannot use the method from PluginFlyvemdmToolbox if the plugin is being uninstalled
    * @param string $dir
    */
   protected function rrmdir($dir) {
      if (file_exists($dir) && is_dir($dir)) {
         $objects = scandir($dir);
         foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
               if (filetype($dir . "/" . $object) == "dir") {
                  $this->rrmdir($dir . "/" . $object);
               } else {
                  unlink($dir . "/" . $object);
               }
            }
         }
         reset($objects);
         rmdir($dir);
      }
   }

   /**
    * Generate default configuration for the plugin
    */
   protected function createInitialConfig() {
      $MdmMqttPassword = PluginFlyvemdmMqttuser::getRandomPassword();

      // New config management provided by GLPi
      $crypto_strong = null;
      $instanceId = base64_encode(openssl_random_pseudo_bytes(64, $crypto_strong));
      $newConfig = [
         'mqtt_broker_address'             => '',
         'mqtt_broker_internal_address'    => '127.0.0.1',
         'mqtt_broker_port'                => '1883',
         'mqtt_broker_tls_port'            => '8883',
         'mqtt_broker_port_backend'        => '1883',
         'mqtt_broker_tls_port_backend'    => '8883',
         'mqtt_tls_for_clients'            => '0',
         'mqtt_tls_for_backend'            => '0',
         'mqtt_use_client_cert'            => '0',
         'mqtt_broker_tls_ciphers'         => self::DEFAULT_CIPHERS_LIST,
         'mqtt_user'                       => self::BACKEND_MQTT_USER,
         'mqtt_passwd'                     => $MdmMqttPassword,
         'instance_id'                     => $instanceId,
         'registered_profiles_id'          => '',
         'guest_profiles_id'               => '',
         'agent_profiles_id'               => '',
         'service_profiles_id'             => '',
         'debug_enrolment'                 => '0',
         'debug_noexpire'                  => '0',
         'debug_save_inventory'            => '0',
         'ssl_cert_url'                    => '',
         'default_device_limit'            => '0',
         'default_agent_url'               => PLUGIN_FLYVEMDM_AGENT_DOWNLOAD_URL,
         'android_bugcollecctor_url'       => '',
         'android_bugcollector_login'      => '',
         'android_bugcollector_passwd'     => '',
         'webapp_url'                      => '',
         'demo_mode'                       => '0',
         'demo_time_limit'                 => '0',
         'inactive_registered_profiles_id' => '',
         'computertypes_id'                => '0',
         'agentusercategories_id'          => '0',
         'invitation_deeplink'             => PLUGIN_FLYVEMDM_DEEPLINK,
         'show_wizard'                     => PluginFlyvemdmConfig::WIZARD_WELCOME_BEGIN,
         'mqtt_enabled'                    => '1',
         'fcm_enabled'                     => '0',
         'fcm_api_token'                   => '',
      ];
      Config::setConfigurationValues('flyvemdm', $newConfig);
      $this->createBackendMqttUser(self::BACKEND_MQTT_USER, $MdmMqttPassword);
   }

   /**
    * Create MQTT user for the backend and save credentials
    * @param string $MdmMqttUser
    * @param string $MdmMqttPassword
    */
   protected function createBackendMqttUser($MdmMqttUser, $MdmMqttPassword) {
      global $DB;

      // Create mqtt credentials for the plugin
      $mqttUser = new PluginFlyvemdmMqttuser();

      // Check the MQTT user account for the plugin exists
      if ($mqttUser->getFromDBByCrit(['user' => $MdmMqttUser])) {
         return;
      }
      // Create the MQTT user account for the plugin
      if (!$mqttUser->add([
         'user'     => $MdmMqttUser,
         'password' => $MdmMqttPassword,
         'enabled'  => '1',
         '_acl'     => [
            [
               'topic'        => '#',
               'access_level' => PluginFlyvemdmMqttacl::MQTTACL_READ_WRITE,
            ],
         ],
      ])) {
         // Failed to create the account
         $this->migration->displayWarning('Unable to create the MQTT account for FlyveMDM : ' . $DB->error());
      } else {
         // Check the ACL has been created
         $aclList = $mqttUser->getACLs();
         $mqttAcl = array_shift($aclList);
         if ($mqttAcl === null) {
            $this->migration->displayWarning('Unable to create the MQTT ACL for FlyveMDM : ' . $DB->error());
         }

         // Save MQTT credentials in configuration
         Config::setConfigurationValues('flyvemdm',
            ['mqtt_user' => $MdmMqttUser, 'mqtt_passwd' => $MdmMqttPassword]);
      }
   }


   /**
    * Generate HTML version of a text
    * Replaces \n by <br>
    * Encloses the text un <p>...</p>
    * Add anchor to URLs
    * @param string $text
    * @return string
    */
   protected static function convertTextToHtml($text) {
      $text = '<p>' . str_replace("\n\n", '</p><p>', $text) . '</p>';
      $text = '<p>' . str_replace("\n", '<br>', $text) . '</p>';
      return $text;
   }

   /**
    * @return array
    */
   static public function getPolicyCategories() {
      // Force locale for localized strings
      $currentLocale = $_SESSION['glpilanguage'];
      Session::loadLanguage('en_GB');

      $categories = [
         [
            'name' => __('Security', 'flyvemdm'),
         ],
         [
            'name' => __('Authentication', 'flyvemdm'),
         ],
         [
            'name' => __('Password', 'flyvemdm'),
         ],
         [
            'name' => __('Encryption', 'flyvemdm'),
         ],
         [
            'name' => __('Peripherals', 'flyvemdm'),
         ],
         [
            'name' => __('Deployment', 'flyvemdm'),
         ],
      ];

      // Restore user's locale
      Session::loadLanguage($currentLocale);

      return $categories;
   }

   /**
    * Gets a reference array of policies installation or update of the plugin
    * the link to the policy category contains the complete name of the category
    * the caller is in charge of importing it and using the ID of the imported
    * category to set the foreign key.
    *
    * @return array policies to add in DB on install
    */
   static public function getPolicies() {
      // Force locale for localized strings
      $currentLocale = $_SESSION['glpilanguage'];
      Session::loadLanguage('en_GB');

      $policies = [];
      foreach (glob(__DIR__ . '/policies/*.php') as $filename) {
         $newPolicies = include $filename;
         $newPolicies = Toolbox::addslashes_deep($newPolicies);
         $policies = array_merge($policies, $newPolicies);
      }

      // Restore user's locale
      Session::loadLanguage($currentLocale);

      return $policies;
   }

   protected function deleteNotificationTargetInvitation() {
      global $DB;

      // Define DB tables
      $tableTargets = NotificationTarget::getTable();
      $tableNotification = Notification::getTable();
      $tableTranslations = NotificationTemplateTranslation::getTable();
      $tableTemplates = NotificationTemplate::getTable();

      foreach ($this->getNotificationTargetInvitationEvents() as $event => $data) {
         $itemtype = $data['itemtype'];
         $name = Toolbox::addslashes_deep($data['name']);
         //TODO : implement cleanup
         // Delete translations
         $query = "DELETE FROM `$tableTranslations`
                   WHERE `notificationtemplates_id` IN (
                   SELECT `id` FROM `$tableTemplates` WHERE `itemtype` = '$itemtype' AND `name`='$name')";
         $DB->query($query);

         // Delete notification templates
         $template = new NotificationTemplate();
         $template->deleteByCriteria(['itemtype' => $itemtype, 'name' => $name]);

         // Delete notification targets
         $query = "DELETE FROM `$tableTargets`
                   WHERE `notifications_id` IN (
                   SELECT `id` FROM `$tableNotification` WHERE `itemtype` = '$itemtype' AND `event`='$event')";
         $DB->query($query);

         // Delete notifications
         $notification = new Notification();
         $notification_notificationTemplate = new Notification_NotificationTemplate();
         if (version_compare(GLPI_VERSION, '9.4') < 0) {
            $condition = "`itemtype` = '$itemtype' AND `event` = '$event'";
         } else {
            $condition = [
               'itemtype' => $itemtype,
               'event' => $event,
            ];
         }
         $rows = $notification->find($condition);
         foreach ($rows as $row) {
            $notification_notificationTemplate->deleteByCriteria(['notifications_id' => $row['id']]);
            $notification->delete($row);
         }
      }
   }

   protected function deleteTables() {
      global $DB;

      $tables = [
         PluginFlyvemdmAgent::getTable(),
         PluginFlyvemdmEntityConfig::getTable(),
         PluginFlyvemdmFile::getTable(),
         PluginFlyvemdmInvitationlog::getTable(),
         PluginFlyvemdmFleet::getTable(),
         PluginFlyvemdmTask::getTable(),
         PluginFlyvemdmGeolocation::getTable(),
         PluginFlyvemdmInvitation::getTable(),
         PluginFlyvemdmMqttacl::getTable(),
         PluginFlyvemdmMqttlog::getTable(),
         PluginFlyvemdmMqttuser::getTable(),
         PluginFlyvemdmPackage::getTable(),
         PluginFlyvemdmPolicy::getTable(),
         PluginFlyvemdmPolicyCategory::getTable(),
         PluginFlyvemdmWellknownpath::getTable(),
         PluginFlyvemdmTaskstatus::getTable(),
      ];

      foreach ($tables as $table) {
         $DB->query("DROP TABLE IF EXISTS `$table`");
      }
   }

   protected function deleteProfiles() {
      $config = Config::getConfigurationValues('flyvemdm', ['guest_profiles_id']);

      foreach ($config as $profileId) {
         $profile = new Profile();
         $profile->getFromDB($profileId);
         if ($profile->deleteFromDB()) {
            $profileUser = new Profile_User();
            $profileUser->deleteByCriteria(['profiles_id' => $profileId], true);
         }
      }
   }

   protected function deleteProfileRights() {
      $rights = [
         PluginFlyvemdmAgent::$rightname,
         PluginFlyvemdmFile::$rightname,
         PluginFlyvemdmFleet::$rightname,
         PluginFlyvemdmGeolocation::$rightname,
         PluginFlyvemdmInvitation::$rightname,
         PluginFlyvemdmInvitationlog::$rightname,
         PluginFlyvemdmPackage::$rightname,
         PluginFlyvemdmPolicy::$rightname,
         PluginFlyvemdmProfile::$rightname,
         PluginFlyvemdmTaskstatus::$rightname,
         PluginFlyvemdmWellknownpath::$rightname,
         PluginFlyvemdmMqttlog::$rightname,
      ];
      foreach ($rights as $right) {
         ProfileRight::deleteProfileRights([$right]);
         unset($_SESSION["glpiactiveprofile"][$right]);
      }
   }

   protected function deleteRelations() {
      $pluginItemtypes = [
         PluginFlyvemdmAgent::class,
         PluginFlyvemdmEntityConfig::class,
         PluginFlyvemdmFile::class,
         PluginFlyvemdmFleet::class,
         PluginFlyvemdmGeolocation::class,
         PluginFlyvemdmInvitation::class,
         PluginFlyvemdmPackage::class,
         PluginFlyvemdmPolicy::class,
         PluginFlyvemdmPolicyCategory::class,
         PluginFlyvemdmWellknownpath::class,
      ];

      // Itemtypes from the core having relations to itemtypes of the plugin
      $itemtypes = [
         Notepad::class,
         DisplayPreference::class,
         DropdownTranslation::class,
         Log::class,
         Bookmark::class,
         SavedSearch::class,
      ];
      foreach ($pluginItemtypes as $pluginItemtype) {
         foreach ($itemtypes as $itemtype) {
            if (class_exists($itemtype)) {
               $item = new $itemtype();
               $item->deleteByCriteria(['itemtype' => $pluginItemtype]);
            }
         }
      }
   }

   protected function createDisplayPreferences() {
      $displayPreference = new DisplayPreference();
      $itemtype = PluginFlyvemdmFile::class;
      $rank = 1;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '1' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '1',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '1',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }
      $rank++;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '4' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '4',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '4',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }

      $itemtype = PluginFlyvemdmInvitation::class;
      $rank = 1;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '3' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '3',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '3',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }
      $rank++;
      $criteria = "`itemtype` = '$itemtype' AND `num` = '4' AND `users_id` = '0'";
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '4' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '4',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '4',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }
      $rank++;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '5' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '5',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '5',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }

      $itemtype = PluginFlyvemdmPackage::class;
      $rank = 1;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '3' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '3',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '3',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }
      $rank++;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '4' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '4',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '4',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }
      $rank++;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '5' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '5',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '5',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }

      $itemtype = PluginFlyvemdmAgent::class;
      $rank = 1;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '11' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '11',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '11',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }
      $rank++;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '12' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '12',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '12',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }
      $rank++;
      if (version_compare(GLPI_VERSION, '9.4') < 0) {
         $criteria = "`itemtype` = '$itemtype' AND `num` = '3' AND `users_id` = '0'";
      } else {
         $criteria = [
            'itemtype' => $itemtype,
            'num' => '3',
            'users_id' => '0',
         ];
      }
      if (count($displayPreference->find($criteria)) == 0) {
         $displayPreference->add([
            'itemtype'                 => $itemtype,
            'num'                      => '3',
            'rank'                     => $rank,
            User::getForeignKeyField() => '0'
         ]);
      }

   }

   protected function deleteDisplayPreferences() {
      global $DB;

      $table = DisplayPreference::getTable();
      $DB->query("DELETE FROM `$table` WHERE `itemtype` LIKE 'PluginFlyvemdm%'");
   }

   /**
    * Works only for GLPI 9.3 and upper
    */
   protected function migrateToInnodb() {
      global $DB;

      $result = $DB->listTables('glpi_plugin_flyvemdm_%', ['engine' => 'MyIsam']);
      if ($result) {
         while ($table = $result->next()) {
            echo "Migrating {$table['TABLE_NAME']}...";
            $DB->queryOrDie("ALTER TABLE {$table['TABLE_NAME']} ENGINE = InnoDB");
            echo " Done.\n";
         }
      }
   }
}
