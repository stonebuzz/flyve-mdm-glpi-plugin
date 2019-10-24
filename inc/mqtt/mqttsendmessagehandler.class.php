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
 * @author    Domingo Oropeza <doropeza@teclib.com>
 * @copyright Copyright © 2018 Teclib
 * @license   http://www.gnu.org/licenses/agpl.txt AGPLv3+
 * @link      https://github.com/flyve-mdm/glpi-plugin
 * @link      https://flyve-mdm.com/
 * ------------------------------------------------------------------------------
 */

namespace GlpiPlugin\Flyvemdm\Mqtt;

use GlpiPlugin\Flyvemdm\Broker\BrokerMessage;
use Toolbox;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class MqttSendMessageHandler {

   private $connection;
   private $mqttEnvelope;

   public function __construct(MqttConnection $connection, MqttEnvelope $mqttEnvelope) {
      $this->connection = $connection;
      $this->mqttEnvelope = $mqttEnvelope;
   }

   public function __invoke(BrokerMessage $message) {
      $mqttEnvelope = $this->mqttEnvelope;
      $qos = ($option = $mqttEnvelope->getContext('qos')) ? $option : 0;
      $retain = ($option = $mqttEnvelope->getContext('retain')) ? $option : 0;
      $topic = $mqttEnvelope->getContext('topic');
      $bodyMessage = $message->getMessage();

      if (null === $bodyMessage && strpos($topic, "defaultStreamType") === false) {
         $chunks = explode('/', $topic);
         switch ($chunks[3]) {
            case 'Policy':
               // null messages aren't sent when reseting policies values, let's fix that
               $bodyMessage = '{"' . $chunks[4] . '":"default","taskId":"' . $chunks[6] . '"}';
               break;
         }
      }
      $this->connection->publish($topic, $bodyMessage, $qos, $retain);
   }
}